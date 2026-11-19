<?php

namespace App\Domain\Monitoring\Agent;

use App\Domain\Monitoring\CheckState;
use App\Domain\Monitoring\EvaluationResult;
use DateTimeImmutable;

/**
 * Turns the latest metric values of an `agent` monitor into a check state
 * (agent-ingestion addendum §5.3). Pure: no I/O, no framework.
 *
 * Decision order:
 *  1. inside a maintenance window   => skipped
 *  2. no ingestion within interval + grace, or never => down ("no_data")
 *  3. a `down` rule fires (or its metric is missing with policy `fail`) => down
 *  4. a `degraded` rule fires        => degraded
 *  5. otherwise                      => up
 *
 * "Missing" means the metric was not part of the latest ingestion: its
 * sampledAt is older than lastIngestAt, or it was never received.
 */
final class AgentMonitorEvaluator
{
    /** Upper bound of a latency taken from a metric: one day, in ms. */
    public const MAX_LATENCY_MS = 86_400_000;

    /**
     * @param  array<string, array{value: float, received_at: DateTimeImmutable}>  $latest  keyed by metric key
     * @param  list<MetricThresholdRule>  $rules
     */
    public function evaluate(
        array $latest,
        array $rules,
        ?DateTimeImmutable $lastIngestAt,
        DateTimeImmutable $now,
        int $intervalSeconds,
        int $graceSeconds,
        bool $inMaintenance = false,
    ): EvaluationResult {
        if ($inMaintenance) {
            return new EvaluationResult(CheckState::SKIPPED, 'Maintenance window');
        }

        if ($lastIngestAt === null) {
            return new EvaluationResult(CheckState::DOWN, 'no_data: no metrics received yet');
        }

        $age = $now->getTimestamp() - $lastIngestAt->getTimestamp();
        $limit = max(1, $intervalSeconds + $graceSeconds);
        if ($age > $limit) {
            return new EvaluationResult(CheckState::DOWN, sprintf('no_data: last metrics %ds ago (limit %ds)', $age, $limit));
        }

        $downReasons = [];
        $degradedReasons = [];
        foreach ($rules as $rule) {
            $sample = $latest[$rule->metricKey] ?? null;
            $fresh = $sample !== null && $sample['received_at'] >= $lastIngestAt;

            if (! $fresh) {
                if ($rule->missingPolicy === MissingPolicy::FAIL) {
                    $reason = sprintf('%s missing', $rule->metricKey);
                    $rule->severity === RuleSeverity::DOWN ? $downReasons[] = $reason : $degradedReasons[] = $reason;
                }

                continue;
            }

            if ($rule->firesFor($sample['value'])) {
                $reason = $rule->describe($sample['value']);
                $rule->severity === RuleSeverity::DOWN ? $downReasons[] = $reason : $degradedReasons[] = $reason;
            }
        }

        if ($downReasons !== []) {
            return new EvaluationResult(CheckState::DOWN, self::join($downReasons));
        }
        if ($degradedReasons !== []) {
            return new EvaluationResult(CheckState::DEGRADED, self::join($degradedReasons));
        }

        return new EvaluationResult(CheckState::UP);
    }

    /**
     * Latency of an agent check from its latency metric (#242), in whole ms.
     *
     * Null when there is no metric key, when ingestion is stale (the no_data
     * limit), when the metric was not part of the latest ingestion (the same
     * "missing" notion as the rules), or when its value is not a positive
     * finite number. It never changes the check state.
     *
     * @param  array<string, array{value: float, received_at: DateTimeImmutable}>  $latest
     */
    public function latencyMs(
        array $latest,
        ?string $metricKey,
        ?DateTimeImmutable $lastIngestAt,
        DateTimeImmutable $now,
        int $intervalSeconds,
        int $graceSeconds,
    ): ?int {
        if ($metricKey === null || $metricKey === '' || $lastIngestAt === null) {
            return null;
        }
        if ($now->getTimestamp() - $lastIngestAt->getTimestamp() > max(1, $intervalSeconds + $graceSeconds)) {
            return null;
        }
        $sample = $latest[$metricKey] ?? null;
        if ($sample === null || $sample['received_at'] < $lastIngestAt) {
            return null;
        }
        $value = $sample['value'];
        if (! is_finite($value) || $value <= 0) {
            return null;
        }

        return (int) min(self::MAX_LATENCY_MS, max(1, round($value)));
    }

    /**
     * @param  list<string>  $reasons
     */
    private static function join(array $reasons): string
    {
        $text = implode('; ', array_slice($reasons, 0, 5));
        if (count($reasons) > 5) {
            $text .= sprintf(' (+%d more)', count($reasons) - 5);
        }

        return mb_strimwidth($text, 0, 1000, '…');
    }
}
