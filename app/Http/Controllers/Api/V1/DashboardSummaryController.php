<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Maintenance\Services\MaintenanceWindowEvaluator;
use App\Domain\Shared\Clock;
use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\CheckResult;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Incident;
use App\Infrastructure\Persistence\Eloquent\MaintenanceWindow;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardSummaryController extends Controller
{
    public function __construct(
        private readonly MaintenanceWindowEvaluator $maintenanceEvaluator,
        private readonly Clock $clock,
    ) {
    }

    public function __invoke(Request $request, string $tenantId, string $environmentId): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        /** @var Environment $environment */
        $environment = $request->attributes->get('environment');

        $totalMonitors = Monitor::query()
            ->where('tenant_id', $tenant->id)
            ->where('environment_id', $environment->id)
            ->count();

        $openIncidents = Incident::query()
            ->where('tenant_id', $tenant->id)
            ->where('environment_id', $environment->id)
            ->whereNull('resolved_at')
            ->count();

        $now = $this->clock->nowUtc();
        $activeMaintenanceWindows = MaintenanceWindow::query()
            ->where('environment_id', $environment->id)
            ->get()
            ->filter(fn (MaintenanceWindow $window) => $this->maintenanceEvaluator->isWindowActive($window, $now))
            ->count();

        $uptime24h = $this->uptimeOverLastHours($tenant, $environment, 24);

        return response()->json([
            'data' => [
                'total_monitors' => $totalMonitors,
                'open_incidents' => $openIncidents,
                'active_maintenance_windows' => $activeMaintenanceWindows,
                'uptime_24h_percent' => $uptime24h,
            ],
        ]);
    }

    private function uptimeOverLastHours(Tenant $tenant, Environment $environment, int $hours): ?float
    {
        $counts = CheckResult::query()
            ->where('tenant_id', $tenant->id)
            ->where('environment_id', $environment->id)
            ->where('checked_at', '>=', now()->subHours($hours))
            ->whereNull('voided_at')
            ->selectRaw('state, failure_confirmed, count(*) as total')
            ->groupBy('state', 'failure_confirmed')
            ->get()
            // #237: an unconfirmed failure counts as up.
            ->reduce(function (array $carry, $row): array {
                $state = \App\Application\Rollups\AvailabilityState::of($row->state instanceof \BackedEnum ? $row->state->value : (string) $row->state, $row->getRawOriginal('failure_confirmed'));
                $carry[$state] = ($carry[$state] ?? 0) + (int) $row->total;

                return $carry;
            }, []);
        $counts = collect($counts);

        $total = $counts->sum();
        if ($total === 0) {
            return null;
        }

        $healthy = ($counts['up'] ?? 0) + ($counts['degraded'] ?? 0);

        return round(($healthy / $total) * 100, 2);
    }
}
