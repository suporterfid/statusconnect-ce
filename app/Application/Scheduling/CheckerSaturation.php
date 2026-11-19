<?php

namespace App\Application\Scheduling;

use App\Domain\Shared\Clock;
use App\Infrastructure\Persistence\Eloquent\SystemHeartbeat;
use App\Infrastructure\Persistence\Eloquent\User;
use App\Mail\AccountMail;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Notices when the checker runs out of room (#214).
 *
 * A tick is saturated when it leaves due monitors for the next tick, or when
 * it runs longer than scheduler.saturation_duration_seconds. After
 * scheduler.saturation_ticks saturated ticks in a row the platform admins are
 * emailed (at most once per scheduler.saturation_alert_hours) and
 * GET /v1/platform/health?checker=1 answers 503, so customers' checks running
 * late is seen before customers see it.
 */
final class CheckerSaturation
{
    public const HEARTBEAT = 'checker_saturation';

    public function __construct(
        private readonly Clock $clock,
        private readonly HeartbeatWriter $heartbeats,
    ) {
    }

    public static function durationThresholdSeconds(): int
    {
        return max(1, (int) config('scheduler.saturation_duration_seconds', 30));
    }

    public static function ticksThreshold(): int
    {
        return max(1, (int) config('scheduler.saturation_ticks', 3));
    }

    /**
     * @param  array{deferred:int, duration_ms:int}  $stats  Stats of the tick that just ended.
     */
    public static function isSaturatedTick(array $stats): bool
    {
        return (int) ($stats['deferred'] ?? 0) > 0
            || (int) ($stats['duration_ms'] ?? 0) >= self::durationThresholdSeconds() * 1000;
    }

    /**
     * @param  array{claimed:int, executed:int, deferred:int, budget_stopped:bool, duration_ms:int}  $stats
     */
    public function afterTick(array $stats): void
    {
        $now = $this->clock->nowUtc();
        $state = self::state();
        $wasSaturated = $state['consecutive_ticks'] >= self::ticksThreshold();

        if (! self::isSaturatedTick($stats)) {
            if ($wasSaturated) {
                Log::info('The checker is no longer saturated.', ['since' => $state['since']]);
            }
            $this->heartbeats->record(self::HEARTBEAT, [
                'consecutive_ticks' => 0,
                'since' => null,
                'last_alert_at' => $state['last_alert_at'],
            ]);

            return;
        }

        $consecutive = $state['consecutive_ticks'] + 1;
        $since = $state['since'] ?? $now->format(DATE_ATOM);
        $lastAlertAt = $state['last_alert_at'];

        if ($consecutive >= self::ticksThreshold() && $this->cooledDown($lastAlertAt, $now)) {
            $lastAlertAt = $now->format(DATE_ATOM);
            $this->alert($stats, $consecutive, new DateTimeImmutable($since));
        }

        $this->heartbeats->record(self::HEARTBEAT, [
            'consecutive_ticks' => $consecutive,
            'since' => $since,
            'last_alert_at' => $lastAlertAt,
        ]);
    }

    /**
     * @return array{consecutive_ticks:int, since:?string, last_alert_at:?string}
     */
    public static function state(): array
    {
        $meta = SystemHeartbeat::query()->where('name', self::HEARTBEAT)->value('meta_json');
        $meta = is_string($meta) ? (array) json_decode($meta, true) : (array) $meta;

        return [
            'consecutive_ticks' => (int) ($meta['consecutive_ticks'] ?? 0),
            'since' => is_string($meta['since'] ?? null) ? $meta['since'] : null,
            'last_alert_at' => is_string($meta['last_alert_at'] ?? null) ? $meta['last_alert_at'] : null,
        ];
    }

    /**
     * @return array{saturated:bool, consecutive_ticks:int, since:?string}
     */
    public static function status(): array
    {
        $state = self::state();

        return [
            'saturated' => $state['consecutive_ticks'] >= self::ticksThreshold(),
            'consecutive_ticks' => $state['consecutive_ticks'],
            'since' => $state['since'],
        ];
    }

    private function cooledDown(?string $lastAlertAt, DateTimeImmutable $now): bool
    {
        if ($lastAlertAt === null) {
            return true;
        }
        $hours = max(1, (int) config('scheduler.saturation_alert_hours', 6));

        return $now->getTimestamp() - (new DateTimeImmutable($lastAlertAt))->getTimestamp() >= $hours * 3600;
    }

    /**
     * @param  array{deferred:int, duration_ms:int}  $stats
     */
    private function alert(array $stats, int $ticks, DateTimeImmutable $since): void
    {
        $load = app(CheckerLoad::class)->summary();
        Log::warning('The checker is saturated: due monitors are checked late.', [
            'ticks' => $ticks, 'deferred' => $stats['deferred'], 'duration_ms' => $stats['duration_ms'],
        ] + $load);

        $data = [
            'ticks' => $ticks,
            'since' => $since->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i').' UTC',
            'deferred' => (int) $stats['deferred'],
            'seconds' => (int) round(((int) $stats['duration_ms']) / 1000),
            'demand' => (int) round($load['demand_per_minute']),
            'capacity' => $load['capacity_per_minute'],
        ];

        foreach (User::query()->where('is_platform_admin', true)->get() as $admin) {
            try {
                Mail::to($admin->email)->send(new AccountMail(
                    'admin_checker_saturated',
                    __('messages.mail_checker_saturated_subject', ['ticks' => $ticks]),
                    $data,
                ));
            } catch (Throwable $e) {
                Log::error('Could not email the checker saturation alert.', ['error' => $e->getMessage()]);
            }
        }
    }
}
