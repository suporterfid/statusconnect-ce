<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\CheckState;
use App\Domain\Monitoring\EvaluationResult;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use DateTimeImmutable;

final class HeartbeatMonitorEvaluator
{
    public function evaluate(Monitor $monitor, DateTimeImmutable $checkedAt): EvaluationResult
    {
        $interval = (int) ($monitor->interval_seconds ?? 60);
        $grace = (int) ($monitor->heartbeat_grace_seconds ?? 60);
        $maxAgeSeconds = max(1, $interval + $grace);

        $lastPingAt = $monitor->last_ping_at?->toDateTimeImmutable();

        if ($lastPingAt === null) {
            return new EvaluationResult(
                state: CheckState::DOWN,
                reason: 'Heartbeat never received',
            );
        }

        $ageSeconds = $checkedAt->getTimestamp() - $lastPingAt->getTimestamp();

        if ($ageSeconds > $maxAgeSeconds) {
            return new EvaluationResult(
                state: CheckState::DOWN,
                reason: sprintf('Heartbeat missed: last ping was %ds ago (grace limit %ds)', $ageSeconds, $maxAgeSeconds),
            );
        }

        return new EvaluationResult(
            state: CheckState::UP,
            reason: null,
        );
    }
}
