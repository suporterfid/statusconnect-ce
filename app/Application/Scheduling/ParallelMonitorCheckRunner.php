<?php

namespace App\Application\Scheduling;

use App\Domain\Monitoring\CheckOutcome;
use App\Domain\Monitoring\MonitorKind;
use App\Domain\Outbound\OutboundPolicyViolation;
use App\Domain\Outbound\TcpTargetValidator;
use App\Infrastructure\HttpClient\MultiPinnedHttpProbe;
use App\Infrastructure\HttpClient\MultiPinnedHttpRequest;
use App\Infrastructure\HttpClient\MultiPinnedHttpResult;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Tcp\PinnedTcpProbe;
use App\Infrastructure\Tcp\PinnedTcpRequest;
use Illuminate\Support\Facades\Log;

final class ParallelMonitorCheckRunner
{
    public function __construct(
        private readonly DueMonitorClaimer $claimer,
        private readonly CheckExecutor $executor,
        private readonly MultiPinnedHttpProbe $httpProbe,
        private readonly PinnedTcpProbe $tcpProbe,
        private readonly TcpTargetValidator $tcpTargetValidator,
        private readonly HeartbeatWriter $heartbeatWriter,
    ) {
    }

    /**
     * One tick. With $onlyTenantId the tick is a capacity test (#214): it
     * claims only that organization's monitors and leaves the checker's
     * heartbeat, gap and saturation records alone.
     *
     * @return array{claimed:int, executed:int, deferred:int, budget_stopped:bool, duration_ms:int}
     */
    public function run(?TickBudget $budget = null, ?int $onlyTenantId = null): array
    {
        $budget ??= TickBudget::fromConfig();
        $isTest = $onlyTenantId !== null;
        if (! $isTest) {
            // Before the heartbeat moves: a stale one means the checker was away (#199).
            app(CheckerGapRecorder::class)->beforeTick();
        }
        app(\App\Application\SecondLocation\SecondLocationConfirmer::class)->startTick($budget);
        $limits = SchedulerLimits::fromConfig();
        $claimed = 0;
        $executed = 0;
        $claimedIds = [];
        $reserve = max(0, (int) config('scheduler.execution_reserve_seconds', 1));

        // TCP, MQTT, heartbeat and agent monitors first, in waves: they are few,
        // and heartbeat/agent results can open incidents (slow side effects).
        $stopped = $this->runWaves($budget, $limits, $reserve, $onlyTenantId, self::WAVE_KINDS, $claimedIds, $claimed, $executed);
        // HTTP monitors with rolling concurrency (#221).
        $stopped = $this->runRolling($budget, $limits, $reserve, $onlyTenantId, $claimedIds, $claimed, $executed) || $stopped;

        // Claims stop once the remaining budget is shorter than a monitor's
        // timeout, so due monitors can be left over without the budget being
        // exhausted. They wait for the next tick; count them so the heartbeat
        // shows the checker is over capacity (docs/deployment/capacity.md).
        $deferred = $this->claimer->countDue($claimedIds, $onlyTenantId);
        $stopped = $stopped || $budget->exhausted() || $deferred > 0;
        $stats = compact('claimed', 'executed', 'deferred') + [
            'budget_stopped' => $stopped,
            'duration_ms' => (int) round($budget->elapsedSeconds() * 1000),
        ];
        if ($this->httpProbe instanceof \App\Infrastructure\HttpClient\ReportsExecutionStats) {
            // Where HTTP checks ran this tick (#222): batches sent, checks on the probe and here, failed batches.
            $stats['probe'] = $this->httpProbe->takeStats();
        }
        if (! $isTest) {
            $this->heartbeatWriter->record('checker', $stats);
            app(CheckerSaturation::class)->afterTick($stats);
        }
        return $stats;
    }

    /** Kinds checked in waves; HTTP runs with rolling concurrency. */
    private const WAVE_KINDS = ['tcp', 'mqtt', 'heartbeat', 'agent'];

