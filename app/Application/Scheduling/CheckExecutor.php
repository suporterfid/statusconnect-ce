<?php

namespace App\Application\Scheduling;

use App\Application\Incidents\IncidentService;
use App\Domain\Monitoring\AssertionDefinition;
use App\Domain\Monitoring\AssertionEvaluator;
use App\Domain\Monitoring\AssertionOperator;
use App\Domain\Monitoring\AssertionType;
use App\Domain\Monitoring\CheckOutcome;
use App\Domain\Monitoring\CheckState;
use App\Domain\Monitoring\MonitorKind;

use App\Domain\Outbound\OutboundPolicy;
use App\Domain\Outbound\OutboundPolicyViolation;
use App\Domain\Secrets\SecretRedactor;
use App\Domain\Shared\Clock;
use App\Infrastructure\HttpClient\PinnedHttpRequest;
use App\Infrastructure\HttpClient\PinnedHttpTransport;
use App\Infrastructure\HttpClient\PinnedHttpResponse;
use App\Infrastructure\Persistence\Eloquent\CheckResult;
use App\Infrastructure\Persistence\Eloquent\Monitor;

class CheckExecutor
{
    private const CHECK_FAILURE_EXCERPT_BYTES = 512;

    public function __construct(
        private readonly OutboundPolicy $outboundPolicy,
        private readonly PinnedHttpTransport $httpTransport,
        private readonly AssertionEvaluator $assertionEvaluator,
        private readonly SecretRedactor $secretRedactor,
        private readonly Clock $clock,
        private readonly IncidentService $incidentService,
        private readonly \App\Domain\Monitoring\Services\HeartbeatMonitorEvaluator $heartbeatEvaluator = new \App\Domain\Monitoring\Services\HeartbeatMonitorEvaluator(),
        private readonly \App\Domain\Maintenance\Services\MaintenanceWindowEvaluator $maintenanceEvaluator = new \App\Domain\Maintenance\Services\MaintenanceWindowEvaluator(),
        private readonly \App\Domain\Monitoring\Agent\AgentMonitorEvaluator $agentEvaluator = new \App\Domain\Monitoring\Agent\AgentMonitorEvaluator(),
        private readonly ?\App\Infrastructure\Persistence\Eloquent\AgentMetricRepository $agentMetrics = null,
    ) {
    }

    public function execute(Monitor $monitor): ?CheckResult
    {
        $now = $this->clock->nowUtc();

        if ($monitor->kind === MonitorKind::HEARTBEAT) {
            $evalResult = $this->heartbeatEvaluator->evaluate($monitor, $now);
            $outcome = new CheckOutcome(
                statusCode: $evalResult->state === CheckState::UP ? 200 : 503,
                latencyMs: 0,
            );

            return $this->incidentService->record($monitor, $outcome, $evalResult, $now, null)?->checkResult;
        }

        if ($monitor->kind === MonitorKind::AGENT) {
            return $this->persist($monitor, new CheckOutcome(statusCode: 200));
        }

        if ($monitor->kind === MonitorKind::HTTP) {
            $outcome = $this->executeHttpCheck($monitor);
        } else {
            // TCP / other fallback
            $outcome = new CheckOutcome(statusCode: 200, latencyMs: 10);
        }

        return $this->persist($monitor, $outcome);
    }

    public function persist(Monitor $monitor, CheckOutcome $outcome): ?CheckResult
    {
        $now = $this->clock->nowUtc();
        $claimToken = $monitor->claim_token;

        if ($monitor->kind === MonitorKind::AGENT) {
            return $this->persistAgent($monitor, $outcome, $now);
        }

        if ($this->maintenanceEvaluator->isInMaintenance($monitor, $now)) {
            $evalResult = new \App\Domain\Monitoring\EvaluationResult(state: CheckState::MAINTENANCE, reason: 'Maintenance window active');
            return $this->incidentService->record($monitor, $outcome, $evalResult, $now, null)?->checkResult;
        }

        if ($monitor->kind === MonitorKind::HEARTBEAT) {
            $evalResult = $this->heartbeatEvaluator->evaluate($monitor, $now);
            return $this->incidentService->record($monitor, $outcome, $evalResult, $now, null)?->checkResult;
        }

        $assertionDefs = $this->assertionDefinitions($monitor);

        $evalResult = $this->assertionEvaluator->evaluate($outcome, $assertionDefs);

        $failureExcerpt = null;
        if ($evalResult->state !== CheckState::UP && $outcome->body !== '') {
            $rawExcerpt = substr($outcome->body, 0, self::CHECK_FAILURE_EXCERPT_BYTES);
            $failureExcerpt = $this->secretRedactor->redactString($rawExcerpt);
        }

        return $this->incidentService->record(
            $monitor, $outcome, $evalResult, $now, $failureExcerpt,
            $this->secondLocationCheck($monitor, $outcome, $assertionDefs),
        )?->checkResult;
    }

