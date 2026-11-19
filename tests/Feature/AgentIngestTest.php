<?php

namespace Tests\Feature;

use App\Application\Tenancy\TenantService;
use App\Domain\Monitoring\MonitorKind;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\TenantMembership;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AgentIngestTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Monitor $monitor;

    private string $base;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('agent.enabled', true);

        $this->owner = User::factory()->create();
        $tenant = app(TenantService::class)->createTenant('Acme', 'acme', $this->owner);
        $environment = $tenant->environments()->firstOrFail();
        $this->monitor = Monitor::query()->create([
            'tenant_id' => $tenant->id,
            'environment_id' => $environment->id,
            'name' => 'web1 agent',
            'kind' => MonitorKind::AGENT,
            'target' => 'agent://web1',
            'interval_seconds' => 60,
        ]);
        $this->base = "/v1/tenants/{$tenant->public_id}/environments/{$environment->public_id}/monitors/{$this->monitor->public_id}";
        $this->token = $this->rotate();
    }

    public function test_real_telegraf_payload_is_stored_cheaply(): void
    {
        $this->ingest(file_get_contents(base_path('tests/Fixtures/telegraf/linux-host.lp')))->assertNoContent();

        $keys = DB::table('agent_metric_latest')->where('monitor_id', $this->monitor->id)->pluck('value', 'metric_key');
        $this->assertCount(57, $keys);
        $this->assertEqualsWithDelta(31.7243, (float) $keys['mem.used_percent{host=web1}'], 1e-4);
        $this->assertEqualsWithDelta(0.95, (float) $keys['system.load5{host=web1}'], 1e-9);
        $this->assertNotNull($this->monitor->fresh()->last_ingest_at);
        $this->assertSame(0, DB::table('check_results')->count());
        $this->assertSame(0, DB::table('incidents')->count());
    }

    public function test_unknown_malformed_and_flag_off_tokens_look_the_same(): void
    {
        $unknown = $this->ingest('m f=1', 'sca_'.str_repeat('0', 48))->assertNotFound();
        $malformed = $this->ingest('m f=1', 'not-a-token')->assertNotFound();
        $this->assertSame($unknown->getContent(), $malformed->getContent());

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->ingest('m f=1', 'sca_'.str_repeat('1', 48));
        $unknownQueries = count(array_filter(DB::getQueryLog(), static fn ($q) => str_contains($q['query'], 'from "monitors"') || str_contains($q['query'], 'from `monitors`')));
        DB::flushQueryLog();
        $this->ingest('m f=1', 'garbage');
        $malformedQueries = count(array_filter(DB::getQueryLog(), static fn ($q) => str_contains($q['query'], 'from "monitors"') || str_contains($q['query'], 'from `monitors`')));
        DB::disableQueryLog();
        $this->assertSame(1, $unknownQueries);
        $this->assertSame($unknownQueries, $malformedQueries);

        config()->set('agent.enabled', false);
        $off = $this->ingest('m f=1')->assertNotFound();
        $this->assertSame($unknown->getContent(), $off->getContent());
        $this->assertSame(0, DB::table('agent_metric_latest')->count());
    }

    public function test_parse_errors_are_400_without_echoing_the_payload(): void
    {
        $response = $this->ingest("ok f=1\nsecret-host-name f=oops")->assertStatus(400);

        $this->assertSame('Line 2 is not valid line protocol', $response->json('message'));
        $this->assertStringNotContainsString('secret-host-name', $response->getContent());
        $this->assertSame(0, DB::table('agent_metric_latest')->count());
    }

    public function test_oversized_bodies_are_413(): void
    {
        config()->set('agent.max_bytes', 64);

        $this->ingest(str_repeat('m f=1'."\n", 20))->assertStatus(413);
        $this->ingest('m f=1', null, ['CONTENT_LENGTH' => '999999'])->assertStatus(413);
        $this->assertSame(0, DB::table('agent_metric_latest')->count());
    }

    public function test_gzip_bodies_are_accepted_with_a_decompressed_ceiling(): void
    {
        $this->ingest(gzencode("mem,host=web1 used_percent=12.5\n"), null, ['HTTP_CONTENT_ENCODING' => 'gzip'])->assertNoContent();
        $this->assertSame(12.5, (float) DB::table('agent_metric_latest')->value('value'));

        config()->set('agent.max_decompressed_bytes', 1024);
        $bomb = gzencode(str_repeat("m f=1\n", 10000));
        $this->assertLessThan(1024, strlen($bomb));
        $this->ingest($bomb, null, ['HTTP_CONTENT_ENCODING' => 'gzip'])->assertStatus(413);

        $this->ingest('not gzip at all', null, ['HTTP_CONTENT_ENCODING' => 'gzip'])->assertStatus(400);
    }

    public function test_protobuf_is_415_and_rate_limits_are_429(): void
    {
        $this->ingest('m f=1', null, ['CONTENT_TYPE' => 'application/x-protobuf'])->assertStatus(415);

        config()->set('agent.rate_per_token_per_minute', 2);
        $this->ingest('m f=1')->assertNoContent();
        $this->ingest('m f=2')->assertNoContent();
        $limited = $this->ingest('m f=3')->assertStatus(429);
        $this->assertNotNull($limited->headers->get('Retry-After'));
    }

    public function test_rotation_invalidates_the_previous_token_and_is_owner_only(): void
    {
        $old = $this->token;
        $new = $this->rotate();
        $this->assertNotSame($old, $new);
        $this->assertMatchesRegularExpression('/^sca_[0-9a-f]{48}$/', $new);

        $this->ingest('m f=1', $old)->assertNotFound();
        $this->ingest('m f=1', $new)->assertNoContent();

        $monitorJson = $this->actingAs($this->owner)->getJson($this->base)->assertOk()->getContent();
        $this->assertStringNotContainsString($new, $monitorJson);
        $this->assertStringNotContainsString('agent_token_hash', $monitorJson);

        $viewer = User::factory()->create();
        TenantMembership::query()->create(['tenant_id' => $this->monitor->tenant_id, 'user_id' => $viewer->id, 'role' => 'viewer']);
        $this->actingAs($viewer)->postJson("{$this->base}/rotate-agent-token")->assertForbidden();

        config()->set('agent.enabled', false);
        $this->actingAs($this->owner)->postJson("{$this->base}/rotate-agent-token")->assertNotFound();
    }

    public function test_only_agent_monitors_have_a_token(): void
    {
        $this->monitor->forceFill(['kind' => MonitorKind::HEARTBEAT])->save();

        $this->actingAs($this->owner)->postJson("{$this->base}/rotate-agent-token")->assertUnprocessable();
    }

    private function rotate(): string
    {
        return $this->actingAs($this->owner)->postJson("{$this->base}/rotate-agent-token")
            ->assertOk()
            ->assertJsonPath('meta.ingest_url', fn ($url) => str_contains($url, '/ingest/sca_'))
            ->json('meta.agent_token');
    }

    private function ingest(string $body, ?string $token = null, array $server = []): TestResponse
    {
        auth()->logout();

        return $this->call('POST', '/ingest/'.($token ?? $this->token), [], [], [], array_merge(['CONTENT_TYPE' => 'text/plain; charset=utf-8'], $server), $body);
    }
}
