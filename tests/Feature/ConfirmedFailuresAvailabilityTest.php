<?php

namespace Tests\Feature;

use App\Application\Incidents\IncidentService;
use App\Domain\Monitoring\CheckOutcome;
use App\Domain\Monitoring\CheckState;
use App\Domain\Monitoring\EvaluationResult;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Availability counts only confirmed failures (#237). */
class ConfirmedFailuresAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Monitor $monitor;

    private CarbonImmutable $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->day = CarbonImmutable::now('UTC')->subDays(2)->startOfDay();
        $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $environment = Environment::query()->create(['tenant_id' => $tenant->id, 'name' => 'Production', 'slug' => 'production', 'is_default' => true]);
        $this->monitor = Monitor::query()->create([
            'tenant_id' => $tenant->id, 'environment_id' => $environment->id, 'name' => 'Acme MQTT',
            'kind' => 'tcp', 'target' => 'broker.example:8883', 'interval_seconds' => 60,
            'confirmation_threshold' => 3, 'recovery_threshold' => 2, 'current_state' => 'up',
        ]);
        $pageId = DB::table('status_pages')->insertGetId([
            'tenant_id' => $tenant->id, 'environment_id' => $environment->id, 'slug' => 'acme-status', 'title' => 'Acme Status',
            'visibility' => 'public', 'locale' => 'en', 'timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $componentId = DB::table('status_page_components')->insertGetId(['status_page_id' => $pageId, 'name' => 'Customer portal', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('status_page_component_monitors')->insert(['status_page_component_id' => $componentId, 'monitor_id' => $this->monitor->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_an_isolated_failure_below_the_threshold_leaves_the_day_green(): void
    {
        $this->record(CheckState::UP, '10:00', 21);
        $blip = $this->record(CheckState::DOWN, '10:01', 16229);
        $this->record(CheckState::UP, '10:02', 21);

        $evidence = DB::table('check_results')->where('id', $blip)->first();
        $this->assertSame('down', $evidence->state, 'the result keeps its measured state');
        $this->assertFalse((bool) $evidence->failure_confirmed);
        $this->assertSame(0, DB::table('incidents')->count());

        $day = $this->rollup('day', $this->day);
        $this->assertSame([3, 0, 0, 0], [(int) $day->up_count, (int) $day->down_count, (int) $day->degraded_count, (int) $day->downtime_seconds]);
        $this->assertSame(16229, (int) $day->latency_max_ms, 'its latency still counts');

        $entry = $this->historyFor($this->day);
        $this->assertSame('operational', $entry['state']);
        $this->assertEquals(100.0, $entry['uptime']);
        $this->assertSame(0, $entry['downtime_seconds']);
    }

    public function test_a_confirmed_streak_counts_from_its_first_failure(): void
    {
        $this->record(CheckState::UP, '10:00');
        $ids = [$this->record(CheckState::DOWN, '10:01'), $this->record(CheckState::DOWN, '10:02'), $this->record(CheckState::DOWN, '10:03')];
        $this->record(CheckState::UP, '10:04');
        $this->record(CheckState::UP, '10:05');

        $this->assertSame([true, true, true], DB::table('check_results')->whereIn('id', $ids)->orderBy('id')->pluck('failure_confirmed')->map(fn ($v) => (bool) $v)->all());
        $this->assertSame(1, DB::table('incidents')->count());

        $day = $this->rollup('day', $this->day);
        $this->assertSame([3, 3, 180], [(int) $day->up_count, (int) $day->down_count, (int) $day->downtime_seconds]);
        $this->assertSame('major_outage', $this->historyFor($this->day)['state']);
    }

    public function test_a_failure_while_the_outage_is_still_open_counts(): void
    {
        foreach (['10:01', '10:02', '10:03'] as $time) {
            $this->record(CheckState::DOWN, $time);
        }
        $this->record(CheckState::UP, '10:04');
        $relapse = $this->record(CheckState::DOWN, '10:05');

        $this->assertTrue((bool) DB::table('check_results')->where('id', $relapse)->value('failure_confirmed'), 'the monitor never left down');
        $this->assertSame(4, (int) $this->rollup('day', $this->day)->down_count);
    }

    public function test_a_degraded_streak_works_the_same(): void
    {
        $this->record(CheckState::DEGRADED, '10:01');
        $this->record(CheckState::UP, '10:02');
        $this->assertSame([2, 0], $this->counts('degraded'));
        $this->assertSame('operational', $this->historyFor($this->day)['state'], 'one degraded check is not a degraded day');

        foreach (['11:01', '11:02', '11:03'] as $time) {
            $this->record(CheckState::DEGRADED, $time);
        }
        $this->assertSame([2, 3], $this->counts('degraded'));
        $this->assertSame('degraded', $this->historyFor($this->day)['state']);
    }

    public function test_a_streak_across_an_hour_boundary_counts_in_both_hours_once_confirmed(): void
    {
        $this->record(CheckState::DOWN, '10:59');
        $this->record(CheckState::DOWN, '11:00');
        $this->assertSame(0, (int) $this->rollup('hour', $this->day->setTime(10, 0))->down_count, 'still below the threshold');

        $this->record(CheckState::DOWN, '11:01');
        $this->assertSame(1, (int) $this->rollup('hour', $this->day->setTime(10, 0))->down_count, 'the closed hour is rewritten');
        $this->assertSame(2, (int) $this->rollup('hour', $this->day->setTime(11, 0))->down_count);
    }

    public function test_voided_results_never_count(): void
    {
        $ids = [$this->record(CheckState::DOWN, '10:01'), $this->record(CheckState::DOWN, '10:02'), $this->record(CheckState::DOWN, '10:03')];
        DB::table('check_results')->whereIn('id', $ids)->update(['voided_at' => now(), 'void_reason' => 'test']);

        $day = $this->rollup('day', $this->day);
        $this->assertSame([0, 0], [(int) $day->down_count, (int) $day->checks_total]);
    }

    public function test_a_streak_in_progress_today_counts_as_up_until_confirmed(): void
    {
        $today = CarbonImmutable::now('UTC');
        $this->recordAt(CheckState::UP, $today->subMinutes(2));
        $this->recordAt(CheckState::DOWN, $today->subMinute());

        Artisan::call('monitor:rollup');
        $payload = $this->payload();
        $this->assertEquals(100.0, $payload['components'][0]['uptime']['24h']);
        $this->assertSame('operational', collect($payload['components'][0]['history'])->last()['state']);
    }

    public function test_the_old_behavior_can_be_restored(): void
    {
        config(['status_page.count_unconfirmed_failures' => true]);
        $this->record(CheckState::DOWN, '10:01');
        $this->record(CheckState::UP, '10:02');

        $this->assertSame(1, (int) $this->rollup('day', $this->day)->down_count);
    }

    public function test_the_migration_backfills_kept_results_from_incident_windows(): void
    {
        $insidePast = $this->record(CheckState::DOWN, '10:01');
        $this->record(CheckState::DOWN, '10:02');
        $this->record(CheckState::DOWN, '10:03');
        $this->record(CheckState::UP, '10:04');
        $this->record(CheckState::UP, '10:05');
        $blip = $this->record(CheckState::DOWN, '12:00');
        $up = $this->record(CheckState::UP, '12:01');

        $migration = require database_path('migrations/2026_11_17_160000_add_failure_confirmed_to_check_results.php');
        $migration->down();
        $migration->up();

        $values = DB::table('check_results')->whereIn('id', [$insidePast, $blip, $up])->orderBy('id')->pluck('failure_confirmed')->all();
        $this->assertSame([true, false, null], array_map(static fn ($v) => $v === null ? null : (bool) $v, $values));
    }

    private function record(CheckState $state, string $time, int $latency = 12): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $this->recordAt($state, $this->day->setTime($h, $m), $latency);
    }

    private function recordAt(CheckState $state, CarbonImmutable $at, int $latency = 12): int
    {
        $record = app(IncidentService::class)->record(
            $this->monitor->fresh(),
            new CheckOutcome(statusCode: $state === CheckState::UP ? 200 : 0, latencyMs: $latency),
            new EvaluationResult($state, $state === CheckState::UP ? null : 'Transport failure: tcp_timeout'),
            new DateTimeImmutable($at->toIso8601String()),
        );

        return (int) $record->checkResult->id;
    }

    private function rollup(string $kind, CarbonImmutable $start): object
    {
        Artisan::call('monitor:rollup');

        return DB::table('check_rollups')->where(['monitor_id' => $this->monitor->id, 'bucket_kind' => $kind])
            ->where('bucket_start', $start->format('Y-m-d H:i:s'))->sole();
    }

    /** @return array{0: int, 1: int} up and $state counts of the day */
    private function counts(string $state): array
    {
        $day = $this->rollup('day', $this->day);

        return [(int) $day->up_count, (int) $day->{$state.'_count'}];
    }

    /** @return array<string, mixed> */
    private function historyFor(CarbonImmutable $day): array
    {
        Artisan::call('monitor:rollup');

        return collect($this->payload()['components'][0]['history'])->firstWhere('date', $day->format('Y-m-d'));
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        DB::table('status_page_cache')->delete();
        Artisan::call('monitor:rollup');

        return json_decode((string) DB::table('status_page_cache')->value('payload_json'), true, 512, JSON_THROW_ON_ERROR);
    }
}