    /**
     * True when persisting this HTTP outcome only records another "up": the
     * monitor is up, stays up and is not in maintenance. Such a result opens
     * or closes nothing, so it sends no notification and asks no second
     * location. The rolling checker (#221) persists these at once and holds
     * every other result until no request is in flight, so that slow side
     * effects never stall requests that are still running.
     */
    public function isRoutineUp(Monitor $monitor, CheckOutcome $outcome): bool
    {
        if ($monitor->kind !== MonitorKind::HTTP
            || $monitor->current_state !== CheckState::UP
            || $outcome->blockedReason !== null
            || $outcome->transportError !== null
            || $this->maintenanceEvaluator->isInMaintenance($monitor, $this->clock->nowUtc())) {
            return false;
        }

        return $this->assertionEvaluator->evaluate($outcome, $this->assertionDefinitions($monitor))->state === CheckState::UP;
    }

    /** @return list<AssertionDefinition> */
    private function assertionDefinitions(Monitor $monitor): array
    {
        return $monitor->assertions->map(fn ($ast) => new AssertionDefinition(
            type: $ast->type,
            operator: $ast->operator,
            targetProperty: $ast->target_property,
            expectedValue: $ast->expected_value,
            caseSensitive: $ast->case_sensitive,
            order: $ast->order,
        ))->all();
    }

    /**
     * Closure the incident service calls only when a failure is about to open
     * an incident (#128): it asks a second location to repeat the check and
     * evaluates the answer with the monitor's own assertions. Null when the
     * feature is off or the failure is not one a second location can judge
     * (the check was blocked here by the outbound policy).
     *
     * @param  list<AssertionDefinition>  $assertionDefs
     */
    private function secondLocationCheck(Monitor $monitor, CheckOutcome $outcome, array $assertionDefs): ?\Closure
    {
        if ($outcome->blockedReason !== null || ! in_array($monitor->kind, [MonitorKind::HTTP, MonitorKind::TCP], true)) {
            return null;
        }
        $confirmer = app(\App\Application\SecondLocation\SecondLocationConfirmer::class);
        if (! $confirmer->enabled()) {
            return null;
        }

        return function () use ($confirmer, $monitor, $assertionDefs): ?\App\Application\SecondLocation\SecondLocationVerdict {
            $request = $this->confirmationRequest($monitor);
            $result = $request === null ? null : $confirmer->confirm($request);
            if ($result === null) {
                return null;
            }
            $evaluation = $monitor->kind === MonitorKind::TCP
                ? new \App\Domain\Monitoring\EvaluationResult($result->outcome->transportError === null ? CheckState::UP : CheckState::DOWN, $result->outcome->transportError)
                : $this->assertionEvaluator->evaluate($result->outcome, $assertionDefs);

            return new \App\Application\SecondLocation\SecondLocationVerdict($evaluation->state, $result->provider, $result->location, $evaluation->reason);
        };
    }

    private function confirmationRequest(Monitor $monitor): ?\App\Application\SecondLocation\ConfirmationRequest
    {
        if ($monitor->kind === MonitorKind::TCP) {
            [$host, $port] = $this->splitHostPort((string) $monitor->target);

            return $host === '' || $port < 1 ? null : new \App\Application\SecondLocation\ConfirmationRequest(
                kind: 'tcp', host: $host, port: $port, timeoutMs: (int) $monitor->timeout_ms,
            );
        }

        return new \App\Application\SecondLocation\ConfirmationRequest(
            kind: 'http',
            url: (string) $monitor->target,
            method: $monitor->http_method ?: 'GET',
            headers: is_array($monitor->request_headers_json) ? array_map('strval', $monitor->request_headers_json) : [],
            body: $monitor->request_body,
            timeoutMs: (int) $monitor->timeout_ms,
            verifyTls: (bool) $monitor->verify_tls,
            followRedirects: (bool) $monitor->follow_redirects,
        );
    }

