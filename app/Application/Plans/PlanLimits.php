<?php

namespace App\Application\Plans;

use App\Domain\Monitoring\MonitorKind;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Enforces the plan limits of an organization (#119).
 *
 * Monitor limits count enabled monitors: a paused monitor costs the checker
 * nothing, and a downgrade pauses the monitors above the new limit instead of
 * deleting them.
 */
final class PlanLimits
{
    public function __construct(private readonly PlanCatalog $catalog)
    {
    }

    /**
     * Throws when enabling one more monitor of this kind would exceed the plan.
     */
    public function assertCanEnableMonitor(Tenant $tenant, string $kind, ?int $exceptMonitorId = null): void
    {
        $plan = $this->catalog->forTenant($tenant);

        $limit = $plan['monitors'];
        if ($limit !== null && $this->enabledMonitors($tenant, null, $exceptMonitorId) >= $limit) {
            throw ValidationException::withMessages(['plan' => [__('messages.plan_monitor_limit', ['limit' => $limit, 'plan' => $plan['name']])]]);
        }

        if ($kind === MonitorKind::AGENT->value) {
            $agentLimit = $plan['agent_monitors'];
            if ($agentLimit !== null && $this->enabledMonitors($tenant, MonitorKind::AGENT->value, $exceptMonitorId) >= $agentLimit) {
                throw ValidationException::withMessages(['plan' => [__('messages.plan_agent_limit', ['limit' => $agentLimit, 'plan' => $plan['name']])]]);
            }
        }
    }

    public function assertInterval(Tenant $tenant, int $intervalSeconds): void
    {
        $plan = $this->catalog->forTenant($tenant);
        if ($intervalSeconds < $plan['min_interval_seconds']) {
            throw ValidationException::withMessages(['interval_seconds' => [__('messages.plan_interval_floor', [
                'seconds' => $plan['min_interval_seconds'],
                'plan' => $plan['name'],
            ])]]);
        }
    }

    public function assertCustomDomain(Tenant $tenant): void
    {
        $plan = $this->catalog->forTenant($tenant);
        if (! $plan['custom_domain']) {
            throw ValidationException::withMessages(['custom_domain' => [__('messages.plan_custom_domain', ['plan' => $plan['name']])]]);
        }
    }

    /** Choosing a probe region other than the default (#222). */
    public function assertCheckRegions(Tenant $tenant): void
    {
        $plan = $this->catalog->forTenant($tenant);
        if (! ($plan['check_regions'] ?? false)) {
            throw ValidationException::withMessages(['check_region' => [__('messages.plan_check_regions', ['plan' => $plan['name']])]]);
        }
    }

    /** Smallest interval the plan allows, used when a request leaves the interval out. */
    public function minInterval(Tenant $tenant): int
    {
        return (int) $this->catalog->forTenant($tenant)['min_interval_seconds'];
    }

    /**
     * @return array{plan: array<string, mixed>, usage: array{monitors: int, agent_monitors: int, status_pages: int}}
     */
    public function usage(Tenant $tenant): array
    {
        return [
            'plan' => $this->catalog->present($this->catalog->planOf($tenant)),
            'usage' => [
                'monitors' => $this->enabledMonitors($tenant),
                'agent_monitors' => $this->enabledMonitors($tenant, MonitorKind::AGENT->value),
                'status_pages' => DB::table('status_pages')->where('tenant_id', $tenant->id)->count(),
            ],
        ];
    }

    private function enabledMonitors(Tenant $tenant, ?string $kind = null, ?int $exceptMonitorId = null): int
    {
        return Monitor::query()
            ->where('tenant_id', $tenant->id)
            ->where('enabled', true)
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
            ->when($exceptMonitorId !== null, fn ($q) => $q->where('id', '!=', $exceptMonitorId))
            ->count();
    }
}
