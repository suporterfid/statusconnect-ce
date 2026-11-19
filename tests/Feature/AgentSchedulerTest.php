<?php

namespace Tests\Feature;

use App\Application\Tenancy\TenantService;
use App\Domain\Monitoring\Agent\LineProtocolParser;
use App\Domain\Monitoring\MonitorKind;
use App\Infrastructure\Persistence\Eloquent\AgentMetricRepository;
use App\Infrastructure\Persistence\Eloquent\AgentMetricRule;
use App\Infrastructure\Persistence\Eloquent\MaintenanceWindow;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AgentSchedulerTest extends TestCase
{
    use RefreshDatabase;

    private Monitor $monitor;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create();
        $tenant = app(TenantService::class)->createTenant('Acme', 'acme', $owner);
        $environment = $tenant->environments()->firstOrFail();
        $this->monitor = Monitor::query()->create([
            'tenant_id' => $tenant->id,
            'environment_id' => $environment->id,
            'name' => 'db agent',
            'kind' => MonitorKind::AGENT,
            'target' => 'agent://db',
            'interval_seconds' => 60,
            'agent_grace_seconds' => 120,
            'confirmation_threshold' => 2,
            'recovery_threshold' => 1,
            'next_check_at' => now()->subMinute(),
        ]);
    }

    public function test_missing_ingestion_opens_an_incident_and_resumed_ingestion_resolves_it(): void
    {
        $this->tick();
        $this->assertSame('down', $this->lastResult()->state);
        $this->assertStringStartsWith('no_data', $this->lastResult()->failure_reason);
        $this->assertSame(0, DB::table('incidents')->count(), 'one failure is not confirmed yet');

        $this->tick();
        $this->assertSame(1, DB::table('incidents')->whereNull('resolved_at')->count());
        $this->assertSame('down', $this->monitor->fresh()->current_state->value);

        $this->ingest("mem,host=db-secret-host used_percent=40\n");
        $this->tick();
        $this->assertSame('up', $this->lastResult()->state);
        $this->assertSame(0, DB::table('incidents')->whereNull('resolved_at')->count());
        $this->assertSame('up', $this->monitor->fresh()->current_state->value);

        DB::table('monitors')->where('id', $this->monitor->id)->update(['last_ingest_at' => now()->subSeconds(181)]);
        $this->tick();
        $this->assertSame('down', $this->lastResult()->state);
        $this->assertSame('no_data: last metrics 181s ago (limit 180s)', $this->lastResult()->failure_reason);
    }

    public function test_degraded_and_down_rules_set_the_state_with_a_reason(): void
    {
        $this->rule('disk.used_percent{host=db-secret-host,path=/}', 'gte', '80', 'degraded');
        $this->rule('mem.used_percent{host=db-secret-host}', 'gt', '90', 'down');

        $this->ingest("disk,host=db-secret-host,path=/ used_percent=85\nmem,host=db-secret-host used_percent=50\n");
        $this->tick();
        $this->assertSame('degraded', $this->lastResult()->state);
        $this->assertSame('disk.used_percent{host=db-secret-host,path=/}=85 gte 80', $this->lastResult()->failure_reason);

        $this->ingest("disk,host=db-secret-host,path=/ used_percent=85\nmem,host=db-secret-host used_percent=93.2\n");
        $this->tick();
        $this->assertSame('down', $this->lastResult()->state);
        $this->assertSame('mem.used_percent{host=db-secret-host}=93.2 gt 90', $this->lastResult()->failure_reason);
    }

    public function test_maintenance_window_skips_the_check(): void
    {
        $window = MaintenanceWindow::query()->create([
            'tenant_id' => $this->monitor->tenant_id,
            'environment_id' => $this->monitor->environment_id,
            'name' => 'Patching',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);
        $window->monitors()->attach($this->monitor->id);

        $this->tick();
        $this->tick();

        $this->assertSame('skipped', $this->lastResult()->state);
        $this->assertSame(0, DB::table('incidents')->count());
    }

    public function test_public_status_page_never_shows_metric_keys_values_or_tags(): void
    {
        $owner = User::query()->firstOrFail();
        $tenant = Tenant::query()->findOrFail($this->monitor->tenant_id);
        $environment = Environment::query()->findOrFail($this->monitor->environment_id);
        $base = "/v1/tenants/{$tenant->public_id}/environments/{$environment->public_id}/status-page";
        $this->actingAs($owner)->putJson($base, ['slug' => 'acme-status', 'title' => 'Acme Status', 'locale' => 'en'])->assertCreated();
        $this->actingAs($owner)->postJson("{$base}/components", ['name' => 'Database', 'monitor_ids' => [$this->monitor->public_id]])->assertCreated();
        auth()->logout();

        $this->rule('mem.used_percent{host=db-secret-host}', 'gt', '90', 'down');
        $this->ingest("mem,host=db-secret-host used_percent=93.2\n");
        $this->tick();
        $this->tick();
        DB::table('status_page_cache')->delete();
        $this->artisan('monitor:rollup')->assertSuccessful();

        foreach (['/status/acme-status', '/status/acme-status.json', '/status/acme-status/feed', '/status/acme-status/history'] as $path) {
            $body = $this->get($path)->assertOk()->getContent();
            $this->assertStringNotContainsString('db-secret-host', $body, $path);
            $this->assertStringNotContainsString('used_percent', $body, $path);
            $this->assertStringNotContainsString('93.2', $body, $path);
        }
        $this->get('/status/acme-status.json')->assertJsonPath('components.0.state', 'major_outage');
    }

    public function test_latency_metric_becomes_the_check_latency_without_changing_the_state(): void
    {
        DB::table('monitors')->where('id', $this->monitor->id)->update(['agent_latency_metric_key' => 'app_latency.query_p95_ms{host=acme}']);
        $this->rule('app_latency.query_p95_ms{host=acme}', 'gt', '5000', 'down');

        $this->ingest("app_latency,host=acme query_p95_ms=450.6\n");
        $this->tick();
        $this->assertSame('up', $this->lastResult()->state);
        $this->assertSame(451, (int) $this->lastResult()->latency_ms);
        $this->assertSame(451, (int) $this->monitor->fresh()->last_latency_ms);

        // A value above a down rule still sets the latency; the rule alone sets the state.
        $this->ingest("app_latency,host=acme query_p95_ms=6200\n");
        $this->tick();
        $this->assertSame('down', $this->lastResult()->state);
        $this->assertSame(6200, (int) $this->lastResult()->latency_ms);
    }

    public function test_missing_stale_or_non_positive_latency_metric_leaves_no_latency(): void
    {
        DB::table('monitors')->where('id', $this->monitor->id)->update(['agent_latency_metric_key' => 'app_latency.query_p95_ms{host=acme}']);

        // Another metric only: the latency metric is missing.
        $this->ingest("mem,host=db used_percent=40\n");
        $this->tick();
        $this->assertSame('up', $this->lastResult()->state);
        $this->assertSame(0, (int) $this->lastResult()->latency_ms);

        foreach (['0', '-12'] as $value) {
            $this->ingest("app_latency,host=acme query_p95_ms={$value}\n");
            $this->tick();
            $this->assertSame('up', $this->lastResult()->state);
            $this->assertSame(0, (int) $this->lastResult()->latency_ms, $value);
        }

        // A non-numeric field is not a metric (the parser drops it).
        $this->ingest("app_latency,host=acme query_p95_ms=\"slow\",other=1\n");
        $this->tick();
        $this->assertSame(0, (int) $this->lastResult()->latency_ms);

        // Received before the latest ingestion: missing, like the rules see it.
        $this->ingest("app_latency,host=acme query_p95_ms=300\n");
        DB::table('agent_metric_latest')->where('metric_key', 'app_latency.query_p95_ms{host=acme}')->update(['received_at' => now()->subMinutes(5)]);
        $this->tick();
        $this->assertSame(0, (int) $this->lastResult()->latency_ms);

        // Ingestion stopped: no_data, and no latency from the old value.
        $this->ingest("app_latency,host=acme query_p95_ms=300\n");
        DB::table('monitors')->where('id', $this->monitor->id)->update(['last_ingest_at' => now()->subSeconds(181)]);
        DB::table('agent_metric_latest')->update(['received_at' => now()->subSeconds(181)]);
        $this->tick();
        $this->assertSame('down', $this->lastResult()->state);
        $this->assertSame(0, (int) $this->lastResult()->latency_ms);
    }

    public function test_without_a_latency_metric_the_latency_stays_zero(): void
    {
        $this->ingest("app_latency,host=acme query_p95_ms=450\n");
        $this->tick();
        $this->assertSame(0, (int) $this->lastResult()->latency_ms);
    }

    public function test_agent_latency_reaches_rollups_and_the_main_status_page(): void
    {
        DB::table('monitors')->where('id', $this->monitor->id)->update(['agent_latency_metric_key' => 'app_latency.query_p95_ms{host=acme}']);
        $owner = User::query()->firstOrFail();
        $tenant = Tenant::query()->findOrFail($this->monitor->tenant_id);
        $environment = Environment::query()->findOrFail($this->monitor->environment_id);
        $base = "/v1/tenants/{$tenant->public_id}/environments/{$environment->public_id}/status-page";
        $this->actingAs($owner)->putJson($base, ['slug' => 'acme-status', 'title' => 'Acme Status', 'locale' => 'en'])->assertCreated();
        $this->actingAs($owner)->postJson("{$base}/components", ['name' => 'Portal', 'monitor_ids' => [$this->monitor->public_id]])->assertCreated();
        auth()->logout();

        foreach (['400', '500'] as $value) {
            $this->ingest("app_latency,host=acme query_p95_ms={$value}\n");
            $this->tick();
        }
        // Move both checks into the last closed hour, then roll up.
        DB::table('check_results')->where('monitor_id', $this->monitor->id)->update(['checked_at' => now()->startOfHour()->subMinutes(30)]);
        DB::table('status_page_cache')->delete();
        $this->artisan('monitor:rollup')->assertSuccessful();

        $hour = DB::table('check_rollups')->where('monitor_id', $this->monitor->id)->where('bucket_kind', 'hour')->first();
        $this->assertSame(450, (int) $hour->latency_avg_ms);
        $this->assertSame(400, (int) $hour->latency_min_ms);
        $this->assertSame(500, (int) $hour->latency_max_ms);

        $this->get('/status/acme-status.json')->assertJsonPath('components.0.latency_24h_ms', 450);
        $body = $this->get('/status/acme-status')->assertOk()->assertSee('450 ms')->getContent();
        $this->assertStringNotContainsString('app_latency', $body);
        $this->assertStringNotContainsString('host=acme', $body);
    }

    private function tick(): void
    {
        DB::table('monitors')->where('id', $this->monitor->id)->update(['next_check_at' => now()->subSecond(), 'claim_token' => null]);
        $this->artisan('monitor:check-due')->assertSuccessful();
    }

    private function ingest(string $lines): void
    {
        app(AgentMetricRepository::class)->store($this->monitor->fresh(), (new LineProtocolParser())->parse($lines)->samples, new DateTimeImmutable(now()->format('Y-m-d H:i:s')));
    }

    private function rule(string $key, string $operator, string $expected, string $severity): void
    {
        AgentMetricRule::query()->create([
            'tenant_id' => $this->monitor->tenant_id,
            'monitor_id' => $this->monitor->id,
            'metric_key' => $key,
            'operator' => $operator,
            'expected' => $expected,
            'severity' => $severity,
            'missing_policy' => 'ignore',
        ]);
    }

    private function lastResult(): object
    {
        return DB::table('check_results')->where('monitor_id', $this->monitor->id)->orderByDesc('id')->first();
    }
}