    /**
     * Claims and checks monitors of $kinds in waves of SchedulerLimits::waveSize.
     * Each wave waits for its slowest check. Returns true when it stopped on budget.
     *
     * @param  list<string>  $kinds
     * @param  list<int>  $claimedIds
     */
    private function runWaves(TickBudget $budget, SchedulerLimits $limits, int $reserve, ?int $onlyTenantId, array $kinds, array &$claimedIds, int &$claimed, int &$executed): bool
    {
        $stopped = false;
        while ($budget->canClaimMore()) {
            $maxTimeoutMs = (int) floor(($budget->remainingSeconds() - $reserve) * 1000);
            if ($maxTimeoutMs <= 0) { $stopped = true; break; }
            $wave = $this->claimer->claimDueMonitors(
                limit: $limits->waveSize,
                excludedMonitorIds: $claimedIds,
                maxTimeoutMs: $maxTimeoutMs,
                tenantId: $onlyTenantId,
                kinds: $kinds,
            );
            if ($wave->isEmpty()) break;
            $claimed += $wave->count();
            $claimedIds = [...$claimedIds, ...$wave->pluck('id')->all()];
            $requests = [];
            $tcpRequests = [];
            $mqtt = [];
            $blocked = [];
            foreach ($wave as $monitor) {
                // Heartbeat and agent monitors are database comparisons: no
                // network I/O, so they never take a concurrency slot.
                if ($monitor->kind === MonitorKind::HEARTBEAT || $monitor->kind === MonitorKind::AGENT) {
                    $blocked[] = [$monitor, new CheckOutcome(statusCode: 200)];
                    continue;
                }
                if ($monitor->kind === MonitorKind::MQTT) {
                    // CONNECT/CONNACK (#200), run one by one after the parallel probes.
                    try {
                        $target = \App\Domain\Monitoring\Mqtt\MqttTarget::parse((string) $monitor->target);
                        $mqtt[] = [$monitor, $target, $this->tcpTargetValidator->validate($target->hostPort())];
                    } catch (\InvalidArgumentException) {
                        $blocked[] = [$monitor, CheckOutcome::blocked('invalid_mqtt_target')];
                    } catch (OutboundPolicyViolation $violation) {
                        $blocked[] = [$monitor, CheckOutcome::blocked($violation->reasonCode)];
                    }
                    continue;
                }
                if ($monitor->kind === MonitorKind::TCP) {
                    try {
                        $tcpRequests[] = new PinnedTcpRequest($monitor->id, $this->tcpTargetValidator->validate($monitor->target), $monitor->timeout_ms);
                    } catch (OutboundPolicyViolation $violation) {
                        $blocked[] = [$monitor, CheckOutcome::blocked($violation->reasonCode)];
                    }
                    continue;
                }
                $prepared = $this->executor->prepareHttp($monitor);
                if ($prepared instanceof CheckOutcome) { $blocked[] = [$monitor, $prepared]; continue; }
                $requests[] = new MultiPinnedHttpRequest($monitor->id, $prepared, $monitor->check_region);
            }
            // All network probes of the wave finish before any result is persisted:
            // persisting can open incidents and send notifications, which takes
            // seconds, and a TCP socket left waiting meanwhile would read as a
            // timeout of the target (false MQTT-port outages on 2026-11-16).
            $tcpBatch = $this->tcpProbe instanceof \App\Infrastructure\Tcp\StreamSelectPinnedTcpProbe
                ? $this->tcpProbe->start($tcpRequests)
                : null;
            $httpResults = $this->httpProbe->probe($requests);
            $tcpResults = $tcpBatch === null ? $this->tcpProbe->probe($tcpRequests) : $this->tcpProbe->finish($tcpBatch, hrtime(true) + ((int) floor($budget->remainingSeconds() * 1_000_000_000)));
            foreach ($blocked as [$monitor, $outcome]) if ($this->persistSafely($monitor, $outcome)) $executed++;
            $byId = $wave->keyBy('id');
            foreach ($httpResults as $result) {
                $monitor = $byId->get($result->monitorId);
                if ($monitor !== null && $this->persistSafely($monitor, $this->executor->outcomeFromHttpResponse($result->response, $result->latencyMs, $result->location))) $executed++;
            }
            foreach ($tcpResults as $result) {
                $monitor = $byId->get($result->monitorId);
                $outcome = $result->connected
                    ? new CheckOutcome(statusCode: 200, latencyMs: $result->latencyMs)
                    : CheckOutcome::transportError($result->error ?? 'tcp_connect_error', $result->latencyMs);
                if ($monitor !== null && $this->persistSafely($monitor, $outcome)) $executed++;
            }
            foreach ($mqtt as [$monitor, $target, $endpoint]) {
                $timeoutMs = min((int) $monitor->timeout_ms, (int) floor(($budget->remainingSeconds() - $reserve) * 1000));
                $outcome = $timeoutMs <= 0
                    ? CheckOutcome::transportError('MQTT check skipped: tick budget exhausted')
                    : app(\App\Infrastructure\Mqtt\MqttConnectProbe::class)->check(
                        $endpoint, $target->tls, (bool) $monitor->verify_tls,
                        $monitor->mqtt_client_id ?: 'sc-'.substr((string) $monitor->public_id, -20),
                        $monitor->mqtt_username, $monitor->mqtt_password, $timeoutMs,
                    );
                if ($this->persistSafely($monitor, $outcome)) $executed++;
            }
            if (! $budget->canClaimMore()) $stopped = true;
        }

        return $stopped;
    }

