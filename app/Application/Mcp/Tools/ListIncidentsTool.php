<?php

namespace App\Application\Mcp\Tools;

use App\Application\Mcp\McpContext;
use App\Http\Middleware\EnforceTenantPermission;
use App\Http\Support\ApiDate;
use App\Infrastructure\Persistence\Eloquent\Incident;

final class ListIncidentsTool implements McpTool
{
    public function name(): string
    {
        return 'list_incidents';
    }

    public function description(): string
    {
        return 'List incidents of this environment, newest first. Defaults to the open ones.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['open', 'resolved', 'all'], 'default' => 'open'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
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
            'status' => ['sometimes', 'string', 'in:open,resolved,all'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $status = $args['status'] ?? 'open';

        $query = Incident::query()
            ->where('tenant_id', $context->tenant->id)
            ->where('environment_id', $context->environment->id)
            ->with(['monitor', 'updates'])
            ->orderByDesc('started_at')
            ->orderByDesc('id');
        if ($status === 'open') {
            $query->whereNull('resolved_at');
        } elseif ($status === 'resolved') {
            $query->whereNotNull('resolved_at');
        }

        $incidents = $query->limit($args['limit'] ?? 20)->get()->map(function (Incident $incident): array {
            $latest = $incident->updates->last();

            return [
                'incident_id' => $incident->public_id,
                'summary' => $incident->summary,
                'severity' => $incident->severity,
                'status' => $incident->resolved_at === null ? 'open' : 'resolved',
                'manual' => (bool) $incident->manual,
                'monitor' => $incident->monitor === null ? null : [
                    'monitor_id' => $incident->monitor->public_id,
                    'name' => $incident->monitor->name,
                ],
                'started_at' => ApiDate::format($incident->started_at),
                'resolved_at' => ApiDate::format($incident->resolved_at),
                'duration_seconds' => $incident->duration_seconds,
                'latest_update' => $latest === null ? null : [
                    'status' => $latest->status,
                    'message' => $latest->message,
                    'published_at' => ApiDate::format($latest->published_at),
                ],
            ];
        });

        return ['incidents' => $incidents->values()->all()];
    }
}