    /** @return array{0: string, 1: int} */
    private function splitHostPort(string $target): array
    {
        $target = preg_replace('#^tcp://#i', '', trim($target)) ?? '';
        if (preg_match('/^\[([^\]]+)\]:(\d+)$/', $target, $m) === 1 || preg_match('/^([^:]+):(\d+)$/', $target, $m) === 1) {
            return [$m[1], (int) $m[2]];
        }

        return ['', 0];
    }

    /**
     * Agent monitors are evaluated from stored metrics: a database comparison
     * like heartbeat, with no network I/O (agent-ingestion addendum §5.3).
     * The reason may name metric keys; it stays on the operator side, since
     * the public status page only publishes state.
     */
    private function persistAgent(Monitor $monitor, CheckOutcome $outcome, \DateTimeImmutable $now): ?CheckResult
    {
        $metrics = $this->agentMetrics ?? app(\App\Infrastructure\Persistence\Eloquent\AgentMetricRepository::class);
        $latest = $metrics->latest($monitor);
        $lastIngestAt = $monitor->last_ingest_at?->toDateTimeImmutable();
        $graceSeconds = (int) ($monitor->agent_grace_seconds ?? 180);
        $evaluation = $this->agentEvaluator->evaluate(
            $latest,
            $metrics->rules($monitor),
            $lastIngestAt,
            $now,
            (int) $monitor->interval_seconds,
            $graceSeconds,
            $this->maintenanceEvaluator->isInMaintenance($monitor, $now),
        );

        // The latency metric's value becomes the check's latency (#242), so
        // rollups and the response-time charts pick it up. 0 = no latency.
        $latencyMs = $this->agentEvaluator->latencyMs(
            $latest,
            $monitor->agent_latency_metric_key,
            $lastIngestAt,
            $now,
            (int) $monitor->interval_seconds,
            $graceSeconds,
        );
        if ($latencyMs !== null) {
            $outcome = new CheckOutcome(
                statusCode: $outcome->statusCode,
                latencyMs: $latencyMs,
                headers: $outcome->headers,
                body: $outcome->body,
                transportError: $outcome->transportError,
                blockedReason: $outcome->blockedReason,
                tlsExpiresAt: $outcome->tlsExpiresAt,
                location: $outcome->location,
            );
        }

        $redacted = $evaluation->reason === null ? null : $this->secretRedactor->redactString($evaluation->reason);
        $evaluation = new \App\Domain\Monitoring\EvaluationResult($evaluation->state, $redacted);

        return $this->incidentService->record($monitor, $outcome, $evaluation, $now, null)?->checkResult;
    }

    public function prepareHttp(Monitor $monitor): PinnedHttpRequest|CheckOutcome
    {
        try {
            $validatedEndpoint = $this->outboundPolicy->validateUrl(
                $monitor->target,
                [$monitor->environment?->name ?? 'production'],
                $monitor->egress_profile,
            );
        } catch (OutboundPolicyViolation $violation) {
            return CheckOutcome::blocked($violation->reasonCode);
        }

        return new PinnedHttpRequest(
            method: $monitor->http_method ?: 'GET', endpoint: $validatedEndpoint,
            headers: is_array($monitor->request_headers_json) ? $monitor->request_headers_json : [],
            body: $monitor->request_body, verifyTls: $monitor->verify_tls, followRedirects: $monitor->follow_redirects,
            totalTimeout: (int) ceil($monitor->timeout_ms / 1000),
            egressProfile: \App\Domain\Outbound\EgressProfile::tryFromMixed($monitor->egress_profile),
        );
    }

    public function outcomeFromHttpResponse(PinnedHttpResponse $response, int $latencyMs, ?string $location = null): CheckOutcome
    {
        if ($response->transportError !== null && str_starts_with($response->transportError, 'blocked:')) {
            return new CheckOutcome(blockedReason: substr($response->transportError, 8), location: $location);
        }
        return $response->transportError !== null
            ? new CheckOutcome(latencyMs: $latencyMs, transportError: $response->transportError, location: $location)
            : new CheckOutcome(statusCode: $response->statusCode, latencyMs: $latencyMs, headers: $response->headers, body: $response->bodyTruncated, tlsExpiresAt: $response->tlsExpiresAt, location: $location);
    }

    private function executeHttpCheck(Monitor $monitor): CheckOutcome
    {
        $request = $this->prepareHttp($monitor);
        if ($request instanceof CheckOutcome) return $request;

        $startTime = microtime(true);
        $response = $this->httpTransport->send($request);
        $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

        return $this->outcomeFromHttpResponse($response, $latencyMs);
    }
}