    /**
     * HTTP monitors with rolling concurrency (#221): up to CHECK_CONCURRENCY
     * requests stay in flight, and a new one starts as soon as one finishes,
     * so a slow target holds one slot instead of a whole wave.
     *
     * Monitors are claimed in small batches as slots free up, with the same
     * rules as waves (SKIP LOCKED, next_check_at order, timeout must fit the
     * remaining budget). A result that only records another "up" is persisted
     * at once. Any other result can open or close an incident, send
     * notifications or ask a second location, which can take seconds, so it is
     * held until no request is in flight and persisted then; the stream then
     * resumes. Returns true when it stopped on budget.
     *
     * @param  list<int>  $claimedIds
     */
    private function runRolling(TickBudget $budget, SchedulerLimits $limits, int $reserve, ?int $onlyTenantId, array &$claimedIds, int &$claimed, int &$executed): bool
    {
        $batchSize = max(1, (int) ceil($limits->concurrency / 5));
        /** @var list<MultiPinnedHttpRequest> $queue */
        $queue = [];
        /** @var array<int, Monitor> $monitors */
        $monitors = [];
        /** @var list<array{0: Monitor, 1: CheckOutcome}> $held */
        $held = [];
        $exhausted = false;
        $stopped = false;

        $next = function () use (&$queue, &$monitors, &$held, &$exhausted, &$stopped, &$claimedIds, &$claimed, &$executed, $budget, $reserve, $onlyTenantId, $batchSize): ?MultiPinnedHttpRequest {
            if ($held !== []) {
                return null; // drain, then persist the held results
            }
            while ($queue === []) {
                if ($exhausted) {
                    return null;
                }
                $maxTimeoutMs = (int) floor(($budget->remainingSeconds() - $reserve) * 1000);
                if (! $budget->canClaimMore() || $maxTimeoutMs <= 0) {
                    $exhausted = true;
                    $stopped = true;

                    return null;
                }
                $batch = $this->claimer->claimDueMonitors(
                    limit: $batchSize,
                    excludedMonitorIds: $claimedIds,
                    maxTimeoutMs: $maxTimeoutMs,
                    tenantId: $onlyTenantId,
                    kinds: [MonitorKind::HTTP->value],
                );
                if ($batch->isEmpty()) {
                    $exhausted = true;

                    return null;
                }
                $claimed += $batch->count();
                $claimedIds = [...$claimedIds, ...$batch->pluck('id')->all()];
                foreach ($batch as $monitor) {
                    $prepared = $this->executor->prepareHttp($monitor);
                    if ($prepared instanceof CheckOutcome) {
                        $held[] = [$monitor, $prepared];
                        continue;
                    }
                    $monitors[$monitor->id] = $monitor;
                    $queue[] = new MultiPinnedHttpRequest($monitor->id, $prepared, $monitor->check_region);
                }
                if ($held !== []) {
                    return null;
                }
            }

            return array_shift($queue);
        };

        $onResult = function (MultiPinnedHttpResult $result) use (&$monitors, &$held, &$executed): void {
            $monitor = $monitors[$result->monitorId] ?? null;
            if ($monitor === null) {
                return;
            }
            unset($monitors[$result->monitorId]);
            $outcome = $this->executor->outcomeFromHttpResponse($result->response, $result->latencyMs, $result->location);
            if ($this->executor->isRoutineUp($monitor, $outcome)) {
                if ($this->persistSafely($monitor, $outcome)) {
                    $executed++;
                }

                return;
            }
            $held[] = [$monitor, $outcome];
        };

        do {
            $this->httpProbe->stream($next, $onResult, $limits->concurrency);
            foreach ($held as [$monitor, $outcome]) {
                if ($this->persistSafely($monitor, $outcome)) {
                    $executed++;
                }
            }
            $held = [];
        } while (! $exhausted || $queue !== []);

        return $stopped;
    }

    /**
     * IncidentService::record() clears claim_token as part of its own
     * DB transaction — if anything inside that transaction throws (a
     * notification dispatcher bug, a DB constraint, ...), the whole
     * transaction rolls back and the claim is never released. Without
     * this guard the monitor stays claimed until claim_expires_at
     * (minutes), unable to be re-checked in the meantime. Catch here,
     * log it, and release the claim directly so the next tick can retry.
     */
    private function persistSafely(Monitor $monitor, CheckOutcome $outcome): bool
    {
        try {
            return $this->executor->persist($monitor, $outcome) !== null;
        } catch (\Throwable $e) {
            Log::error('Monitor check persist failed; releasing claim for retry', [
                'monitor_id' => $monitor->id,
                'exception' => $e->getMessage(),
            ]);

            Monitor::query()
                ->where('id', $monitor->id)
                ->where('claim_token', $monitor->claim_token)
                ->update(['claim_token' => null, 'claimed_at' => null, 'claim_expires_at' => null]);

            return false;
        }
    }
}
