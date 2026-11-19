<?php

namespace Tests\Feature;

use App\Application\Tenancy\TenantService;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Agent flow end to end, through the same HTTP surfaces the SPA and Telegraf use (#78):
 * create the monitor, ingest a real Telegraf payload, add a rule, run the minute cron,
 * then stop ingesting and let the grace period expire.
 */
class AgentEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('agent.enabled', true);

        $this->owner = User::factory()->create();
        $tenant = app(TenantService::class)->createTenant('Acme', 'acme', $this->owner);
        $environment = $tenant->environments()->firstOrFail();
        $this->base = "/v1/tenants/{$tenant->public_id}/environments/{$environment->public_id}/monitors";
    }

    public function test_telegraf_payload_rule_incident_and_silence(): void
    {
        $created = $this->actingAs($this->owner)->postJson($this->base, [
            'name' => 'web1',
            'kind' => 'agent',
            'interval_seconds' => 60,
            'agent_grace_seconds' => 60,
            'confirmation_threshold' => 1,
            'recovery_threshold' => 1,
        ])->assertCreated();
        $id = $created->json('data.public_id');
        $token = $created->json('meta.agent_token');

        // Telegraf's outputs.http with content_encoding = "gzip".
        $payload = gzencode(file_get_contents(base_path('tests/Fixtures/telegraf/linux-host.lp')));
        $this->ingest($token, $payload, ['HTTP_CONTENT_ENCODING' => 'gzip'])->assertNoContent();

        $metrics = $this->actingAs($this->owner)->getJson("{$this->base}/{$id}/agent-metrics?search=mem.used_percent")->assertOk();
        $metrics->assertJsonPath('data.0.metric_key', 'mem.used_percent{host=web1}');

        $rule = $this->actingAs($this->owner)->postJson("{$this->base}/{$id}/agent-rules", [
            'metric_key' => 'mem.used_percent{host=web1}', 'operator' => 'gt', 'expected' => '0', 'severity' => 'down',
        ])->assertCreated()->json('data.id');

        $this->tick($id);
        $this->assertSame('down', $this->lastResult($id)->state);
        $this->assertStringStartsWith('mem.used_percent{host=web1}=', $this->lastResult($id)->failure_reason);
        $this->assertSame(1, DB::table('incidents')->whereNull('resolved_at')->count());

        $this->actingAs($this->owner)->deleteJson("{$this->base}/{$id}/agent-rules/{$rule}")->assertNoContent();
        $this->ingest($token, $payload, ['HTTP_CONTENT_ENCODING' => 'gzip'])->assertNoContent();
        $this->tick($id);
        $this->assertSame('up', $this->lastResult($id)->state);
        $this->assertSame(0, DB::table('incidents')->whereNull('resolved_at')->count());

        // Telegraf stops: after interval + grace (120 s) the monitor goes down with no_data.
        // The scheduler reads a system clock, so age the last ingestion instead of travelling.
        Monitor::query()->where('public_id', $id)->update(['last_ingest_at' => now()->subSeconds(121)]);
        $this->tick($id);
        $this->assertSame('down', $this->lastResult($id)->state);
        $this->assertStringStartsWith('no_data', $this->lastResult($id)->failure_reason);
        $this->assertSame(1, DB::table('incidents')->whereNull('resolved_at')->count());
    }

    private function ingest(string $token, string $body, array $server = []): TestResponse
    {
        auth()->logout();

        return $this->call('POST', '/ingest/'.$token, [], [], [], array_merge(['CONTENT_TYPE' => 'text/plain; charset=utf-8'], $server), $body);
    }

    private function tick(string $publicId): void
    {
        Monitor::query()->where('public_id', $publicId)->update(['next_check_at' => now()->subSecond(), 'claim_token' => null]);
        $this->artisan('monitor:check-due')->assertSuccessful();
    }

    private function lastResult(string $publicId): object
    {
        $monitorId = Monitor::query()->where('public_id', $publicId)->value('id');

        return DB::table('check_results')->where('monitor_id', $monitorId)->orderByDesc('id')->first();
    }
}
