<?php

namespace App\Application\Mcp\Tools;

use App\Domain\Monitoring\MonitorKind;
use App\Http\Support\ApiDate;
use App\Infrastructure\Persistence\Eloquent\Monitor;

/**
 * The compact monitor shape the MCP tools return. Leaves out request headers,
 * bodies and heartbeat tokens: an AI client needs the state, not the secrets.
 */
final class MonitorPresenter
{
    /** @return array<string, mixed> */
    public static function present(Monitor $monitor): array
    {
        $kind = $monitor->kind instanceof MonitorKind ? $monitor->kind->value : (string) $monitor->kind;
        $state = $monitor->current_state instanceof \BackedEnum ? $monitor->current_state->value : (string) $monitor->current_state;

        return [
            'monitor_id' => $monitor->public_id,
            'name' => $monitor->name,
            'kind' => $kind,
            'target' => in_array($kind, ['http', 'tcp', 'mqtt'], true) ? $monitor->target : null,
            'state' => $state,
            'enabled' => (bool) $monitor->enabled,
            'interval_seconds' => (int) $monitor->interval_seconds,
            'last_checked_at' => ApiDate::format($monitor->last_checked_at),
            'last_latency_ms' => $monitor->last_latency_ms,
            'down_since' => ApiDate::format($monitor->first_failure_at),
            'tls_expires_at' => ApiDate::format($monitor->tls_expires_at),
        ];
    }
}
