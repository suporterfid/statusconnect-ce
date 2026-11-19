<?php

namespace App\Application\Mcp\Tools;

use App\Application\Maintenance\Services\MaintenanceWindowService;
use App\Application\Mcp\McpContext;
use App\Http\Middleware\EnforceTenantPermission;
use App\Http\Support\ApiDate;
use App\Infrastructure\Persistence\Eloquent\MaintenanceWindow;

/**
 * create_maintenance_window (#185). Same rules and service as
 * POST …/maintenance-windows; `title` is the window's name.
 */
final class CreateMaintenanceWindowTool implements McpTool
{
    public function __construct(private readonly MaintenanceWindowService $windows)
    {
    }

    public function name(): string
    {
        return 'create_maintenance_window';
    }

    public function description(): string
    {
        return 'Schedule a maintenance window: notifications are suppressed while it runs. Without monitor_ids it covers no monitor; '
            .'pass the monitor_id values from list_monitors. Times are ISO 8601; ends_at must be after starts_at.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'maxLength' => 255],
                'starts_at' => ['type' => 'string', 'format' => 'date-time'],
                'ends_at' => ['type' => 'string', 'format' => 'date-time'],
                'monitor_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['title', 'starts_at', 'ends_at'],
            'additionalProperties' => false,
        ];
    }

    public function permissions(): array
    {
        return [EnforceTenantPermission::WRITE];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false];
    }

    public function call(array $arguments, McpContext $context): array
    {
        $input = [
            'name' => $arguments['title'] ?? null,
            'starts_at' => $arguments['starts_at'] ?? null,
            'ends_at' => $arguments['ends_at'] ?? null,
        ];
        if (array_key_exists('monitor_ids', $arguments)) {
            $input['monitor_ids'] = $arguments['monitor_ids'];
        }

        $validated = Arguments::validate($input, MaintenanceWindowService::rules(), ['name' => 'title']);
        $window = $this->windows->create($context->tenant, $context->environment, $validated);

        return ['maintenance_window' => self::present($window)];
    }

    /** @return array<string, mixed> */
    private static function present(MaintenanceWindow $window): array
    {
        return [
            'maintenance_window_id' => $window->public_id,
            'title' => $window->name,
            'starts_at' => ApiDate::format($window->starts_at),
            'ends_at' => ApiDate::format($window->ends_at),
            'suppress_notifications' => (bool) $window->suppress_notifications,
            'monitor_ids' => $window->monitors->pluck('public_id')->values()->all(),
        ];
    }
}
