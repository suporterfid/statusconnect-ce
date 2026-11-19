<?php

namespace Tests\Feature;

use App\Application\Monitoring\MonitorService;
use App\Application\Tenancy\TenantService;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StatusPageComponentDetailTest extends TestCase
{
    use RefreshDatabase;

    private Monitor $monitor;

    private int $componentId;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create();
        $tenant = app(TenantService::class)->createTenant('Acme', 'acme', $owner);
        $environment = $tenant->environments()->firstOrFail();
        $this->monitor = app(MonitorService::class)->createMonitor($tenant, $environment, [
            'name' => 'API',
            'kind' => 'http',
            'target' => 'http://target:8095/status/200?secret=do-not-leak',
        ]);
        $base = "/v1/tenants/{$tenant->public_id}/environments/{$environment->public_id}/status-page";
        $this->actingAs($owner)->putJson($base, ['slug' => 'acme-status', 'title' => 'Acme Status', 'locale' => 'en'])->assertCreated();
        $this->componentId = $this->actingAs($owner)->postJson("{$base}/components", [
            'name' => 'Storefront',
            'monitor_ids' => [$this->monitor->public_id],
        ])->assertCreated()->json('data.id');
        auth()->logout();
    }

    public function test_snapshot_carries_component_id_and_hourly_response_time_series(): void
    {
        $lastClosedHour = now()->startOfHour()->subHour();
        $this->hourRollup($lastClosedHour, 120, 300);
        $this->hourRollup($lastClosedHour->copy()->subHours(30), 80, 90);
        $this->refresh();

        $component = $this->get('/status/acme-status.json')->assertOk()->json('components.0');
        $this->assertSame($this->componentId, $component['id']);
        $this->assertCount(24, $component['response_time']['24h']);
        $this->assertCount(42, $component['response_time']['7d']);
        $last = $component['response_time']['24h'][23];
        $this->assertSame(120, $last['avg_ms']);
        $this->assertSame(300, $last['p95_ms']);
        $this->assertNull($component['response_time']['24h'][0]['avg_ms']);
        $this->assertSame(2, collect($component['response_time']['7d'])->whereNotNull('avg_ms')->count());
        $this->assertStringNotContainsString('do-not-leak', json_encode($component));
    }

    public function test_component_page_renders_chart_bars_and_its_incidents(): void
    {
        $this->hourRollup(now()->startOfHour()->subHours(2), 100, 150);
        $this->hourRollup(now()->startOfHour()->subHour(), 140, 200);
        DB::table('incidents')->insert([
            'public_id' => 'inc_01JCOMPONENTDETAIL0000000',
            'tenant_id' => $this->monitor->tenant_id,
            'environment_id' => $this->monitor->environment_id,
            'monitor_id' => $this->monitor->id,
            'started_at' => now()->subHours(3),
            'resolved_at' => now()->subHours(2),
            'severity' => 'major',
            'summary' => 'internal summary must stay private',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->refresh();

        $this->get('/status/acme-status')
            ->assertOk()
            ->assertSee('/status/acme-status/components/'.$this->componentId)
            ->assertSee('/status/acme-status/calendar');

        $this->get('/status/acme-status/components/'.$this->componentId)
            ->assertOk()
            ->assertSee('Storefront')
            ->assertSee('Response time')
            ->assertSee('Avg')
            ->assertSee('120 ms')
            ->assertSee('<path d="M', false)
            ->assertSee('Major outage')
            ->assertDontSee('internal summary must stay private')
            ->assertDontSee('do-not-leak');
    }

    public function test_an_isolated_hour_is_drawn_as_a_round_dot(): void
    {
        $this->hourRollup(now()->startOfHour()->subHours(5), 100, 150);
        $this->refresh();

        $html = $this->get('/status/acme-status/components/'.$this->componentId)
            ->assertOk()
            ->assertSee('class="dot-point"', false)
            ->getContent();

        // Only the charts are checked: the footer's brand mark legitimately uses circles.
        preg_match_all('#<div class="chart">.*?</svg>#s', $html, $charts);
        $this->assertNotEmpty($charts[0]);
        foreach ($charts[0] as $chart) {
            $this->assertStringNotContainsString('<circle', $chart);
        }
    }

    public function test_each_chart_point_has_a_tooltip_with_its_period_and_values(): void
    {
        $hour = now()->startOfHour()->subHours(2);
        $this->hourRollup($hour, 140, 200);
        DB::table('check_rollups')->where('monitor_id', $this->monitor->id)->update(['latency_min_ms' => 90, 'latency_max_ms' => 410]);
        $this->refresh();

        $html = $this->get('/status/acme-status/components/'.$this->componentId)->assertOk()->getContent();

        // 24 hourly points plus 42 four-hour points, each one focusable.
        $this->assertSame(24 + 42, substr_count($html, '<span tabindex="0" class="'));
        // English pages: m/d/Y h:i A in the page timezone (UTC here); same-day end shows the time only.
        $period = $hour->format('m/d/Y h:i A').' – '.$hour->copy()->addHour()->format('h:i A');
        $this->assertStringContainsString('data-tip="'.e($period."\nAvg 140 ms · min 90 ms · max 410 ms").'"', $html);
        $this->assertStringContainsString("\nNo data\"", $html);
        $this->assertStringContainsString('class="mark"', $html);
    }

    public function test_tooltips_follow_the_page_locale_and_timezone(): void
    {
        DB::table('status_pages')->update(['locale' => 'pt_BR', 'timezone' => 'America/Sao_Paulo']);
        $hour = now()->startOfHour()->subHours(2);
        $this->hourRollup($hour, 140, 200);
        $this->refresh();

        $html = $this->get('/status/acme-status/components/'.$this->componentId)->assertOk()->getContent();

        $local = $hour->copy()->timezone('America/Sao_Paulo');
        $period = $local->format('d/m/Y H:i').' – '.($local->isSameDay($local->copy()->addHour()) ? $local->copy()->addHour()->format('H:i') : $local->copy()->addHour()->format('d/m/Y H:i'));
        $this->assertStringContainsString(e($period."\nMédia 140 ms"), $html);
        $this->assertStringContainsString("\nSem dados\"", $html);
    }

    public function test_main_page_shows_the_24h_average_response_time_next_to_uptime(): void
    {
        // Weighted by checks: (100 * 60 + 400 * 180) / 240 = 325. Older than 24 h: ignored.
        $this->hourRollup(now()->startOfHour()->subHours(3), 100, 150);
        $this->hourRollup(now()->startOfHour()->subHours(2), 400, 500, 180);
        $this->hourRollup(now()->startOfHour()->subHours(30), 9000, 9000);
        $this->refresh();

        $this->get('/status/acme-status.json')->assertJsonPath('components.0.latency_24h_ms', 325);
        $this->get('/status/acme-status')
            ->assertOk()
            ->assertSee('<span class="sr-only">Average response time over 24 h:</span>', false)
            ->assertSee('325 ms')
            ->assertDontSee('do-not-leak');
    }

    public function test_main_page_formats_seconds_per_locale(): void
    {
        DB::table('status_pages')->update(['locale' => 'pt_BR']);
        $this->hourRollup(now()->startOfHour()->subHour(), 1234, 1500);
        $this->refresh();

        $this->get('/status/acme-status')
            ->assertOk()
            ->assertSee('Tempo de resposta médio em 24 h:')
            ->assertSee('1,2 s');
    }

    public function test_main_page_shows_no_response_time_without_data(): void
    {
        $this->hourRollup(now()->startOfHour()->subHours(30), 120, 300);
        $this->refresh();

        $this->get('/status/acme-status.json')->assertJsonPath('components.0.latency_24h_ms', null);
        $this->get('/status/acme-status')
            ->assertOk()
            ->assertDontSee('Average response time over 24 h')
            ->assertDontSee('class="latency"', false);
    }

    public function test_unknown_component_returns_not_found(): void
    {
        $this->refresh();

        $this->get('/status/acme-status/components/999999')->assertNotFound();
        $this->get('/status/unknown/components/'.$this->componentId)->assertNotFound();
    }

    public function test_component_page_without_latency_data_says_so(): void
    {
        $this->refresh();

        $this->get('/status/acme-status/components/'.$this->componentId)
            ->assertOk()
            ->assertSee('No response time data for this period yet.');
    }

    public function test_calendar_colours_days_by_the_worst_component_state(): void
    {
        $day = now()->subDays(3)->startOfDay();
        DB::table('check_rollups')->insert([
            'monitor_id' => $this->monitor->id,
            'bucket_start' => $day,
            'bucket_kind' => 'day',
            'up_count' => 9,
            'down_count' => 1,
            'checks_total' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->refresh();

        $this->get('/status/acme-status/calendar')
            ->assertOk()
            ->assertSee('Calendar')
            ->assertSee(now()->format('F Y'))
            ->assertSee($day->format('M j, Y').': Major outage');
    }

    private function hourRollup($start, int $avg, int $p95, int $checks = 60): void
    {
        DB::table('check_rollups')->insert([
            'monitor_id' => $this->monitor->id,
            'bucket_start' => $start,
            'bucket_kind' => 'hour',
            'up_count' => $checks,
            'checks_total' => $checks,
            'latency_min_ms' => (int) ($avg / 2),
            'latency_avg_ms' => $avg,
            'latency_p95_ms' => $p95,
            'latency_max_ms' => $p95 + 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function refresh(): void
    {
        DB::table('status_page_cache')->delete();
        $this->artisan('monitor:rollup')->assertSuccessful();
    }
}
