<?php

namespace App\Console\Commands;

use App\Application\Scheduling\ParallelMonitorCheckRunner;
use App\Application\Scheduling\TickBudget;
use App\Domain\Outbound\EgressProfile;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\SystemHeartbeat;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Measures how many monitors one checker tick handles on this host (#214).
 *
 * It creates a throwaway organization with N HTTP monitors pointed at
 * --target, runs one real tick of the checker limited to them, prints the
 * result and rolls everything back. Nothing is committed: no monitor, check
 * result or heartbeat survives, and real monitors are never claimed.
 *
 * The test holds a database transaction for up to the tick budget. So that it
 * never overlaps the real minute cron, it starts a few seconds after the
 * checker's tick of the current minute has finished and stops before the next
 * minute begins.
 */
final class MonitorCapacityTestCommand extends Command
{
    protected $signature = 'monitor:capacity-test
        {--target= : URL every synthetic monitor checks (a public URL in production)}
        {--monitors=200 : Number of synthetic monitors due in the tick (1-2000)}
        {--concurrency= : CHECK_CONCURRENCY for this run (default: the configured one)}
        {--timeout-ms=10000 : Timeout of each synthetic monitor}
        {--egress=public-crawl : Egress profile of the synthetic monitors}
        {--no-wait : Start at once instead of waiting for a safe second of the minute}
        {--force : Required when APP_ENV is production}';

    protected $description = 'Measure how many monitors one checker tick handles here, then roll everything back';

    /** The test starts no earlier than this second of the minute... */
    private const START_SECOND = 5;

    /** ...and no later than this one, so it ends before the next cron tick. */
    private const LATEST_START_SECOND = 10;

    /** Seconds before the next minute by which the test must have finished. */
    private const END_MARGIN_SECONDS = 4;

    public function handle(ParallelMonitorCheckRunner $runner): int
    {
        $target = trim((string) $this->option('target'));
        $count = (int) $this->option('monitors');
        $timeoutMs = (int) $this->option('timeout-ms');
        $egress = EgressProfile::tryFrom((string) $this->option('egress'));

        if (filter_var($target, FILTER_VALIDATE_URL) === false || ! preg_match('#^https?://#i', $target)) {
            $this->error('--target must be an http(s) URL.');

            return self::INVALID;
        }
        if ($count < 1 || $count > 2000) {
            $this->error('--monitors must be between 1 and 2000.');

            return self::INVALID;
        }
        if ($timeoutMs < 100 || $timeoutMs > 30000) {
            $this->error('--timeout-ms must be between 100 and 30000.');

            return self::INVALID;
        }
        if ($egress === null) {
            $this->error('--egress must be one of: '.implode(', ', array_column(EgressProfile::cases(), 'value')).'.');

            return self::INVALID;
        }
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('This runs a real load test from this server. Pass --force to run it in production.');

            return self::INVALID;
        }
        if ($this->option('concurrency') !== null) {
            config(['scheduler.check_concurrency' => (int) $this->option('concurrency'), 'scheduler.check_wave_size' => null]);
        }

        $limitSeconds = (float) config('scheduler.target_duration_seconds', 45);
        if (! $this->option('no-wait')) {
            $startSecond = $this->waitForSafeStart();
            if ($startSecond === null) {
                $this->error('The checker did not finish its tick in time for two minutes in a row. Try again later.');

                return self::FAILURE;
            }
            $limitSeconds = min($limitSeconds, 60 - $startSecond - self::END_MARGIN_SECONDS);
        }

        $concurrency = \App\Application\Scheduling\SchedulerLimits::fromConfig()->concurrency;
        $this->line(sprintf('Testing %d monitor(s) against %s, concurrency %d, timeout %d ms, budget %.0f s.', $count, $target, $concurrency, $timeoutMs, $limitSeconds));

        DB::beginTransaction();
        try {
            $tenant = $this->syntheticTenant($count, $target, $timeoutMs, $egress);
            $stats = $runner->run(new TickBudget(microtime(true), $limitSeconds), $tenant->id);
            $checks = DB::table('check_results')->whereIn('monitor_id', Monitor::query()->where('tenant_id', $tenant->id)->select('id'));
            $up = (clone $checks)->where('state', 'up')->count();
            $latency = (clone $checks)->avg('latency_ms');
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('The test failed and was rolled back: '.$e->getMessage());

            return self::FAILURE;
        }
        DB::rollBack();

        $this->table(['Due', 'Executed', 'Up', 'Deferred', 'Tick duration', 'Avg latency', 'Concurrency'], [[
            $count,
            $stats['executed'],
            $up,
            $stats['deferred'],
            number_format($stats['duration_ms'] / 1000, 1).' s',
            $latency === null ? '-' : round((float) $latency).' ms',
            $concurrency,
        ]]);

        if ($stats['deferred'] > 0) {
            $this->warn(sprintf('Over capacity: %d monitor(s) would wait for the next tick. One tick handles about %d here.', $stats['deferred'], $stats['executed']));
        } else {
            $this->info(sprintf('Every due monitor fit in one tick (%.1f s of %.0f s). Raise --monitors to find the ceiling.', $stats['duration_ms'] / 1000, $limitSeconds));
        }
        if ($up < $stats['executed']) {
            $this->warn(sprintf('%d check(s) were not up: the target or the network failed, so the figures are not a clean measurement.', $stats['executed'] - $up));
        }
        $this->line('Everything was rolled back.');

        return self::SUCCESS;
    }

    private function syntheticTenant(int $count, string $target, int $timeoutMs, EgressProfile $egress): Tenant
    {
        $suffix = Str::lower(Str::random(8));
        $tenant = Tenant::query()->create(['name' => 'Capacity test '.$suffix, 'slug' => 'capacity-test-'.$suffix]);
        $environment = Environment::query()->create(['tenant_id' => $tenant->id, 'name' => 'Capacity test', 'slug' => 'capacity-test']);
        $due = now()->subSecond();

        for ($i = 1; $i <= $count; $i++) {
            Monitor::query()->create([
                'tenant_id' => $tenant->id,
                'environment_id' => $environment->id,
                'name' => 'Capacity test '.$i,
                'kind' => 'http',
                'target' => $target,
                'interval_seconds' => 60,
                'timeout_ms' => $timeoutMs,
                'egress_profile' => $egress->value,
                // Never opens an incident, so no notification or second-location request.
                'confirmation_threshold' => 1000,
                'recovery_threshold' => 1,
                'enabled' => true,
                // Already up, like a monitor in steady state: its results are
                // persisted inline instead of waiting for the stream to drain (#221).
                'current_state' => 'up',
                'next_check_at' => $due,
            ]);
        }

        return $tenant;
    }

    /**
     * Waits until this minute's tick has written its heartbeat and the clock is
     * between START_SECOND and LATEST_START_SECOND. Returns the second it
     * started at, or null after two minutes without a safe window.
     */
    private function waitForSafeStart(): ?int
    {
        $deadline = time() + 125;
        $this->line('Waiting for the checker to finish this minute\'s tick...');
        while (time() < $deadline) {
            $now = time();
            $second = (int) date('s', $now);
            $minuteStart = $now - $second;
            $lastTick = SystemHeartbeat::query()->where('name', 'checker')->first()?->last_seen_at;
            $tickDone = $lastTick !== null && $lastTick->getTimestamp() >= $minuteStart;

            if ($second >= self::START_SECOND && $second <= self::LATEST_START_SECOND && $tickDone) {
                return $second;
            }
            usleep(250_000);
        }

        return null;
    }
}
