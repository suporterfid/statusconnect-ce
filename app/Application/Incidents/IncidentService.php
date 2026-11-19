<?php

namespace App\Application\Incidents;

use App\Domain\Incidents\IncidentAction;
use App\Domain\Incidents\FlapPolicy;
use App\Domain\Incidents\IncidentStateMachine;
use App\Domain\Incidents\MonitorSnapshot;
use App\Domain\Incidents\MonitorTransition;
use App\Domain\Monitoring\CheckOutcome;
use App\Domain\Monitoring\CheckState;
use App\Domain\Monitoring\EvaluationResult;
use App\Infrastructure\Persistence\Eloquent\CheckResult;
use App\Infrastructure\Persistence\Eloquent\Incident;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

class IncidentService
{
    public function __construct(
        private readonly IncidentStateMachine $stateMachine,
        private readonly FlapPolicy $flapPolicy = new FlapPolicy(),
        private readonly int $flapThreshold = 5,
        private readonly int $flapWindowMinutes = 60,
        private readonly ?\App\Application\Notifications\Services\NotificationDispatcherService $dispatcher = null,
    ) {
    }

    public function record(
        Monitor $monitor,
        CheckOutcome $outcome,
        EvaluationResult $evaluation,
        DateTimeImmutable $checkedAt,
        ?string $failureExcerpt = null,
        ?\Closure $confirmFromSecondLocation = null,
    ): ?IncidentRecord {
        $claimToken = $monitor->claim_token;
        $transition = $this->stateMachine->transition(
            new MonitorSnapshot(
                $monitor->current_state ?? CheckState::PENDING,
                (int) $monitor->consecutive_failures,
                (int) $monitor->consecutive_successes,
                $monitor->first_failure_at?->toDateTimeImmutable(),
            ),
            $evaluation->state,
            $checkedAt,
            (int) $monitor->confirmation_threshold,
            (int) $monitor->recovery_threshold,
        );

        $confirmation = ['state' => null, 'provider' => null, 'location' => null, 'up_until' => false];
        if ($transition->action === IncidentAction::OPEN && $confirmFromSecondLocation !== null) {
            [$transition, $evaluation, $confirmation] = $this->confirmFromSecondLocation($monitor, $transition, $evaluation, $checkedAt, $confirmFromSecondLocation);
        }

        return DB::transaction(function () use ($monitor, $outcome, $evaluation, $checkedAt, $failureExcerpt, $claimToken, $transition, $confirmation) {
            $monitorUpdate = Monitor::query()->whereKey($monitor->id);
            if ($claimToken !== null) {
                $monitorUpdate->where('claim_token', $claimToken);
            }

            $updated = $monitorUpdate->update([
                'current_state' => $transition->nextState,
                'consecutive_failures' => $transition->consecutiveFailures,
                'consecutive_successes' => $transition->consecutiveSuccesses,
                'first_failure_at' => $transition->firstFailureAt,
                'last_checked_at' => $checkedAt,
                'last_latency_ms' => $outcome->latencyMs,
                'tls_expires_at' => $outcome->tlsExpiresAt ?? $monitor->tls_expires_at,
                'claim_token' => null,
                'claimed_at' => null,
                'claim_expires_at' => null,
            ] + ($confirmation['up_until'] !== false ? ['second_location_up_until' => $confirmation['up_until']] : []));

            if ($updated === 0) {
                return null;
            }

            $result = CheckResult::query()->create([
                'tenant_id' => $monitor->tenant_id,
                'environment_id' => $monitor->environment_id,
                'monitor_id' => $monitor->id,
                'state' => $evaluation->state,
                'latency_ms' => $outcome->latencyMs,
                'status_code' => $outcome->statusCode > 0 ? $outcome->statusCode : null,
                'failure_reason' => $evaluation->reason,
                'failure_excerpt' => $failureExcerpt,
                'confirmation_state' => $confirmation['state'],
                'confirmation_provider' => $confirmation['provider'],
                'confirmation_location' => $confirmation['location'],
                'location' => $outcome->location,
                'failure_confirmed' => $this->failureConfirmed($evaluation->state, $transition->nextState),
                'checked_at' => $checkedAt,
            ]);

            // #237: the streak just reached the confirmation threshold, so its
            // earlier failures count too: the outage started at the first one.
            if ($transition->action === IncidentAction::OPEN && $transition->firstFailureAt !== null) {
                CheckResult::query()
                    ->where('monitor_id', $monitor->id)
                    ->where('failure_confirmed', false)
                    ->where('checked_at', '>=', $transition->firstFailureAt)
                    ->update(['failure_confirmed' => true]);
            }

            match ($transition->action) {
                IncidentAction::OPEN => $this->openIncident($monitor, $transition, $evaluation, $checkedAt, $confirmation['state'] === 'confirmed' ? $confirmation['location'] : null),
                IncidentAction::RESOLVE => $this->resolveAutomaticIncident($monitor, $checkedAt),
                default => null,
            };

            return new IncidentRecord($result, $this->updateFlapping($monitor, $checkedAt));
        });
    }

