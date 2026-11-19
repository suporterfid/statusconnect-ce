<?php

namespace Tests\Feature;

use App\Application\Tenancy\TenantService;
use App\Domain\Monitoring\Agent\LineProtocolParser;
use App\Domain\Monitoring\Agent\MetricSample;
use App\Domain\Monitoring\MonitorKind;
use App\Infrastructure\Persistence\Eloquent\AgentMetricRepository;
use App\Infrastructure\Persistence\Eloquent\AgentMetricRule;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AgentMetricStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_samples_are_upserted_per_monitor_and_tenants_stay_isolated(): void
    {
        $a = $this->agentMonitor('acme');
        $b = $this->agentMonitor('globex');
        $repo = new AgentMetricRepository();
        $samples = (new LineProtocolParser())->parse("mem,host=web1 used_percent=40\nsystem,host=web1 load1=0.5 1794064232000000000")->samples;

        $repo->store($a, $samples, new DateTimeImmutable('2026-11-07 12:00:00'));
        $repo->store($b, (new LineProtocolParser())->parse('mem,host=web1 used_percent=99')->samples, new DateTimeImmutable('2026-11-07 12:00:00'));
        $repo->store($a, (new LineProtocolParser())->parse('mem,host=web1 used_percent=41.5')->samples, new DateTimeImmutable('2026-11-07 12:01:00'));

        $latestA = $repo->latest($a);
        $this->assertSame(['mem.used_percent{host=web1}', 'system.load1{host=web1}'], array_keys($latestA));
        $this->assertSame(41.5, $latestA['mem.used_percent{host=web1}']['value']);
        $this->assertSame('2026-11-07 12:01:00', $latestA['mem.used_percent{host=web1}']['received_at']->format('Y-m-d H:i:s'));
        $this->assertSame(99.0, $repo->latest($b)['mem.used_percent{host=web1}']['value']);
        $this->assertSame(3, DB::table('agent_metric_latest')->count());
        $this->assertSame(0, DB::table('agent_metric_latest')->where('monitor_id', $a->id)->where('tenant_id', '!=', $a->tenant_id)->count());
        $this->assertSame(0, DB::table('agent_metric_latest')->where('monitor_id', $b->id)->where('tenant_id', '!=', $b->tenant_id)->count());
        $this->assertSame('2026-11-07 12:01:00', $a->fresh()->last_ingest_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-07 12:00:00', $b->fresh()->last_ingest_at->format('Y-m-d H:i:s'));

        $sampledAt = DB::table('agent_metric_latest')->where('monitor_id', $a->id)->where('metric_key', 'system.load1{host=web1}')->value('sampled_at');
        $this->assertSame(gmdate('Y-m-d H:i:s', 1794064232), (string) $sampledAt);

        AgentMetricRule::query()->create(['tenant_id' => $a->tenant_id, 'monitor_id' => $a->id, 'metric_key' => 'mem.used_percent{host=web1}', 'operator' => 'gt', 'expected' => '90', 'severity' => 'down', 'missing_policy' => 'ignore']);
        $this->assertCount(1, $repo->rules($a));
        $this->assertSame([], $repo->rules($b));
        $this->assertSame('gt', $repo->rules($a)[0]->operator->value);
    }

    public function test_new_metrics_above_the_cardinality_cap_are_dropped_and_counted(): void
    {
        $monitor = $this->agentMonitor('acme');
        $repo = new AgentMetricRepository(maxMetricsPerMonitor: 200);
        $at = new DateTimeImmutable('2026-11-07 12:00:00');

        $first = [];
        for ($i = 1; $i <= 200; $i++) {
            $first[] = new MetricSample("m.f{i=$i}", 'm', 'f', ['i' => (string) $i], (float) $i);
        }
        $result = $repo->store($monitor, $first, $at);
        $this->assertSame(200, $result->stored);
        $this->assertSame(0, $result->droppedOverCardinality);

        $next = [
            new MetricSample('m.f{i=1}', 'm', 'f', ['i' => '1'], 1000.0),
            new MetricSample('m.f{i=201}', 'm', 'f', ['i' => '201'], 201.0),
        ];
        $result = $repo->store($monitor, $next, $at->modify('+1 minute'));

        $this->assertSame(1, $result->stored);
        $this->assertSame(1, $result->droppedOverCardinality);
        $this->assertSame(200, DB::table('agent_metric_latest')->where('monitor_id', $monitor->id)->count());
        $this->assertSame(1000.0, $repo->latest($monitor)['m.f{i=1}']['value']);
        $this->assertArrayNotHasKey('m.f{i=201}', $repo->latest($monitor));
    }

    public function test_monitor_maintenance_deletes_metrics_not_updated_within_retention(): void
    {
        config()->set('agent.retention_days', 30);
        $monitor = $this->agentMonitor('acme');
        $repo = new AgentMetricRepository();
        $repo->store($monitor, [new MetricSample('old.f', 'old', 'f', [], 1.0)], new DateTimeImmutable(now()->subDays(31)->format('Y-m-d H:i:s')));
        $repo->store($monitor, [new MetricSample('fresh.f', 'fresh', 'f', [], 2.0)], new DateTimeImmutable(now()->subDays(2)->format('Y-m-d H:i:s')));

        $this->artisan('monitor:maintenance')->assertSuccessful();

        $this->assertSame(['fresh.f'], array_keys($repo->latest($monitor)));
    }

    public function test_agent_token_hash_is_never_serialized(): void
    {
        $monitor = $this->agentMonitor('acme');
        $monitor->forceFill(['agent_token_hash' => str_repeat('a', 64)])->save();

        $this->assertArrayNotHasKey('agent_token_hash', $monitor->fresh()->toArray());
        $this->assertSame(MonitorKind::AGENT, $monitor->fresh()->kind);
    }

    private function agentMonitor(string $slug): Monitor
    {
        $user = User::factory()->create();
        /** @var Tenant $tenant */
        $tenant = app(TenantService::class)->createTenant(ucfirst($slug), $slug, $user);
        $environment = $tenant->environments()->firstOrFail();

        return Monitor::query()->create([
            'tenant_id' => $tenant->id,
            'environment_id' => $environment->id,
            'name' => 'Agent '.$slug,
            'kind' => MonitorKind::AGENT,
            'target' => 'agent://'.$slug,
            'interval_seconds' => 60,
        ]);
    }
}
