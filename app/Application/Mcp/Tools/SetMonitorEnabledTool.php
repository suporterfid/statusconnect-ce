<?php

namespace App\Application\Mcp\Tools;

use App\Application\Mcp\McpContext;
use App\Application\Mcp\McpToolException;
use App\Application\Monitoring\MonitorCommands;
use App\Http\Middleware\EnforceTenantPermission;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * pause_monitor and resume_monitor (#185). Both go through MonitorCommands,
 * so resuming counts against the plan exactly as PATCH …/monitors does.
 */
abstract class SetMonitorEnabledTool implements McpTool
{
    public function __construct(private readonly MonitorCommands $commands)
    {
    }

    abstract protected function enabled(): bool;

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
        return [EnforceTenantPermission::OPERATE, EnforceTenantPermission::WRITE];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
    }

    public function call(array $arguments, McpContext $context): array
    {
        $args = Arguments::validate($arguments, ['monitor_id' => ['required', 'string', 'max:64']]);

        try {
            $monitor = $this->commands->setEnabled(
                $context->tenant, $context->environment, $args['monitor_id'], $this->enabled(),
                null, ['source' => 'mcp', 'api_key' => $context->apiKey->public_id],
            );
        } catch (ModelNotFoundException) {
            throw new McpToolException('Monitor not found: '.$args['monitor_id']);
        }

        return ['monitor' => MonitorPresenter::present($monitor)];
    }
}