    /**
     * #237: null on a non-failure; on a down/degraded result, true when the
     * monitor is in a confirmed down/degraded state after it.
     */
    private function failureConfirmed(CheckState $resultState, CheckState $monitorState): ?bool
    {
        $failures = [CheckState::DOWN, CheckState::DEGRADED];
        if (! in_array($resultState, $failures, true)) {
            return null;
        }

        return in_array($monitorState, $failures, true);
    }

    private function openIncident(
        Monitor $monitor,
        MonitorTransition $transition,
        EvaluationResult $evaluation,
        DateTimeImmutable $checkedAt,
        ?string $confirmedFrom = null,
    ): Incident {
        $incident = Incident::query()->firstOrCreate(
            ['monitor_id' => $monitor->id, 'resolved_flag' => false],
            [
                'tenant_id' => $monitor->tenant_id,
                'environment_id' => $monitor->environment_id,
                'manual' => false,
                'started_at' => $transition->firstFailureAt,
                'confirmed_at' => $checkedAt,
                'severity' => $transition->nextState->value === 'degraded' ? 'minor' : 'major',
                'summary' => mb_substr(($evaluation->reason ?? 'Monitor check failed').($confirmedFrom !== null && $confirmedFrom !== '' ? ' (confirmed from '.$confirmedFrom.')' : ''), 0, 1000),
            ],
        );

        if ($incident->wasRecentlyCreated && $this->dispatcher !== null) {
            $this->dispatcher->dispatchForIncident($incident, 'opened');
        }

        return $incident;
    }

    /**
     * Second-location confirmation (#128). Runs before the transaction: it is
     * network I/O. A failure seen from elsewhere too opens the incident; a
     * target seen up from elsewhere does not, and the check is stored as up
     * with the local failure in its reason; no answer opens as before.
     *
     * @return array{0: MonitorTransition, 1: EvaluationResult, 2: array{state: ?string, provider: ?string, location: ?string, up_until: DateTimeImmutable|null|false}}
     */
    private function confirmFromSecondLocation(
        Monitor $monitor,
        MonitorTransition $transition,
        EvaluationResult $evaluation,
        DateTimeImmutable $checkedAt,
        \Closure $confirm,
    ): array {
        $cooldownUntil = $monitor->second_location_up_until?->toDateTimeImmutable();
        $cached = $cooldownUntil !== null && $cooldownUntil > $checkedAt;
        /** @var \App\Application\SecondLocation\SecondLocationVerdict|null $verdict */
        $verdict = $cached ? null : $confirm();

        if (! $cached && ($verdict === null || ! in_array($verdict->state, [CheckState::UP, CheckState::DOWN, CheckState::DEGRADED], true))) {
            return [$transition, $evaluation, ['state' => 'unavailable', 'provider' => $verdict?->provider, 'location' => $verdict?->location, 'up_until' => false]];
        }

        if (! $cached && $verdict->state !== CheckState::UP) {
            return [$transition, $evaluation, ['state' => 'confirmed', 'provider' => $verdict->provider, 'location' => $verdict->location, 'up_until' => null]];
        }

        $local = $evaluation->reason ?? 'check failed';
        $reason = $cached
            ? sprintf('Failed from this server (%s); a second location saw it up a few minutes ago.', $local)
            : sprintf('Failed from this server (%s); up from %s.', $local, $verdict->location);
        $cooldown = max(0, (int) config('second_location.cooldown_seconds', 300));

        return [
            new MonitorTransition($monitor->current_state ?? CheckState::PENDING, 0, 0, null, IncidentAction::NONE),
            new EvaluationResult(CheckState::UP, mb_substr($reason, 0, 1000)),
            [
                'state' => 'not_confirmed',
                'provider' => $cached ? null : $verdict->provider,
                'location' => $cached ? null : $verdict->location,
                'up_until' => $cached ? false : ($cooldown > 0 ? $checkedAt->modify(sprintf('+%d seconds', $cooldown)) : null),
            ],
        ];
    }

