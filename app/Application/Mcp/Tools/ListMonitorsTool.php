<?php

namespace App\Application\Mcp\Tools;

use App\Application\Mcp\McpContext;
use App\Domain\Monitoring\CheckState;
use App\Http\Middleware\EnforceTenantPermission;
use App\Infrastructure\Persistence\Eloquent\Monitor;

final class ListMonitorsTool implements McpTool
{
    public function name(): string
    {
        return 'list_monitors';
    }

    public function description(): string
    {
        return 'List the monitors of this environment with their current state (up, down, degraded...). Use state="down" to see what is failing now.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'state' => ['type' => 'string', 'enum' => self::states(), 'description' => 'Only monitors in this state.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
            ],
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
        $args = Arguments::validate($arguments, [
            'state' => ['sometimes', 'string', 'in:'.implode(',', self::states())],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Monitor::query()
            ->where('tenant_id', $context->tenant->id)
            ->where('environment_id', $context->environment->id)
            ->orderBy('name')
            ->orderBy('id');
        if (isset($args['state'])) {
            $query->where('current_state', $args['state']);
        }

        $total = (clone $query)->count();
        $monitors = $query->limit($args['limit'] ?? 50)->get();

        return [
            'monitors' => $monitors->map(fn (Monitor $m): array => MonitorPresenter::present($m))->values()->all(),
            'total' => $total,
        ];
    }

    /** @return list<string> */
    private static function states(): array
    {
        return array_map(static fn (CheckState $s): string => $s->value, CheckState::cases());
    }
}
