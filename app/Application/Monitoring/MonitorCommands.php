<?php

namespace App\Application\Monitoring;

use App\Application\Plans\PlanLimits;
use App\Domain\Monitoring\MonitorKind;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creating and changing monitors with the plan limits applied (#119, #185).
 *
 * One code path for every client that writes monitors: the REST API
 * (MonitorController) and the MCP tools. Validation rules live here too, so
 * the two cannot drift apart. The egress policy is applied by MonitorService.
 */
final class MonitorCommands
{
    public function __construct(
        private readonly MonitorService $monitors,
        private readonly PlanLimits $planLimits,
        private readonly MonitorPauseService $pauses,
    ) {
    }

    /**
     * Validation rules for a new monitor (`$forUpdate = false`) or a change.
     *
     * @param  list<string>  $kinds
     * @return array<string, string>
     */
    public static function rules(array $kinds, bool $forUpdate = false): array
    {
        return [
            'name' => ($forUpdate ? 'nullable' : 'required').'|string|max:255',
            'kind' => 'nullable|string|in:'.implode(',', $kinds),
            'target' => 'nullable|string|max:1024',
            'agent_grace_seconds' => 'nullable|integer|min:0|max:86400',
            // Agent monitors only (#242): checked in create()/update().
            'agent_latency_metric_key' => 'nullable|string|max:255',
            'heartbeat_grace_seconds' => 'nullable|integer|min:0|max:86400',
            'http_method' => 'nullable|string|in:GET,POST,PUT,PATCH,DELETE,HEAD,OPTIONS',
            'request_headers_json' => 'nullable|array',
            'request_body' => 'nullable|string',
            'interval_seconds' => 'nullable|integer|min:10|max:86400',
            'timeout_ms' => 'nullable|integer|min:500|max:30000',
            'confirmation_threshold' => 'nullable|integer|min:1|max:10',
            'recovery_threshold' => 'nullable|integer|min:1|max:10',
            'follow_redirects' => 'nullable|boolean',
            'verify_tls' => 'nullable|boolean',
            'egress_profile' => 'nullable|string|in:internal,public-crawl,api',
            // Probe region of an HTTP monitor (#222); null = the default region.
            'check_region' => 'nullable|string|max:32',
            'public_safe' => 'nullable|boolean',
            'enabled' => 'nullable|boolean',
            // MQTT monitors (#200). 23 characters is the client id length every 3.1.1 broker must accept.
            'mqtt_client_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,23}$/'],
            'mqtt_username' => 'nullable|string|max:255',
            'mqtt_password' => 'nullable|string|max:1024',
            'assertions' => 'nullable|array',
            'assertions.*.type' => 'required_with:assertions|string',
            'assertions.*.operator' => 'required_with:assertions|string',
            'assertions.*.target_property' => 'nullable|string',
            'assertions.*.expected_value' => 'nullable|string',
            'assertions.*.case_sensitive' => 'nullable|boolean',
        ];
    }

    /**
     * Creates a monitor from validated input. A missing interval defaults to
     * the plan's minimum when that is above 60 s.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException when the plan or the egress policy refuses it.
     */
    public function create(Tenant $tenant, Environment $environment, array $validated): Monitor
    {
        $kind = $validated['kind'] ?? MonitorKind::HTTP->value;
        if ($kind === MonitorKind::MQTT->value) {
            self::assertMqttTarget((string) ($validated['target'] ?? ''));
        }

        if ($kind === MonitorKind::AGENT->value && isset($validated['interval_seconds']) && $validated['interval_seconds'] < 60) {
            throw ValidationException::withMessages(['interval_seconds' => [__('messages.agent_interval_floor')]]);
        }

        $this->normalizeCheckRegion($tenant, $kind, $validated);
        self::normalizeAgentLatencyMetric($kind, $validated);
        $validated['interval_seconds'] ??= max(60, $this->planLimits->minInterval($tenant));
        $this->planLimits->assertInterval($tenant, (int) $validated['interval_seconds']);
        if ($validated['enabled'] ?? true) {
            $this->planLimits->assertCanEnableMonitor($tenant, $kind);
            app(\App\Application\Signup\SignupLimits::class)->assertNewAccountRate($tenant, $kind, (int) $validated['interval_seconds']);
        }

        return $this->monitors->createMonitor($tenant, $environment, $validated);
    }

    /**
     * Applies validated changes to a monitor. Plan limits are checked only for
     * the fields this change touches. A change of `enabled` pauses or resumes
     * the monitor through MonitorPauseService (#246); $actor and $source go to
     * the audit log.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException when the plan or the egress policy refuses it.
     */
    public function update(Tenant $tenant, Environment $environment, Monitor $current, array $validated, ?User $actor = null, array $auditContext = ['source' => 'api']): Monitor
    {
        $isAgent = $current->kind === MonitorKind::AGENT;
        $kind = isset($validated['kind']) ? MonitorKind::tryFromMixed($validated['kind']) : $current->kind;
        if ($kind === MonitorKind::MQTT && (isset($validated['target']) || $current->kind !== MonitorKind::MQTT)) {
            self::assertMqttTarget((string) ($validated['target'] ?? $current->target));
        }

        if ($isAgent && isset($validated['interval_seconds']) && $validated['interval_seconds'] < 60) {
            throw ValidationException::withMessages(['interval_seconds' => [__('messages.agent_interval_floor')]]);
        }
        $this->normalizeCheckRegion($tenant, ($kind ?? $current->kind)->value, $validated, $current->check_region);
        self::normalizeAgentLatencyMetric(($kind ?? $current->kind)->value, $validated);

        if (isset($validated['interval_seconds']) && (int) $validated['interval_seconds'] !== (int) $current->interval_seconds) {
            $this->planLimits->assertInterval($tenant, (int) $validated['interval_seconds']);
        }
        if (($validated['enabled'] ?? false) && ! $current->enabled) {
            $this->planLimits->assertCanEnableMonitor($tenant, $current->kind->value, $current->id);
        }
        $enabledAfter = (bool) ($validated['enabled'] ?? $current->enabled);
        $intervalAfter = (int) ($validated['interval_seconds'] ?? $current->interval_seconds);
        if ($enabledAfter && (! $current->enabled || $intervalAfter < (int) $current->interval_seconds || isset($validated['kind']))) {
            app(\App\Application\Signup\SignupLimits::class)->assertNewAccountRate($tenant, ($kind ?? $current->kind)->value, $intervalAfter, $current->id);
        }

        $enabled = array_key_exists('enabled', $validated) && $validated['enabled'] !== null ? (bool) $validated['enabled'] : null;
        unset($validated['enabled']);

        return DB::transaction(function () use ($tenant, $environment, $current, $validated, $enabled, $actor, $auditContext): Monitor {
            $monitor = $this->monitors->updateMonitor($tenant, $environment, $current->public_id, $validated);
            if ($enabled === null) {
                return $monitor;
            }
            $enabled
                ? $this->pauses->resume($monitor, $actor, $auditContext)
                : $this->pauses->pause($monitor, $actor, $auditContext);

            return $monitor->fresh('assertions');
        });
    }

    /**
     * Probe region (#222). An unknown key is refused; the default region is
     * stored as null, so the monitor follows the default if it changes. Another
     * region needs a plan with check_regions and an HTTP monitor. Keeping the
     * region a monitor already has is always allowed.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private function normalizeCheckRegion(Tenant $tenant, string $kind, array &$validated, ?string $currentRegion = null): void
    {
        if (! array_key_exists('check_region', $validated)) {
            return;
        }
        $region = $validated['check_region'];
        $regions = app(\App\Application\Scheduling\CheckRegions::class);
        if ($region === null || $region === '' || $region === $regions->defaultKey()) {
            $validated['check_region'] = null;

            return;
        }
        if ($region === $currentRegion) {
            return;
        }
        if (! $regions->exists($region)) {
            throw ValidationException::withMessages(['check_region' => [__('messages.check_region_unknown', ['region' => $region])]]);
        }
        if ($kind !== MonitorKind::HTTP->value) {
            throw ValidationException::withMessages(['check_region' => [__('messages.check_region_http_only')]]);
        }
        $this->planLimits->assertCheckRegions($tenant);
    }

    /**
     * Latency metric (#242): a metric key of an agent monitor, trimmed; empty
     * is stored as null. Any other kind of monitor refuses a key (422).
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private static function normalizeAgentLatencyMetric(string $kind, array &$validated): void
    {
        if (! array_key_exists('agent_latency_metric_key', $validated)) {
            return;
        }
        $key = trim((string) ($validated['agent_latency_metric_key'] ?? ''));
        $validated['agent_latency_metric_key'] = $key === '' ? null : $key;
        if ($key !== '' && $kind !== MonitorKind::AGENT->value) {
            throw ValidationException::withMessages(['agent_latency_metric_key' => [__('messages.agent_latency_metric_agent_only')]]);
        }
    }

    /** @throws ValidationException when the target is not mqtt://host[:port] or mqtts://host[:port]. */
    private static function assertMqttTarget(string $target): void
    {
        try {
            \App\Domain\Monitoring\Mqtt\MqttTarget::parse($target);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['target' => [__('messages.mqtt_target_invalid')]]);
        }
    }

    /**
     * Pauses or resumes a monitor. Resuming counts against the plan's limits.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException when the monitor is not in this environment.
     * @throws ValidationException when the plan has no room for one more enabled monitor.
     */
    public function setEnabled(Tenant $tenant, Environment $environment, string $publicId, bool $enabled, ?User $actor = null, array $auditContext = ['source' => 'api']): Monitor
    {
        $current = $this->monitors->getMonitor($tenant, $environment, $publicId);

        return $this->update($tenant, $environment, $current, ['enabled' => $enabled], $actor, $auditContext);
    }
}
