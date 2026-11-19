<?php

namespace App\Console\Commands;

use App\Application\Scheduling\ParallelMonitorCheckRunner;
use Illuminate\Console\Command;

final class MonitorCheckDueCommand extends Command
{
    protected $signature = 'monitor:check-due';

    protected $description = 'Claim and execute due monitor checks within the tick budget';

    public function handle(ParallelMonitorCheckRunner $runner): int
    {
        $stats = $runner->run();

        $this->info(sprintf(
            'Claimed %d due monitor(s); executed %d in %d ms%s.',
            $stats['claimed'],
            $stats['executed'],
            $stats['duration_ms'],
            $stats['budget_stopped'] ? sprintf('; stopped on wall-clock budget, %d due monitor(s) left for the next tick', $stats['deferred']) : '',
        ));

        return self::SUCCESS;
    }
}
