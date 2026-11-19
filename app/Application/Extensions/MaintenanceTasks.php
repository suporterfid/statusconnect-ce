<?php

namespace App\Application\Extensions;

use Closure;

/**
 * Extra work for the minute `monitor:maintenance` cron (#250).
 *
 * There is no daemon or Laravel scheduler: the minute cron runs three artisan
 * commands. Anything else that must run on a schedule registers here, and
 * monitor:maintenance runs it after its own work. Each task returns counters
 * that go into the `maintenance` heartbeat.
 */
final class MaintenanceTasks
{
    /** @var list<Closure(): array<string, mixed>> */
    private array $tasks = [];

    /** @param  Closure(): array<string, mixed>  $task */
    public function add(Closure $task): void
    {
        $this->tasks[] = $task;
    }

    /** @return array<string, mixed> the counters of every task */
    public function run(): array
    {
        $stats = [];
        foreach ($this->tasks as $task) {
            $stats += (array) $task();
        }

        return $stats;
    }
}
