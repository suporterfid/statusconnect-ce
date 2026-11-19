<?php

namespace App\Application\Mcp\Tools;

use App\Application\Mcp\McpContext;
use App\Application\Mcp\McpToolException;
use App\Http\Middleware\EnforceTenantPermission;
use App\Http\Support\ApiDate;
use App\Infrastructure\Persistence\Eloquent\CheckResult;
use App\Infrastructure\Persistence\Eloquent\Monitor;

final class GetMonitorTool implements McpTool
{
    private const RECENT_CHECKS = 20;

    public function name(): string
    {
        return 'get_monitor';
    }

    public function description(): string
    {
        return 'Get one monitor and its last 20 check results (state, latency, HTTP status, failure reason). Take monitor_id from list_monitors.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'monitor_id' => ['type' => 'string', 'description' => 'The monitor_id returned by list_monitors.'],
            ],
            'required' => ['monitor_id'],
            'additionalProperties' => false,
        ];
    }

    public function permissions(): array
    {
        return [EnforceTenantPermission::READ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => true, 'openWorldHint' => false];
    }

    public function call(array $arguments, McpContext $context): array
    {
        $args = Arguments::validate($arguments, ['monitor_id' => ['required', 'string', 'max:64']]);

        // Scoped to the key's tenant and environment: an id from anywhere else
        // reads exactly like an id that does not exist.
        $monitor = Monitor::query()
            ->where('tenant_id', $context->tenant->id)
            ->where('environment_id', $context->environment->id)
            ->where('public_id', $args['monitor_id'])
            ->first();
        if ($monitor === null) {
            throw new McpToolException('Monitor not found: '.$args['monitor_id']);
        }

        $checks = CheckResult::query()
            ->where('monitor_id', $monitor->id)
            ->orderByDesc('checked_at')
            ->limit(self::RECENT_CHECKS)
            ->get()
            ->map(fn (CheckResult $r): array => [
                'checked_at' => ApiDate::format($r->checked_at),
                'state' => $r->state instanceof \BackedEnum ? $r->state->value : $r->state,
                'latency_ms' => $r->latency_ms,
                'status_code' => $r->status_code,
                'failure_reason' => $r->failure_reason,
            ])
            ->values()
            ->all();

        return ['monitor' => MonitorPresenter::present($monitor), 'recent_checks' => $checks];
    }
}
