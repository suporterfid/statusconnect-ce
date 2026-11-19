<?php

namespace Tests\Feature;

use App\Application\Tenancy\TenantService;
use App\Domain\Monitoring\MonitorKind;
use App\Infrastructure\Persistence\Eloquent\AgentMetricRule;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * POST /ingest/{token}/v1/metrics, OTLP/HTTP JSON (#79).
 */
class AgentOtlpIngestTest extends TestCase
{
    use RefreshDatabase;

    private Monitor $monitor;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('agent.enabled', true);

        $owner = User::factory()->create();
        $tenant = app(TenantService::class)->createTenant('Acme', 'acme', $owner);
        $environment = $tenant->environments()->firstOrFail();
        $this->monitor = Monitor::query()->create([
            'tenant_id' => $tenant->id,
            'environment_id' => $environment->id,
            'name' => 'web1 otel',
            'kind' => MonitorKind::AGENT,
            'target' => 'agent://web1',
            'interval_seconds' => 60,
            'confirmation_threshold' => 1,
            'next_check_at' => now()->subMinute(),
        ]);
        $base = "/v1/tenants/{$tenant->public_id}/environments/{$environment->public_id}/monitors/{$this->monitor->public_id}";
        $this->token = $this->actingAs($owner)->postJson("{$base}/rotate-agent-token")->assertOk()->json('meta.agent_token');
    }

    public function test_real_collector_payload_is_stored_and_evaluated(): void
    {
        $fixture = (string) file_get_contents(base_path('tests/Fixtures/otel/hostmetrics.json'));

        $response = $this->otlp(gzencode($fixture), ['HTTP_CONTENT_ENCODING' => 'gzip'])->assertOk();
        $this->assertSame('{}', $response->getContent(), 'full success is an empty ExportMetricsServiceResponse');
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));

        $keys = DB::table('agent_metric_latest')->where('monitor_id', $this->monitor->id)->pluck('value', 'metric_key');
        $this->assertCount(116, $keys);
        $this->assertEqualsWithDelta(0.97, (float) $keys['system.cpu.load_average.1m{host.name=web1}'], 1e-9);
        $this->assertNotNull($this->monitor->fresh()->last_ingest_at);

        AgentMetricRule::query()->create([
            'tenant_id' => $this->monitor->tenant_id,
            'monitor_id' => $this->monitor->id,
            'metric_key' => 'system.filesystem.utilization{device=/dev/sda1,host.name=web1,mode=rw,mountpoint=/,type=ext4}',
            'operator' => 'gte',
            'expected' => '0.6',
            'severity' => 'down',
            'missing_policy' => 'fail',
        ]);
        $this->artisan('monitor:check-due')->assertSuccessful();

        $result = DB::table('check_results')->where('monitor_id', $this->monitor->id)->orderByDesc('id')->first();
        $this->assertSame('down', $result->state);
        $this->assertStringStartsWith('system.filesystem.utilization{', $result->failure_reason);
        $this->assertSame(1, DB::table('incidents')->whereNull('resolved_at')->count());
    }

    public function test_partial_success_reports_rejected_points(): void
    {
        config()->set('agent.max_metrics_per_monitor', 2);
        $body = json_encode(['resourceMetrics' => [[
            'resource' => ['attributes' => [['key' => 'host.name', 'value' => ['stringValue' => 'web1']]]],
            'scopeMetrics' => [['metrics' => [
                ['name' => 'http.server.duration', 'histogram' => ['dataPoints' => [['count' => '3']]]],
                ['name' => 'a', 'gauge' => ['dataPoints' => [['asDouble' => 1]]]],
                ['name' => 'b', 'gauge' => ['dataPoints' => [['asInt' => '2']]]],
                ['name' => 'c', 'gauge' => ['dataPoints' => [['asInt' => 3]]]],
            ]]],
        ]]]);

        $response = $this->otlp($body)->assertOk();
        // One histogram point, plus one new key over the per-monitor cap of 2.
        $response->assertJsonPath('partialSuccess.rejectedDataPoints', '2');
        $this->assertNotEmpty($response->json('partialSuccess.errorMessage'));
        $this->assertSame(2, DB::table('agent_metric_latest')->where('monitor_id', $this->monitor->id)->count());
    }

    public function test_protobuf_and_other_types_are_415(): void
    {
        $this->otlp('ignored', ['CONTENT_TYPE' => 'application/x-protobuf'])
            ->assertStatus(415)
            ->assertJsonPath('code', 3)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'encoding: json'));
        $this->otlp('{}', ['CONTENT_TYPE' => 'text/plain'])->assertStatus(415);
        $this->assertSame(0, DB::table('agent_metric_latest')->count());
    }

    public function test_errors_follow_the_line_protocol_route(): void
    {
        $this->otlp('{"resourceMetrics": [', [])->assertStatus(400)->assertJsonPath('code', 3);
        $this->otlp('not gzip', ['HTTP_CONTENT_ENCODING' => 'gzip'])->assertStatus(400);

        config()->set('agent.max_bytes', 10);
        $this->otlp(str_repeat(' ', 11).'{}')->assertStatus(413);
        config()->set('agent.max_bytes', 262144);

        $unknown = $this->otlp('{}', [], 'sca_'.str_repeat('0', 48))->assertNotFound();
        $this->assertSame(['message' => 'Not Found'], $unknown->json());
        $this->otlp('{}', [], 'garbage')->assertNotFound();

        config()->set('agent.enabled', false);
        $this->otlp('{}')->assertNotFound();
        config()->set('agent.enabled', true);

        // The invalid-JSON request above already counted against this token.
        config()->set('agent.rate_per_token_per_minute', 2);
        $this->otlp('{}')->assertOk();
        $this->assertNotNull($this->otlp('{}')->assertStatus(429)->headers->get('Retry-After'));
    }

    private function otlp(string $body, array $server = [], ?string $token = null): TestResponse
    {
        auth()->logout();

        return $this->call('POST', '/ingest/'.($token ?? $this->token).'/v1/metrics', [], [], [], array_merge(['CONTENT_TYPE' => 'application/json'], $server), $body);
    }
}
