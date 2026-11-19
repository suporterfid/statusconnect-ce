<?php

namespace App\Console\Commands;

use App\Application\Extensions\MaintenanceTasks;
use App\Application\StatusPages\Subscriptions\SubscriberNotifier;
use App\Application\Scheduling\HeartbeatWriter;
use App\Application\Scheduling\StaleClaimRecovery;
use App\Application\Rollups\RollupService;
use App\Infrastructure\Persistence\Eloquent\AgentMetricRepository;
use Illuminate\Console\Command;

// Mirrors taskconnect app/Console/Commands/MaintenanceCommand.php.
final class MonitorMaintenanceCommand extends Command
{
    protected $signature = 'monitor:maintenance';

    protected $description = 'Recover stale monitor claims';

    public function handle(StaleClaimRecovery $recovery, HeartbeatWriter $heartbeatWriter, RollupService $rollups, AgentMetricRepository $agentMetrics, SubscriberNotifier $subscribers, MaintenanceTasks $tasks): int
    {
        $recovered = $recovery->recover();
        $deleted = $rollups->deleteCoveredRawResults();
        $staleMetrics = $agentMetrics->deleteStale((int) config('agent.retention_days', 30), (int) config('retention.delete_chunk_size', 500));
        // Status page email subscriptions (#92).
        $subscriberStats = $subscribers->run();
        // Work other parts of the application register for the minute cron (#250),
        // such as billing, trials and the sign-up digest of the enterprise edition.
        $taskStats = $tasks->run();
        $heartbeatWriter->record('maintenance', ['stale_claims_recovered' => $recovered, 'raw_results_deleted' => $deleted, 'agent_metrics_deleted' => $staleMetrics] + $subscriberStats + $taskStats);

        $this->info(sprintf('Recovered %d stale monitor claim(s).', $recovered));

        return self::SUCCESS;
    }
}
