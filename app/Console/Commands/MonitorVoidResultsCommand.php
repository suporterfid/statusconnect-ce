<?php

namespace App\Console\Commands;

use App\Application\Rollups\RollupService;
use App\Application\StatusPages\StatusPageCacheWriter;
use App\Application\Tenancy\AuditRecorder;
use App\Infrastructure\Persistence\Eloquent\CheckResult;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Voids failures the checker measured wrongly (#233), for platform admins.
 *
 * A voided result is kept as evidence (state, reason, latency stay as
 * measured) but no longer counts toward availability, downtime or latency:
 * rollups, the status page and the dashboard skip it. Only non-up results are
 * voided, and results inside an incident of the same monitor need --force.
 *
 * Raw results live for retention.check_results_days; once a day's raw results
 * are gone its rollup can no longer be corrected.
 */
final class MonitorVoidResultsCommand extends Command
{
    protected $signature = 'monitor:void-results
        {--monitor= : Monitor ids, comma separated}
        {--from= : Start of the window, UTC (YYYY-MM-DD HH:MM:SS)}
        {--to= : End of the window, UTC, inclusive}
        {--reason= : Why these results are voided (kept with each result and in the audit log)}
        {--result= : Only these result ids, comma separated}
        {--dry-run : List what would be voided and change nothing}
        {--force : Also void results inside an incident of the same monitor}';

    protected $description = 'Exclude failures the checker measured wrongly from availability, keeping them as evidence';

    public function handle(RollupService $rollups, StatusPageCacheWriter $statusPages, AuditRecorder $audit): int
    {
        $monitorIds = $this->ids('monitor');
        $resultIds = $this->ids('result');
        $reason = trim((string) $this->option('reason'));
        try {
            $from = CarbonImmutable::parse((string) $this->option('from'), 'UTC');
            $to = CarbonImmutable::parse((string) $this->option('to'), 'UTC');
        } catch (\Throwable) {
            $from = $to = null;
        }
        if ($monitorIds === [] || $from === null || $to === null || $this->option('from') === null || $this->option('to') === null || $reason === '') {
            $this->error('--monitor, --from, --to and --reason are required.');

            return self::INVALID;
        }
        if ($to->lessThan($from) || $from->diffInDays($to) > 31) {
            $this->error('The window must run forward and span at most 31 days.');

            return self::INVALID;
        }

        $results = CheckResult::query()
            ->whereIn('monitor_id', $monitorIds)
            ->whereBetween('checked_at', [$from, $to])
            ->where('state', '!=', 'up')
            ->whereNull('voided_at')
            ->when($resultIds !== [], fn ($q) => $q->whereIn('id', $resultIds))
            ->orderBy('checked_at')
            ->get();

        $inIncident = $results->filter(fn (CheckResult $r): bool => DB::table('incidents')
            ->where('monitor_id', $r->monitor_id)
            ->where('started_at', '<=', $r->checked_at)
            ->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('resolved_at', '>=', $r->checked_at))
            ->exists());

        $rows = $results->map(fn (CheckResult $r): array => [
            $r->id, $r->monitor_id, $r->checked_at?->format('Y-m-d H:i:s'), $r->state->value, $r->latency_ms,
            mb_substr((string) $r->failure_reason, 0, 60), $inIncident->has($r->getKey()) ? 'yes' : '',
        ])->all();
        $this->table(['id', 'monitor', 'checked_at (UTC)', 'state', 'latency_ms', 'reason', 'in incident'], $rows);

        if ($results->isEmpty()) {
            $this->info('No non-up result to void in that window.');

            return self::SUCCESS;
        }
        if ($inIncident->isNotEmpty() && ! $this->option('force')) {
            $this->error(sprintf('%d result(s) fall inside an incident of their monitor. Narrow the window or --result, or pass --force.', $inIncident->count()));

            return self::FAILURE;
        }
        if ($this->option('dry-run')) {
            $this->info(sprintf('Dry run: %d result(s) would be voided.', $results->count()));

            return self::SUCCESS;
        }

        $now = now();
        DB::transaction(function () use ($results, $reason, $now, $audit, $from, $to): void {
            CheckResult::query()->whereIn('id', $results->modelKeys())->update(['voided_at' => $now, 'void_reason' => mb_substr($reason, 0, 255)]);

            foreach ($results->groupBy('monitor_id') as $monitorId => $voided) {
                $monitor = Monitor::query()->with('tenant')->find($monitorId);
                $audit->record(null, 'check_results.voided', 'monitor', $monitor?->public_id, $monitor?->tenant, [
                    'reason' => mb_substr($reason, 0, 255),
                    'window' => [$from->toDateTimeString(), $to->toDateTimeString()],
                    'result_ids' => $voided->modelKeys(),
                    'source' => 'artisan monitor:void-results',
                ]);
            }
        });

        $buckets = $rollups->rollupClosedBuckets();
        // Snapshots refresh only when a monitor or component changed; history did.
        DB::table('status_page_cache')->update(['generated_at' => '2000-01-01 00:00:00']);
        $pages = $statusPages->refreshStalePages();
        $this->info(sprintf('Voided %d result(s); rolled up %d bucket(s); refreshed %d status page(s).', $results->count(), $buckets, $pages));

        return self::SUCCESS;
    }

    /** @return list<int> */
    private function ids(string $option): array
    {
        return array_values(array_filter(array_map('intval', explode(',', (string) $this->option($option))), fn (int $id): bool => $id > 0));
    }
}
