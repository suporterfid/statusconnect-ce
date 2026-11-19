<?php

namespace App\Application\Maintenance\Services;

use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\MaintenanceWindow;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class MaintenanceWindowService
{
    /**
     * @return Collection<int, MaintenanceWindow>
     */
    public function list(Tenant $tenant, Environment $environment): Collection
    {
        return MaintenanceWindow::query()
            ->where('tenant_id', $tenant->id)
            ->where('environment_id', $environment->id)
            ->with('monitors')
            ->orderBy('starts_at', 'desc')
            ->get();
    }

    public function get(Tenant $tenant, Environment $environment, string $publicId): MaintenanceWindow
    {
        return MaintenanceWindow::query()
            ->where('tenant_id', $tenant->id)
            ->where('environment_id', $environment->id)
            ->where('public_id', $publicId)
            ->with('monitors')
            ->firstOrFail();
    }

    /**
     * Validation rules for a new window, shared by the API and the MCP tool (#185).
     *
     * @return array<string, string>
     */
    public static function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after:starts_at',
            'suppress_notifications' => 'boolean',
            'recurring_weekly' => 'boolean',
            'monitor_ids' => 'nullable|array',
            'monitor_ids.*' => 'string',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Tenant $tenant, Environment $environment, array $data): MaintenanceWindow
    {
        return DB::transaction(function () use ($tenant, $environment, $data) {
            /** @var MaintenanceWindow $window */
            $window = MaintenanceWindow::create([
                'tenant_id' => $tenant->id,
                'environment_id' => $environment->id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'suppress_notifications' => $data['suppress_notifications'] ?? true,
                'recurring_weekly' => $data['recurring_weekly'] ?? false,
            ]);

            if (isset($data['monitor_ids']) && is_array($data['monitor_ids'])) {
                $monitorIds = Monitor::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('environment_id', $environment->id)
                    ->whereIn('public_id', $data['monitor_ids'])
                    ->pluck('id')
                    ->all();

                $window->monitors()->sync($monitorIds);
            }

            return $window->fresh('monitors');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Tenant $tenant, Environment $environment, string $publicId, array $data): MaintenanceWindow
    {
        $window = $this->get($tenant, $environment, $publicId);

        return DB::transaction(function () use ($tenant, $environment, $window, $data) {
            $window->update(array_filter([
                'name' => $data['name'] ?? null,
                'description' => $data['description'] ?? null,
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'suppress_notifications' => $data['suppress_notifications'] ?? null,
                'recurring_weekly' => $data['recurring_weekly'] ?? null,
            ], fn ($v) => $v !== null));

            if (isset($data['monitor_ids']) && is_array($data['monitor_ids'])) {
                $monitorIds = Monitor::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('environment_id', $environment->id)
                    ->whereIn('public_id', $data['monitor_ids'])
                    ->pluck('id')
                    ->all();

                $window->monitors()->sync($monitorIds);
            }

            return $window->fresh('monitors');
        });
    }

    public function delete(Tenant $tenant, Environment $environment, string $publicId): void
    {
        $window = $this->get($tenant, $environment, $publicId);
        $window->delete();
    }
}
