<?php

namespace App\Application\Mcp\Tools;

use App\Application\Mcp\McpContext;
use App\Application\Monitoring\MonitorCommands;
use App\Http\Middleware\EnforceTenantPermission;

/**
 * create_monitor (#185). Validates with the rules of POST …/monitors and
 * creates through MonitorCommands, so plan limits and the egress policy apply
 * exactly as they do for the API. Agent monitors are not offered here.
 */
final class CreateMonitorTool implements McpTool
{
    private const KINDS = ['http', 'tcp', 'heartbeat'];

    /** The arguments the tool accepts; anything else is ignored. */
    private const ARGUMENTS = ['name', 'kind', 'target', 'interval_seconds', 'egress_profile'];

    public function __construct(private readonly MonitorCommands $commands)
    {
    }

    public function name(): string
    {
        return 'create_monitor';
    }

    public function description(): string
    {
        return 'Create an enabled monitor. kind "http" checks a URL, "tcp" a host:port, "heartbeat" waits for pings at the returned ping_url. '
            .'interval_seconds defaults to the plan minimum (at least 60); the plan limits apply.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'maxLength' => 255],
                'kind' => ['type' => 'string', 'enum' => self::KINDS, 'default' => 'http'],
                'target' => ['type' => 'string', 'maxLength' => 1024, 'description' => 'URL for http, host:port for tcp. Not used for heartbeat.'],
                'interval_seconds' => ['type' => 'integer', 'minimum' => 10, 'maximum' => 86400, 'description' => 'Check interval (heartbeat: expected ping interval).'],
                'egress_profile' => ['type' => 'string', 'enum' => ['internal', 'public-crawl', 'api'], 'description' => 'Outbound policy for the check; defaults to internal.'],
            ],
            'required' => ['name'],
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
        $arguments = array_intersect_key($arguments, array_flip(self::ARGUMENTS));
        $arguments['kind'] ??= 'http';

        $rules = MonitorCommands::rules(self::KINDS);
        // http and tcp need a target; a heartbeat has none (the API fills it in).
        $rules['target'] = 'required_unless:kind,heartbeat|nullable|string|max:1024';
        $validated = Arguments::validate($arguments, $rules);
        if ($validated['kind'] === 'heartbeat') {
            unset($validated['target']);
        }

        $monitor = $this->commands->create($context->tenant, $context->environment, $validated);

        $result = ['monitor' => MonitorPresenter::present($monitor)];
        if ($monitor->heartbeat_token !== null) {
            $result['ping_url'] = url('/ping/'.$monitor->heartbeat_token);
        }

        return $result;
    }
}
