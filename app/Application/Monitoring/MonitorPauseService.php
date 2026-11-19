<?php

namespace App\Application\Monitoring;

use App\Application\Incidents\IncidentService;
use App\Application\StatusPages\StatusPageCacheWriter;
use App\Application\Tenancy\AuditRecorder;
use App\Domain\Monitoring\CheckState;
use App\Domain\Shared\Clock;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Support\Facades\DB;

/**
 * Pausing and resuming a monitor (#246): the one place that flips `enabled`
 * after creation. Every path goes through here: the REST API and operator
 * app (MonitorCommands), the MCP tools and the plan downgrade.
 *
 * Pausing sets the paused state, clears the failure streak and any claim
 * (a check in flight is then dropped), and closes the open automatic
 * incident; manual incidents stay open. Resuming starts the monitor fresh
 * as pending, due at once. Both are idempotent, audited, and refresh the
 * status page cache. Plan limits are the caller's job (MonitorCommands).
 */
final class MonitorPauseService
{
    public function __construct(
        private readonly IncidentService $incidents,
        private readonly StatusPageCacheWriter $statusPages,
        private readonly AuditRecorder $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param  array<string, mixed>  $context  extra audit summary (for example the source)
     * @return bool whether anything changed
     */
    public function pause(Monitor $monitor, ?User $actor, array $context = []): bool
    {
        $changed = $this->pauseOne($monitor, $actor, $context);
        if ($changed) {
            $this->statusPages->refreshStalePages();
        }

        return $changed;
    }

    /**
     * Pauses several monitors, then refreshes the status page cache once.
     *
     * @param  iterable<Monitor>  $monitors
     * @param  array<string, mixed>  $context
     * @return int how many changed
     */
    public function pauseMany(iterable $monitors, ?User $actor, array $context = []): int
    {
        $changed = 0;
        foreach ($monitors as $monitor) {
            $changed += $this->pauseOne($monitor, $actor, $context) ? 1 : 0;
        }
        if ($changed > 0) {
            $this->statusPages->refreshStalePages();
        }

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return bool whether anything changed
     */
    public function resume(Monitor $monitor, ?User $actor, array $context = []): bool
    {
        $changed = DB::transaction(function () use ($monitor, $actor, $context): bool {
            $locked = Monitor::query()->whereKey($monitor->id)->lockForUpdate()->firstOrFail();
            // Running already. A disabled monitor without paused_at (paused
            // before #246) is resumed the normal way.
            if ($locked->enabled && $locked->paused_at === null) {
                return false;
            }

            $now = $this->clock->nowUtc();
            $locked->forceFill([
                'enabled' => true,
                'paused_at' => null,
                'current_state' => CheckState::PENDING,
                'next_check_at' => $now,
            ] + self::freshCounters())->save();

            $this->audit->record($actor, 'monitor.resumed', 'monitor', $locked->public_id, $locked->tenant, $context);

            return true;
        });

        if ($changed) {
            $this->statusPages->refreshStalePages();
        }

        return $changed;
    }

    /** @param  array<string, mixed>  $context */
    private function pauseOne(Monitor $monitor, ?User $actor, array $context): bool
    {
        return DB::transaction(function () use ($monitor, $actor, $context): bool {
            $locked = Monitor::query()->whereKey($monitor->id)->lockForUpdate()->firstOrFail();
            // Paused already. A disabled monitor without paused_at (paused
            // before #246) gets the full transition, which repairs its state.
            if (! $locked->enabled && $locked->paused_at !== null) {
                return false;
            }

            $now = $this->clock->nowUtc();
            $locked->forceFill([
                'enabled' => false,
                'paused_at' => $now,
                'current_state' => CheckState::PAUSED,
            ] + self::freshCounters())->save();

            $incident = $this->incidents->resolveForPausedMonitor($locked, $now);

            $this->audit->record($actor, 'monitor.paused', 'monitor', $locked->public_id, $locked->tenant, $context + [
                'resolved_incident' => $incident?->public_id,
            ]);

            return true;
        });
    }

    /** @return array<string, mixed> */
    private static function freshCounters(): array
    {
        return [
            'consecutive_failures' => 0,
            'consecutive_successes' => 0,
            'first_failure_at' => null,
            'second_location_up_until' => null,
            'flapping_since' => null,
            'flap_notification_window_started_at' => null,
            'claim_token' => null,
            'claimed_at' => null,
            'claim_expires_at' => null,
        ];
    }
}
