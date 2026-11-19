<?php

namespace App\Application\Plans;

use App\Application\Tenancy\AuditRecorder;
use App\Domain\Monitoring\MonitorKind;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Moves an organization to another plan (#119).
 *
 * On a downgrade nothing is deleted. Monitors above the new limits are paused
 * (newest first, agent monitors counted against their own limit too),
 * intervals below the new minimum are raised, and custom domains are removed
 * when the plan has none.
 */
final class PlanChangeService
{
    public function __construct(
        private readonly PlanCatalog $catalog,
        private readonly AuditRecorder $audit,
        private readonly \App\Application\Monitoring\MonitorPauseService $pauses,
    ) {
    }

    /**
     * @return array{paused: int, intervals_raised: int, custom_domains_removed: int}
     */
    public function change(Tenant $tenant, string $plan, ?User $actor, string $reason): array
    {
        if (! $this->catalog->exists($plan)) {
            throw new InvalidArgumentException("Unknown plan [{$plan}].");
        }

        $previous = $this->catalog->planOf($tenant);

        $effects = DB::transaction(function () use ($tenant, $plan): array {
            $tenant->forceFill(['plan' => $plan])->save();

            return $this->applyLimits($tenant);
        });

        if ($previous !== $plan || array_sum($effects) > 0) {
            $this->audit->record($actor, 'tenant.plan_changed', 'tenant', $tenant->public_id, $tenant, [
                'from' => $previous, 'to' => $plan, 'reason' => $reason,
            ] + $effects);
        }

        return $effects;
    }

    /**
     * @return array{paused: int, intervals_raised: int, custom_domains_removed: int}
     */
    public function applyLimits(Tenant $tenant): array
    {
        $plan = $this->catalog->forTenant($tenant);
        $paused = 0;

        if ($plan['agent_monitors'] !== null) {
            $paused += $this->pauseAbove($tenant, $plan['agent_monitors'], MonitorKind::AGENT->value);
        }
        if ($plan['monitors'] !== null) {
            $paused += $this->pauseAbove($tenant, $plan['monitors'], null);
        }

        $intervals = Monitor::query()
            ->where('tenant_id', $tenant->id)
            ->where('interval_seconds', '<', $plan['min_interval_seconds'])
            ->update(['interval_seconds' => $plan['min_interval_seconds']]);

        $domains = 0;
        if (! $plan['custom_domain']) {
            $domains = DB::table('status_pages')
                ->where('tenant_id', $tenant->id)
                ->whereNotNull('custom_domain')
                ->update(['custom_domain' => null, 'updated_at' => now()]);
        }

        return ['paused' => $paused, 'intervals_raised' => $intervals, 'custom_domains_removed' => $domains];
    }

    private function pauseAbove(Tenant $tenant, int $limit, ?string $kind): int
    {
        $ids = Monitor::query()
            ->where('tenant_id', $tenant->id)
            ->where('enabled', true)
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
            ->orderBy('id')
            ->pluck('id')
            ->slice($limit)
            ->all();

        if ($ids === []) {
            return 0;
        }

        // Each one through the pause transition (#246): state, streak, open incident, audit.
        return $this->pauses->pauseMany(
            Monitor::query()->whereIn('id', $ids)->orderBy('id')->get(),
            null,
            ['source' => 'plan_downgrade', 'plan' => $tenant->plan],
        );
    }
}
