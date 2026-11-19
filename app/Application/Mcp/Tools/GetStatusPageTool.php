<?php

namespace App\Application\Mcp\Tools;

use App\Application\Mcp\McpContext;
use App\Application\Mcp\McpToolException;
use App\Application\StatusPages\StatusPageService;
use App\Http\Middleware\EnforceTenantPermission;
use App\Http\Support\ApiDate;
use Illuminate\Support\Facades\DB;

/**
 * The environment's status page as visitors see it: read from the same
 * pre-rendered snapshot the public page serves.
 */
final class GetStatusPageTool implements McpTool
{
    private const RECENT_UPDATES = 5;

    public function __construct(private readonly StatusPageService $pages) {}

    public function name(): string
    {
        return 'get_status_page';
    }

    public function description(): string
    {
        return "Get this environment's public status page: overall state, each component's state and uptime, maintenance, incidents and recent notices.";
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false];
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
        $page = $this->pages->find($context->environment);
        if ($page === null) {
            throw new McpToolException('This environment has no status page.');
        }

        $pageInfo = [
            'title' => $page->title,
            'visibility' => $page->visibility,
            'public_url' => url('/status/'.$page->slug),
            'custom_url' => $page->custom_domain === null ? null : 'https://'.$page->custom_domain,
        ];

        $cache = DB::table('status_page_cache')->where('status_page_id', $page->id)->first();
        $snapshot = $cache === null ? null : json_decode((string) $cache->payload_json, true);
        if (! is_array($snapshot)) {
            // Rendered by the next monitor:rollup tick.
            return $pageInfo + ['overall_state' => null, 'components' => [], 'generated_at' => null];
        }

        return $pageInfo + [
            'overall_state' => $snapshot['overall_state'] ?? null,
            'overall_uptime' => $snapshot['overall_uptime'] ?? null,
            'components' => array_map(static fn (array $c): array => [
                'name' => $c['name'] ?? null,
                'state' => $c['state'] ?? null,
                'uptime' => $c['uptime'] ?? null,
            ], $snapshot['components'] ?? []),
            'maintenance' => $snapshot['maintenance'] ?? [],
            'incidents' => $snapshot['incidents'] ?? [],
            'updates' => array_slice($snapshot['updates'] ?? [], 0, self::RECENT_UPDATES),
            'generated_at' => ApiDate::format($cache->generated_at),
        ];
    }
}
