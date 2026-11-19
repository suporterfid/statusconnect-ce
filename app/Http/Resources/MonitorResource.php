<?php

namespace App\Http\Resources;

use App\Application\Secrets\ResponseSecretMask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A monitor as the API shows it (#155): no internal ids or scheduler lease
 * fields, and credential headers masked.
 *
 * @mixin \App\Infrastructure\Persistence\Eloquent\Monitor
 */
class MonitorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'kind' => $this->kind,
            'target' => $this->target,
            'http_method' => $this->http_method,
            'request_headers_json' => ResponseSecretMask::headers($this->request_headers_json),
            'request_body' => $this->request_body,
            'interval_seconds' => $this->interval_seconds,
            'timeout_ms' => $this->timeout_ms,
            'confirmation_threshold' => $this->confirmation_threshold,
            'recovery_threshold' => $this->recovery_threshold,
            'follow_redirects' => $this->follow_redirects,
            'verify_tls' => $this->verify_tls,
            'egress_profile' => $this->egress_profile,
            // Probe region key (#222); null = the default region.
            'check_region' => $this->check_region,
            'public_safe' => $this->public_safe,
            'enabled' => $this->enabled,
            'paused_at' => $this->paused_at,
            'current_state' => $this->current_state,
            'consecutive_failures' => $this->consecutive_failures,
            'consecutive_successes' => $this->consecutive_successes,
            'first_failure_at' => $this->first_failure_at,
            'last_checked_at' => $this->last_checked_at,
            'next_check_at' => $this->next_check_at,
            'last_latency_ms' => $this->last_latency_ms,
            'tls_expires_at' => $this->tls_expires_at,
            'heartbeat_token' => $this->heartbeat_token,
            'heartbeat_grace_seconds' => $this->heartbeat_grace_seconds,
            'agent_grace_seconds' => $this->agent_grace_seconds,
            // Metric whose value becomes the agent check's latency (#242); null = none.
            'agent_latency_metric_key' => $this->agent_latency_metric_key,
            // MQTT (#200): the password is never returned, only whether one is stored.
            'mqtt_client_id' => $this->mqtt_client_id,
            'mqtt_username' => $this->mqtt_username,
            'mqtt_password_set' => $this->mqtt_password !== null && $this->mqtt_password !== '',
            'last_ingest_at' => $this->last_ingest_at,
            'last_ping_at' => $this->last_ping_at,
            'flapping_since' => $this->flapping_since,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'assertions' => $this->whenLoaded('assertions', fn () => $this->assertions->map(fn ($a) => [
                'public_id' => $a->public_id,
                'type' => $a->type,
                'operator' => $a->operator,
                'target_property' => $a->target_property,
                'expected_value' => $a->expected_value,
                'case_sensitive' => $a->case_sensitive,
                'order' => $a->order,
            ])->values()),
        ];
    }
}
