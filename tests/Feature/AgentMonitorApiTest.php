<?php

namespace Tests\Feature;

use App\Application\Tenancy\TenantService;
use App\Infrastructure\Persistence\Eloquent\TenantMembership;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AgentMonitorApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $viewer;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('agent.enabled', true);

        $this->owner = User::factory()->create();
        $tenant = app(TenantService::class)->createTenant('Acme', 'acme', $this->owner);
        $environment = $tenant->environments()->firstOrFail();
        $this->viewer = User::factory()->create();
        TenantMembership::query()->create(['tenant_id' => $tenant->id, 'user_id' => $this->viewer->id, 'role' => 'viewer']);
        $this->base = "/v1/tenants/{$tenant->public_id}/environments/{$environment->public_id}/monitors";
    }

    public function test_owner_creates_an_agent_monitor_and_sees_the_token_once(): void
    {
        $response = $this->actingAs($this->owner)->postJson($this->base, ['name' => 'web1', 'kind' => 'agent', 'interval_seconds' => 60, 'agent_grace_seconds' => 120])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'agent')
            ->assertJsonPath('data.agent_grace_seconds', 120);
        $token = $response->json('meta.agent_token');
        $this->assertMatchesRegularExpression('/^sca_[0-9a-f]{48}$/', $token);
        $this->assertStringEndsWith('/ingest/'.$token, $response->json('meta.ingest_url'));
        $id = $response->json('data.public_id');

        foreach ([$this->owner, $this->viewer] as $user) {
            $read = $this->actingAs($user)->getJson("{$this->base}/{$id}")->assertOk()->getContent();
            $this->assertStringNotContainsString($token, $read);
            $list = $this->actingAs($user)->getJson($this->base)->assertOk()->getContent();
            $this->assertStringNotContainsString($token, $list);
        }

        $this->actingAs($this->owner)->postJson($this->base, ['name' => 'too fast', 'kind' => 'agent', 'interval_seconds' => 30])->assertUnprocessable()->assertJsonValidationErrors(['interval_seconds']);
        $this->actingAs($this->owner)->putJson("{$this->base}/{$id}", ['kind' => 'http'])->assertUnprocessable()->assertJsonValidationErrors(['kind']);
        $this->actingAs($this->owner)->putJson("{$this->base}/{$id}", ['agent_grace_seconds' => 300, 'interval_seconds' => 120])->assertOk()->assertJsonPath('data.agent_grace_seconds', 300);
    }

    public function test_latency_metric_key_is_accepted_only_on_agent_monitors(): void
    {
        $key = 'app_latency.query_p95_ms{host=acme}';
        $id = $this->actingAs($this->owner)->postJson($this->base, ['name' => 'portal', 'kind' => 'agent', 'agent_latency_metric_key' => $key])
            ->assertCreated()
            ->assertJsonPath('data.agent_latency_metric_key', $key)
            ->json('data.public_id');
        $this->assertSame($key, DB::table('monitors')->where('public_id', $id)->value('agent_latency_metric_key'));

        $this->actingAs($this->owner)->putJson("{$this->base}/{$id}", ['agent_latency_metric_key' => '  query_p95_ms  '])->assertOk()->assertJsonPath('data.agent_latency_metric_key', 'query_p95_ms');
        $this->actingAs($this->owner)->putJson("{$this->base}/{$id}", ['name' => 'renamed'])->assertOk()->assertJsonPath('data.agent_latency_metric_key', 'query_p95_ms');
        $this->actingAs($this->owner)->putJson("{$this->base}/{$id}", ['agent_latency_metric_key' => str_repeat('a', 256)])->assertUnprocessable()->assertJsonValidationErrors(['agent_latency_metric_key']);
        $this->actingAs($this->owner)->putJson("{$this->base}/{$id}", ['agent_latency_metric_key' => ['x']])->assertUnprocessable()->assertJsonValidationErrors(['agent_latency_metric_key']);
        $this->actingAs($this->owner)->putJson("{$this->base}/{$id}", ['agent_latency_metric_key' => null])->assertOk()->assertJsonPath('data.agent_latency_metric_key', null);
        $this->actingAs($this->viewer)->putJson("{$this->base}/{$id}", ['agent_latency_metric_key' => $key])->assertForbidden();

        $this->actingAs($this->owner)->postJson($this->base, ['name' => 'site', 'kind' => 'http', 'target' => 'http://target:8095/status/200', 'agent_latency_metric_key' => $key])
            ->assertUnprocessable()->assertJsonValidationErrors(['agent_latency_metric_key']);
        $hb = $this->actingAs($this->owner)->postJson($this->base, ['name' => 'site', 'kind' => 'http', 'target' => 'http://target:8095/status/200'])->assertCreated()->assertJsonPath('data.agent_latency_metric_key', null)->json('data.public_id');
        $this->actingAs($this->owner)->putJson("{$this->base}/{$hb}", ['agent_latency_metric_key' => $key])->assertUnprocessable()->assertJsonValidationErrors(['agent_latency_metric_key']);
        $this->actingAs($this->owner)->putJson("{$this->base}/{$hb}", ['agent_latency_metric_key' => null])->assertOk();
    }

    public function test_viewer_cannot_create_agents_or_edit_rules_but_can_read_metrics_and_rules(): void
    {
        $this->actingAs($this->viewer)->postJson($this->base, ['name' => 'nope', 'kind' => 'agent'])->assertForbidden();

        $id = $this->actingAs($this->owner)->postJson($this->base, ['name' => 'web1', 'kind' => 'agent'])->json('data.public_id');
        $rule = $this->actingAs($this->owner)->postJson("{$this->base}/{$id}/agent-rules", [
            'metric_key' => 'mem.used_percent{host=web1}', 'operator' => 'gt', 'expected' => '90', 'severity' => 'down',
        ])->assertCreated()->assertJsonPath('data.missing_policy', 'ignore')->json('data');
        $this->assertStringStartsWith('amr_', $rule['id']);

        $this->actingAs($this->viewer)->getJson("{$this->base}/{$id}/agent-rules")->assertOk()->assertJsonPath('data.0.operator', 'gt');
        $this->actingAs($this->viewer)->getJson("{$this->base}/{$id}/agent-metrics")->assertOk()->assertJsonPath('meta.total', 0);
        $this->actingAs($this->viewer)->postJson("{$this->base}/{$id}/agent-rules", ['metric_key' => 'x', 'operator' => 'gt', 'expected' => '1'])->assertForbidden();
        $this->actingAs($this->viewer)->putJson("{$this->base}/{$id}/agent-rules/{$rule['id']}", ['expected' => '95'])->assertForbidden();
        $this->actingAs($this->viewer)->deleteJson("{$this->base}/{$id}/agent-rules/{$rule['id']}")->assertForbidden();
        $this->actingAs($this->viewer)->postJson("{$this->base}/{$id}/rotate-agent-token")->assertForbidden();

        $this->actingAs($this->owner)->putJson("{$this->base}/{$id}/agent-rules/{$rule['id']}", ['operator' => 'between', 'expected' => '10..20', 'severity' => 'degraded'])
            ->assertOk()->assertJsonPath('data.severity', 'degraded');
        $this->actingAs($this->owner)->deleteJson("{$this->base}/{$id}/agent-rules/{$rule['id']}")->assertNoContent();
        $this->assertSame(0, DB::table('agent_metric_rules')->count());
    }

    public function test_rule_validation_and_limits(): void
    {
        config()->set('agent.max_rules_per_monitor', 1);
        $id = $this->actingAs($this->owner)->postJson($this->base, ['name' => 'web1', 'kind' => 'agent'])->json('data.public_id');

        $this->actingAs($this->owner)->postJson("{$this->base}/{$id}/agent-rules", ['metric_key' => 'k', 'operator' => 'bogus', 'expected' => '1'])->assertJsonValidationErrors(['operator']);
        $this->actingAs($this->owner)->postJson("{$this->base}/{$id}/agent-rules", ['metric_key' => 'k', 'operator' => 'gt', 'expected' => 'lots'])->assertJsonValidationErrors(['expected']);
        $this->actingAs($this->owner)->postJson("{$this->base}/{$id}/agent-rules", ['metric_key' => 'k', 'operator' => 'between', 'expected' => '9..1'])->assertJsonValidationErrors(['expected']);
        $this->actingAs($this->owner)->postJson("{$this->base}/{$id}/agent-rules", ['metric_key' => 'k', 'operator' => 'gt', 'expected' => '1'])->assertCreated();
        $this->actingAs($this->owner)->postJson("{$this->base}/{$id}/agent-rules", ['metric_key' => 'k2', 'operator' => 'gt', 'expected' => '1'])->assertJsonValidationErrors(['metric_key']);
    }

    public function test_latest_metrics_are_listed_with_age_and_search(): void
    {
        $response = $this->actingAs($this->owner)->postJson($this->base, ['name' => 'web1', 'kind' => 'agent']);
        $id = $response->json('data.public_id');
        auth()->logout();
        $this->call('POST', '/ingest/'.$response->json('meta.agent_token'), [], [], [], ['CONTENT_TYPE' => 'text/plain'], "mem,host=web1 used_percent=40\nsystem,host=web1 load1=0.5,load5=0.4")->assertNoContent();

        $this->actingAs($this->viewer)->getJson("{$this->base}/{$id}/agent-metrics")
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.metric_key', 'mem.used_percent{host=web1}')
            ->assertJsonPath('data.0.value', 40)
            ->assertJsonPath('data.0.age_seconds', fn ($age) => $age >= 0 && $age < 60);
        $this->actingAs($this->viewer)->getJson("{$this->base}/{$id}/agent-metrics?search=load&per_page=1")
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(1, 'data');
    }

    public function test_flag_off_refuses_agent_kind_and_hides_agent_routes(): void
    {
        $id = $this->actingAs($this->owner)->postJson($this->base, ['name' => 'web1', 'kind' => 'agent'])->json('data.public_id');
        config()->set('agent.enabled', false);

        $this->actingAs($this->owner)->postJson($this->base, ['name' => 'web2', 'kind' => 'agent'])->assertUnprocessable();
        $this->actingAs($this->owner)->getJson("{$this->base}/{$id}/agent-metrics")->assertNotFound();
        $this->actingAs($this->owner)->getJson("{$this->base}/{$id}/agent-rules")->assertNotFound();
        $this->actingAs($this->owner)->getJson('/v1/me')->assertJsonPath('meta.features.agent_ingest', false);

        config()->set('agent.enabled', true);
        $this->actingAs($this->owner)->getJson('/v1/me')->assertJsonPath('meta.features.agent_ingest', true);
    }

    public function test_agent_routes_404_for_non_agent_monitors(): void
    {
        $id = $this->actingAs($this->owner)->postJson($this->base, ['name' => 'site', 'kind' => 'http', 'target' => 'http://target:8095/status/200'])->json('data.public_id');

        $this->actingAs($this->owner)->getJson("{$this->base}/{$id}/agent-metrics")->assertNotFound();
        $this->actingAs($this->owner)->postJson("{$this->base}/{$id}/agent-rules", ['metric_key' => 'k', 'operator' => 'gt', 'expected' => '1'])->assertNotFound();
    }
}