    private function resolveAutomaticIncident(Monitor $monitor, DateTimeImmutable $resolvedAt): void
    {
        $incident = $this->closeAutomaticIncident($monitor, $resolvedAt);

        if ($incident !== null && $this->dispatcher !== null) {
            $this->dispatcher->dispatchForIncident($incident, 'resolved');
        }
    }

    /**
     * Closes the monitor's open automatic incident because the monitor was
     * paused (#246), with an update saying so. Manual incidents stay open.
     * No "resolved" notification: the target did not recover. Call it inside
     * the transaction that pauses the monitor.
     */
    public function resolveForPausedMonitor(Monitor $monitor, DateTimeImmutable $pausedAt): ?Incident
    {
        $incident = $this->closeAutomaticIncident($monitor, $pausedAt);

        $incident?->updates()->create([
            'status' => 'resolved',
            'message' => __('messages.incident_resolved_monitor_paused'),
            'published_at' => $pausedAt,
        ]);

        return $incident;
    }

    private function closeAutomaticIncident(Monitor $monitor, DateTimeImmutable $resolvedAt): ?Incident
    {
        $incident = Incident::query()
            ->where('monitor_id', $monitor->id)
            ->where('manual', false)
            ->where('resolved_flag', false)
            ->lockForUpdate()
            ->first();

        if ($incident === null) {
            return null;
        }

        $incident->update([
            'resolved_at' => $resolvedAt,
            'resolved_flag' => null,
            'duration_seconds' => max(0, $resolvedAt->getTimestamp() - $incident->started_at->getTimestamp()),
        ]);

        return $incident;
    }

    private function updateFlapping(Monitor $monitor, DateTimeImmutable $checkedAt): bool
    {
        $windowStart = $checkedAt->modify(sprintf('-%d minutes', max(1, $this->flapWindowMinutes)));
        $recentCycles = Incident::query()
            ->where('monitor_id', $monitor->id)
            ->where('manual', false)
            ->whereNotNull('resolved_at')
            ->where('started_at', '>=', $windowStart)
            ->where('resolved_at', '>=', $windowStart)
            ->count();

        if (! $this->flapPolicy->evaluate($recentCycles, max(0, $this->flapThreshold))) {
            Monitor::query()->whereKey($monitor->id)->update([
                'flapping_since' => null,
                'flap_notification_window_started_at' => null,
            ]);

            return true;
        }

        $monitorState = Monitor::query()->whereKey($monitor->id)->firstOrFail();
        $windowStartedAt = $monitorState->flap_notification_window_started_at?->toDateTimeImmutable();
        if ($windowStartedAt === null || $windowStartedAt <= $windowStart) {
            $monitorState->update([
                'flapping_since' => $monitorState->flapping_since ?? $checkedAt,
                'flap_notification_window_started_at' => $checkedAt,
            ]);

            return true;
        }

        return false;
    }
}
