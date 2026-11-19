<?php

namespace App\Application\Scheduling;

use App\Domain\Monitoring\MonitorKind;
use App\Infrastructure\Persistence\Eloquent\Monitor;

/**
 * How many network checks per minute the enabled monitors ask for, against
 * what one tick was measured to handle (#214, docs/deployment/capacity.md).
 *
 * Heartbeat and agent monitors are left out: they make no network request and
 * never take a concurrency slot.
 */
final class CheckerLoad
{
    public function demandPerMinute(): float
    {
        $sum = Monitor::query()
            ->where('enabled', true)
            ->whereNotIn('kind', [MonitorKind::HEARTBEAT->value, MonitorKind::AGENT->value])
            ->where('interval_seconds', '>', 0)
            ->selectRaw('SUM(60.0 / interval_seconds) AS per_minute')
            ->value('per_minute');

        return round((float) $sum, 1);
    }

    /** Checks per tick measured with monitor:capacity-test, or null when not measured. */
    public function capacityPerMinute(): ?int
    {
        $value = config('scheduler.capacity_per_minute');

        return $value === null || $value === '' || (int) $value <= 0 ? null : (int) $value;
    }

    /**
     * @return array{demand_per_minute:float, capacity_per_minute:?int, utilization:?float}
     */
    public function summary(): array
    {
        $demand = $this->demandPerMinute();
        $capacity = $this->capacityPerMinute();

        return [
            'demand_per_minute' => $demand,
            'capacity_per_minute' => $capacity,
            'utilization' => $capacity === null ? null : round($demand / $capacity, 3),
        ];
    }
}
