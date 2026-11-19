<?php

namespace App\Domain\Maintenance\Services;

use App\Infrastructure\Persistence\Eloquent\MaintenanceWindow;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use DateTimeImmutable;

final class MaintenanceWindowEvaluator
{
    public function isInMaintenance(Monitor $monitor, DateTimeImmutable $at): bool
    {
        $windows = MaintenanceWindow::query()
            ->whereHas('monitors', function ($query) use ($monitor) {
                $query->where('monitors.id', $monitor->id);
            })
            ->get();

        foreach ($windows as $window) {
            if ($this->isWindowActive($window, $at)) {
                return true;
            }
        }

        return false;
    }

    public function isWindowActive(MaintenanceWindow $window, DateTimeImmutable $at): bool
    {
        $startsAt = $window->starts_at->toDateTimeImmutable();
        $endsAt = $window->ends_at->toDateTimeImmutable();

        // 1. One-off time window check
        if ($at >= $startsAt && $at <= $endsAt) {
            return true;
        }

        // 2. Weekly recurring window check (§10.4)
        if ($window->recurring_weekly) {
            $durationSeconds = $endsAt->getTimestamp() - $startsAt->getTimestamp();
            $dayOfWeekStart = (int) $startsAt->format('N'); // 1 (Mon) to 7 (Sun)
            $dayOfWeekAt = (int) $at->format('N');

            if ($dayOfWeekStart === $dayOfWeekAt) {
                $timeOfDayAt = (int) $at->format('H') * 3600 + (int) $at->format('i') * 60 + (int) $at->format('s');
                $timeOfDayStart = (int) $startsAt->format('H') * 3600 + (int) $startsAt->format('i') * 60 + (int) $startsAt->format('s');

                if ($timeOfDayAt >= $timeOfDayStart && $timeOfDayAt <= ($timeOfDayStart + $durationSeconds)) {
                    return true;
                }
            }
        }

        return false;
    }
}
