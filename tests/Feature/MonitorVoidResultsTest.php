<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Voiding failures the checker measured wrongly (#233). */
class MonitorVoidResultsTest extends TestCase
{
    use RefreshDatabase;

    private int $monitorId;

    private int $otherMonitorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures();
    }

    public function test_voided_failures_stay_as_evidence_but_leave_availability(): void
    {
        $day = now()->subDays(2)->startOfDay();
        $this->checkResult($this->monitorId, 'up', $day->copy()->addHours(14)->addMinutes(33), 21);
        $falseOne = $this->checkResult($this->monitorId, 'down', $day->copy()->addHours(14)->addMinutes(34)->addSeconds(20), 16229);
        $this->checkResult($this->monitorId, 'up', $day->copy()->addHours(14)->addMinutes(35), 21);
        $other = $this->checkResult($this->otherMonitorId, 'down', $day->copy()->addHours(14)->addMinutes(34)->addSeconds(20), 16229);
        $today = $this->checkResult($this->monitorId, 'down', now()->startOfDay()->addSecond(), 9000);
        $this->checkResult($this->monitorId, 'up', now()->startOfDay()->addSeconds(2), 21);

        $this->assertSame(0, Artisan::call('monitor:rollup'));
        $before = DB::table('check_rollups')->where(['monitor_id' => $this->monitorId, 'bucket_kind' => 'day'])->first();
        $this->assertSame(1, (int) $before->down_count);
        $this->assertSame(60, (int) $before->downtime_seconds);
        $this->assertSame(16229, (int) $before->latency_max_ms);

        $exit = Artisan::call('monitor:void-results', [
            '--monitor' => (string) $this->monitorId,
            '--from' => $day->toDateTimeString(),
            '--to' => now()->toDateTimeString(),
            '--reason' => 'checker measured tcp_timeout while busy (#231)',
        ]);

        $this->assertSame(0, $exit, Artisan::output());
        $voided = DB::table('check_results')->where('id', $falseOne)->first();
        $this->assertSame('down', $voided->state, 'the evidence keeps its measured state');
        $this->assertSame(16229, (int) $voided->latency_ms);
        $this->assertNotNull($voided->voided_at);
        $this->assertSame('checker measured tcp_timeout while busy (#231)', $voided->void_reason);
        $this->assertNotNull(DB::table('check_results')->where('id', $today)->value('voided_at'));
        $this->assertNull(DB::table('check_results')->where('id', $other)->value('voided_at'), 'other monitors are untouched');
        $this->assertSame(0, DB::table('check_results')->where('monitor_id', $this->monitorId)->where('state', 'up')->whereNotNull('voided_at')->count(), 'up results are never voided');

        $after = DB::table('check_rollups')->where(['monitor_id' => $this->monitorId, 'bucket_kind' => 'day'])->first();
        $this->assertSame(0, (int) $after->down_count);
        $this->assertSame(0, (int) $after->downtime_seconds);
        $this->assertSame(2, (int) $after->checks_total);
        $this->assertSame(21, (int) $after->latency_max_ms);

        $payload = json_decode((string) DB::table('status_page_cache')->value('payload_json'), true, 512, JSON_THROW_ON_ERROR);
        $entry = collect($payload['components'][0]['history'])->firstWhere('date', $day->format('Y-m-d'));
        $this->assertEquals(100.0, $entry['uptime']);
        $this->assertSame(0, $entry['downtime_seconds']);
        $this->assertEquals(100.0, $payload['components'][0]['uptime']['24h'], 'the current-day partial skips voided results too');

        $audit = DB::table('audit_logs')->where('action', 'check_results.voided')->sole();
        $summary = json_decode((string) $audit->summary_json, true);
        $this->assertEqualsCanonicalizing([$falseOne, $today], $summary['result_ids']);
        $this->assertSame('checker measured tcp_timeout while busy (#231)', $summary['reason']);
    }

    public function test_dry_run_changes_nothing_and_result_ids_narrow_the_window(): void
    {
        $day = now()->subDays(2)->startOfDay();
        $a = $this->checkResult($this->monitorId, 'down', $day->copy()->addHours(10), 9000);
        $b = $this->checkResult($this->monitorId, 'down', $day->copy()->addHours(11), 9000);
        $args = ['--monitor' => (string) $this->monitorId, '--from' => $day->toDateTimeString(), '--to' => $day->copy()->endOfDay()->toDateTimeString(), '--reason' => 'test'];

        $this->assertSame(0, Artisan::call('monitor:void-results', $args + ['--dry-run' => true]));
        $this->assertStringContainsString('2 result(s) would be voided', Artisan::output());
        $this->assertSame(0, DB::table('check_results')->whereNotNull('voided_at')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());

        $this->assertSame(0, Artisan::call('monitor:void-results', $args + ['--result' => (string) $b]));
        $this->assertNull(DB::table('check_results')->where('id', $a)->value('voided_at'));
        $this->assertNotNull(DB::table('check_results')->where('id', $b)->value('voided_at'));
    }

    public function test_results_inside_an_incident_need_force(): void
    {
        $day = now()->subDays(2)->startOfDay();
        $failure = $this->checkResult($this->monitorId, 'down', $day->copy()->addHours(9), 30);
        $monitor = DB::table('monitors')->find($this->monitorId);
        DB::table('incidents')->insert([
            'public_id' => 'inc_'.bin2hex(random_bytes(8)), 'tenant_id' => $monitor->tenant_id, 'environment_id' => $monitor->environment_id,
            'monitor_id' => $this->monitorId, 'started_at' => $day->copy()->addHours(9)->subMinute(), 'resolved_at' => $day->copy()->addHours(9)->addMinutes(5),
            'resolved_flag' => null, 'severity' => 'major', 'summary' => 'Down', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $args = ['--monitor' => (string) $this->monitorId, '--from' => $day->toDateTimeString(), '--to' => $day->copy()->endOfDay()->toDateTimeString(), '--reason' => 'test'];

        $this->assertSame(1, Artisan::call('monitor:void-results', $args));
        $this->assertStringContainsString('inside an incident', Artisan::output());
        $this->assertNull(DB::table('check_results')->where('id', $failure)->value('voided_at'));

        $this->assertSame(0, Artisan::call('monitor:void-results', $args + ['--force' => true]));
        $this->assertNotNull(DB::table('check_results')->where('id', $failure)->value('voided_at'));
    }

    public function test_input_is_validated(): void
    {
        $this->assertSame(2, Artisan::call('monitor:void-results', ['--monitor' => (string) $this->monitorId, '--from' => '2026-11-16 14:00:00', '--to' => '2026-11-16 15:00:00']));
        $this->assertSame(2, Artisan::call('monitor:void-results', ['--from' => '2026-11-16 14:00:00', '--to' => '2026-11-16 15:00:00', '--reason' => 'x']));
        $this->assertSame(2, Artisan::call('monitor:void-results', ['--monitor' => (string) $this->monitorId, '--from' => '2026-11-16 15:00:00', '--to' => '2026-11-16 14:00:00', '--reason' => 'x']));
        $this->assertSame(2, Artisan::call('monitor:void-results', ['--monitor' => (string) $this->monitorId, '--from' => '2026-09-01 00:00:00', '--to' => '2026-11-16 14:00:00', '--reason' => 'x']));
    }

    private function checkResult(int $monitorId, string $state, \DateTimeInterface $at, int $latency): int
    {
        $monitor = DB::table('monitors')->find($monitorId);

        return DB::table('check_results')->insertGetId([
            'public_id' => 'res_'.bin2hex(random_bytes(12)),
            'tenant_id' => $monitor->tenant_id,
            'environment_id' => $monitor->environment_id,
            'monitor_id' => $monitorId,
            'state' => $state,
            'latency_ms' => $latency,
            'failure_reason' => $state === 'up' ? null : 'Transport failure: tcp_timeout',
            'checked_at' => $at,
            'created_at' => $at,
        ]);
    }

    private function fixtures(): void
    {
        $tenant = \App\Infrastructure\Persistence\Eloquent\Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $environment = \App\Infrastructure\Persistence\Eloquent\Environment::query()->create(['tenant_id' => $tenant->id, 'name' => 'Production', 'slug' => 'production', 'is_default' => true]);
        $tenantId = $tenant->id;
        $environmentId = $environment->id;
        $monitor = fn (string $name): int => \App\Infrastructure\Persistence\Eloquent\Monitor::query()->create([
            'tenant_id' => $tenantId, 'environment_id' => $environmentId, 'name' => $name,
            'kind' => 'tcp', 'target' => 'broker.example:8883', 'interval_seconds' => 60, 'current_state' => 'up',
        ])->id;
        $this->monitorId = $monitor('Acme MQTT');
        $this->otherMonitorId = $monitor('Example MQTT');
        $pageId = DB::table('status_pages')->insertGetId([
            'tenant_id' => $tenantId, 'environment_id' => $environmentId, 'slug' => 'acme-status', 'title' => 'Acme Status',
            'visibility' => 'public', 'locale' => 'en', 'timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $componentId = DB::table('status_page_components')->insertGetId(['status_page_id' => $pageId, 'name' => 'Customer portal', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('status_page_component_monitors')->insert(['status_page_component_id' => $componentId, 'monitor_id' => $this->monitorId, 'created_at' => now(), 'updated_at' => now()]);
    }
}
