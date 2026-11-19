<?php

namespace App\Application\Monitoring;

use App\Domain\Monitoring\AssertionOperator;
use App\Domain\Monitoring\AssertionType;
use App\Domain\Monitoring\MonitorKind;
use App\Domain\Monitoring\NumericThreshold;
use App\Domain\Outbound\OutboundPolicy;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\MonitorAssertion;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MonitorService
{
    public function __construct(
        private readonly OutboundPolicy $outboundPolicy,
        private readonly \App\Domain\Monitoring\Services\HeartbeatTokenGenerator $tokenGenerator = new \App\Domain\Monitoring\Services\HeartbeatTokenGenerator(),
    ) {
    }

    /**
     * @return Collection<int, Monitor>
     */
    public function listMonitors(Tenant $tenant, Environment $environment): Collection
    {
        return Monitor::query()
            ->where('tenant_id', $tenant->id)
            ->where('environment_id', $environment->id)
            ->with('assertions')
            ->get();
    }

    public function getMonitor(Tenant $tenant, Environment $environment, string $publicId): Monitor
    {
        return Monitor::query()
            ->where('tenant_id', $tenant->id)
            ->where('environment_id', $environment->id)
            ->where('public_id', $publicId)
            ->with('assertions')
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createMonitor(Tenant $tenant, Environment $environment, array $data): Monitor
    {
        $target = (string) ($data['target'] ?? '');
        $kind = MonitorKind::tryFromMixed($data['kind'] ?? null);

        if ($kind === MonitorKind::HTTP) {
            // Validate outbound SSRF policy on monitor target URL
            $this->assertTargetAllowed($target, $environment->name, $data['egress_profile'] ?? null);
        }

        return DB::transaction(function () use ($tenant, $environment, $data, $kind) {
            $heartbeatToken = null;
            if ($kind === MonitorKind::HEARTBEAT || (is_string($data['kind'] ?? null) && strtolower($data['kind']) === 'heartbeat')) {
                $heartbeatToken = $data['heartbeat_token'] ?? $this->tokenGenerator->generate();
            }

            /** @var Monitor $monitor */
            $monitor = Monitor::create([
                'tenant_id' => $tenant->id,
                'environment_id' => $environment->id,
                'name' => $data['name'],
                'kind' => $kind,
                'target' => $data['target'] ?? ($kind === MonitorKind::AGENT ? "agent://{$tenant->public_id}" : "heartbeat://{$tenant->public_id}"),
                'http_method' => strtoupper($data['http_method'] ?? 'GET'),
                'request_headers_json' => $data['request_headers_json'] ?? null,
                'request_body' => $data['request_body'] ?? null,
                'interval_seconds' => $data['interval_seconds'] ?? 60,
                'timeout_ms' => $data['timeout_ms'] ?? 10000,
                'confirmation_threshold' => $data['confirmation_threshold'] ?? 3,
                'recovery_threshold' => $data['recovery_threshold'] ?? 2,
                'follow_redirects' => $data['follow_redirects'] ?? true,
                'verify_tls' => $data['verify_tls'] ?? true,
                'egress_profile' => $data['egress_profile'] ?? 'internal',
                'check_region' => $data['check_region'] ?? null,
                'public_safe' => $data['public_safe'] ?? true,
                'enabled' => $data['enabled'] ?? true,
                // Created paused (#246): the same state a pause leaves.
                ...(($data['enabled'] ?? true) ? [] : ['current_state' => \App\Domain\Monitoring\CheckState::PAUSED, 'paused_at' => now()]),
                'heartbeat_token' => $heartbeatToken,
                'heartbeat_grace_seconds' => $data['heartbeat_grace_seconds'] ?? 60,
                'agent_grace_seconds' => $data['agent_grace_seconds'] ?? 180,
                'agent_latency_metric_key' => $data['agent_latency_metric_key'] ?? null,
                'mqtt_client_id' => $data['mqtt_client_id'] ?? null,
                'mqtt_username' => $data['mqtt_username'] ?? null,
                'mqtt_password' => $data['mqtt_password'] ?? null,
                'next_check_at' => now(),
            ]);

            if (isset($data['assertions']) && is_array($data['assertions'])) {
                $this->saveAssertions($monitor, $data['assertions']);
            } elseif ($kind === MonitorKind::HTTP) {
                // Default status code assertion for HTTP
                MonitorAssertion::create([
                    'monitor_id' => $monitor->id,
                    'type' => AssertionType::STATUS_CODE,
                    'operator' => AssertionOperator::BETWEEN,
                    'expected_value' => '200..299',
                    'order' => 0,
                ]);
            }

            return $monitor->fresh('assertions');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    /**
     * A target the outbound policy refuses is a validation error on `target` (422), not a 500 (#156).
     */
    private function assertTargetAllowed(string $target, string $environmentName, ?string $egressProfile): void
    {
        try {
            $this->outboundPolicy->validateUrl($target, [$environmentName], $egressProfile);
        } catch (\App\Domain\Outbound\OutboundPolicyViolation $violation) {
            throw ValidationException::withMessages([
                'target' => [__('messages.monitor_target_blocked', ['reason' => $violation->reasonCode])],
            ]);
        }
    }

    /**
     * Changes a monitor's settings. `enabled` is not written here: pausing and
     * resuming go through MonitorPauseService (#246).
     *
     * @param  array<string, mixed>  $data
     */
    public function updateMonitor(Tenant $tenant, Environment $environment, string $publicId, array $data): Monitor
    {
        $monitor = $this->getMonitor($tenant, $environment, $publicId);

        if (isset($data['target'])) {
            $kind = isset($data['kind']) ? MonitorKind::tryFromMixed($data['kind']) : $monitor->kind;
            if ($kind === MonitorKind::HTTP) {
                $this->assertTargetAllowed((string) $data['target'], $environment->name, $data['egress_profile'] ?? $monitor->egress_profile);
            }
        }

        return DB::transaction(function () use ($monitor, $data) {
            $updatePayload = array_filter([
                'name' => $data['name'] ?? null,
                'kind' => isset($data['kind']) ? MonitorKind::tryFromMixed($data['kind']) : null,
                'target' => $data['target'] ?? null,
                'http_method' => isset($data['http_method']) ? strtoupper($data['http_method']) : null,
                'request_headers_json' => $data['request_headers_json'] ?? null,
                'request_body' => $data['request_body'] ?? null,
                'interval_seconds' => $data['interval_seconds'] ?? null,
                'timeout_ms' => $data['timeout_ms'] ?? null,
                'confirmation_threshold' => $data['confirmation_threshold'] ?? null,
                'recovery_threshold' => $data['recovery_threshold'] ?? null,
                'follow_redirects' => $data['follow_redirects'] ?? null,
                'verify_tls' => $data['verify_tls'] ?? null,
                'egress_profile' => $data['egress_profile'] ?? null,
                'public_safe' => $data['public_safe'] ?? null,
                'heartbeat_grace_seconds' => $data['heartbeat_grace_seconds'] ?? null,
                'agent_grace_seconds' => $data['agent_grace_seconds'] ?? null,
                'mqtt_client_id' => $data['mqtt_client_id'] ?? null,
                'mqtt_username' => $data['mqtt_username'] ?? null,
                // A missing or empty password keeps the stored one (#200).
                'mqtt_password' => ($data['mqtt_password'] ?? '') !== '' ? $data['mqtt_password'] : null,
            ], fn ($v) => $v !== null);
            // Null (or empty) is a value here: no latency metric (#242).
            if (array_key_exists('agent_latency_metric_key', $data)) {
                $updatePayload['agent_latency_metric_key'] = $data['agent_latency_metric_key'];
            }
            // Null is a value here: back to the default probe region (#222).
            if (array_key_exists('check_region', $data)) {
                $updatePayload['check_region'] = $data['check_region'];
            }

            if (($monitor->kind === MonitorKind::HEARTBEAT || ($updatePayload['kind'] ?? null) === MonitorKind::HEARTBEAT) && $monitor->heartbeat_token === null) {
                $updatePayload['heartbeat_token'] = $this->tokenGenerator->generate();
            }

            $monitor->update($updatePayload);

            if (isset($data['assertions']) && is_array($data['assertions'])) {
                $monitor->assertions()->delete();
                $this->saveAssertions($monitor, $data['assertions']);
            }

            return $monitor->fresh('assertions');
        });
    }

    public function rotateHeartbeatToken(Tenant $tenant, Environment $environment, string $publicId): Monitor
    {
        $monitor = $this->getMonitor($tenant, $environment, $publicId);
        $newToken = $this->tokenGenerator->generate();
        $monitor->update(['heartbeat_token' => $newToken]);

        return $monitor->fresh('assertions');
    }

    /**
     * Issues a new ingestion token for an `agent` monitor. Only the hash is
     * stored; the old token stops working at once (addendum §4.2).
     *
     * @return array{0: Monitor, 1: string} the monitor and the plaintext token, shown once
     */
    public function rotateAgentToken(Tenant $tenant, Environment $environment, string $publicId): array
    {
        $monitor = $this->getMonitor($tenant, $environment, $publicId);
        if ($monitor->kind !== MonitorKind::AGENT) {
            throw ValidationException::withMessages(['kind' => 'Only agent monitors have an ingestion token.']);
        }

        $token = \App\Domain\Monitoring\Agent\AgentToken::generate();
        $monitor->forceFill(['agent_token_hash' => \App\Domain\Monitoring\Agent\AgentToken::hash($token)])->save();

        return [$monitor->fresh('assertions'), $token];
    }

    public function deleteMonitor(Tenant $tenant, Environment $environment, string $publicId): void
    {
        $monitor = $this->getMonitor($tenant, $environment, $publicId);
        $monitor->delete();
    }

    /**
     * @param  list<array<string, mixed>>  $assertionsData
     */
    private function saveAssertions(Monitor $monitor, array $assertionsData): void
    {
        foreach ($assertionsData as $index => $ast) {
            $type = AssertionType::tryFrom((string) ($ast['type'] ?? ''));
            $operator = AssertionOperator::tryFrom((string) ($ast['operator'] ?? ''));

            if ($type === null || $operator === null) {
                throw ValidationException::withMessages([
                    "assertions.{$index}" => 'Invalid assertion type or operator.',
                ]);
            }

            if (! in_array($operator, $type->allowedOperators(), true)) {
                throw ValidationException::withMessages([
                    "assertions.{$index}.operator" => sprintf(
                        'Operator "%s" is not supported for assertion type "%s".',
                        $operator->value,
                        $type->value,
                    ),
                ]);
            }

            // Numeric json_path thresholds need a numeric operand (agent-ingestion addendum §3).
            if ($type === AssertionType::JSON_PATH && NumericThreshold::isNumericOperator($operator)) {
                $expected = isset($ast['expected_value']) ? (string) $ast['expected_value'] : null;

                if (! NumericThreshold::isValidExpectation($operator, $expected)) {
                    throw ValidationException::withMessages([
                        "assertions.{$index}.expected_value" => $operator === AssertionOperator::BETWEEN
                            ? 'Expected value must be a numeric range "min,max" or "min..max" with min <= max.'
                            : 'Expected value must be a number.',
                    ]);
                }
            }

            // Regex ReDoS compile safety check (§7.7)
            if ($type === AssertionType::BODY_MATCHES && $operator === AssertionOperator::REGEX) {
                $pattern = (string) ($ast['expected_value'] ?? '');
                if (strlen($pattern) > 500) {
                    throw ValidationException::withMessages([
                        "assertions.{$index}.expected_value" => 'Regex pattern length must not exceed 500 characters.',
                    ]);
                }
                if (@preg_match($pattern, 'probe') === false) {
                    throw ValidationException::withMessages([
                        "assertions.{$index}.expected_value" => 'Invalid regular expression pattern.',
                    ]);
                }
            }

            MonitorAssertion::create([
                'monitor_id' => $monitor->id,
                'type' => $type,
                'operator' => $operator,
                'target_property' => $ast['target_property'] ?? null,
                'expected_value' => $ast['expected_value'] ?? null,
                'case_sensitive' => $ast['case_sensitive'] ?? false,
                'order' => $index,
            ]);
        }
    }
}
