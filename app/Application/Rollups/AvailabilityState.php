<?php

namespace App\Application\Rollups;

/**
 * The state a check result counts as for availability, downtime and the
 * status page day color (#237).
 *
 * A down/degraded result counts only when its failure streak was confirmed
 * (it reached the monitor's confirmation threshold). An unconfirmed failure,
 * a blip that recovered or a streak still below the threshold, counts as up;
 * the raw result keeps its state as evidence and its latency still counts.
 * Results recorded before this rule (failure_confirmed null) count as recorded.
 *
 * STATUS_COUNT_UNCONFIRMED_FAILURES=true restores the old behavior.
 */
final class AvailabilityState
{
    public static function of(string $state, mixed $failureConfirmed): string
    {
        if (! in_array($state, ['down', 'degraded'], true)
            || $failureConfirmed === null
            || (bool) config('status_page.count_unconfirmed_failures', false)) {
            return $state;
        }

        return (bool) $failureConfirmed ? $state : 'up';
    }
}
