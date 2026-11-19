<?php

namespace Tests\Feature;

use App\Application\ApiKeys\ApiKeyService;
use App\Application\Plans\PlanChangeService;
use App\Application\Scheduling\DueMonitorClaimer;
use App\Application\Tenancy\AccountProvisioningService;
use App\Application\Tenancy\ProvisionedAccount;
use App\Domain\Monitoring\CheckState;
use App\Infrastructure\Persistence\Eloquent\AuditLog;
use App\Infrastructure\Persistence\Eloquent\Incident;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\PlanFixture;
use Tests\TestCase;

/**
 * Pausing and resuming a monitor (#246): state, streak, open incident,
 * status page and audit, on every path that flips `enabled`.
 */
class MonitorPauseTest extends TestCase
{
    use PlanFixture;
    use RefreshDatabase;

    private ProvisionedAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = app(AccountProvisioningService::class)->provision([
            'email' => 'owner@acme.test', 'name' => 'Owner', 'org' => 'Acme', 'slug' => 'acme',
        ]);
    }

    private function base(): string
    {
        return "/v1/tenants/{$this->account->tenant->public_id}/environments/{$this->account->environment->public_id}";
    }

    /** A monitor that is down with an open automatic incident. */
    private function downMonitor(string $name = 'Loja'): Monitor
    {
        $monitor = Monitor::query()->create([
            'tenant_id' => $this->account->tenant->id,
            'environment_id' => $this->account->environment->id,
            'name' => $name,
            'kind' => 'http',
            'target' => 'https://loja.example.test',
            'enabled' => true,
            'current_state' => CheckState::DOWN,
            'consecutive_failures' => 7,
            'consecutive_successes' => 0,
            'first_failure_at' => now()->subHour(),
            'second_location_up_until' => now()->addMinutes(5),
            'next_check_at' => now()->addMinute(),
            'claim_token' => 'in-flight',
            'claimed_at' => now(),
            'claim_expires_at' => now()->addMinutes(5),
        ]);
        Incident::query()->create([
            'tenant_id' => $monitor->tenant_id,
            'environment_id' => $monitor->environment_id,
            'monitor_id' => $monitor->id,
            'manual' => false,
            'resolved_flag' => false,
            'started_at' => now()->subHour(),
            'confirmed_at' => now()->subMinutes(55),
            'severity' => 'major',
            'summary' => 'Connection refused',
        ]);

        return $monitor;
    }

    private function manualIncident(): Incident
    {
        return Incident::query()->create([
            'tenant_id' => $this->account->tenant->id,
            'environment_id' => $this->account->environment->id,
            'monitor_id' => null,
            'manual' => true,
            'resolved_flag' => false,
            'started_at' => now()->subHour(),
            'severity' => 'minor',
            'summary' => 'Planned work on the warehouse',
        ]);
    }

    private function pauseViaApi(Monitor $monitor, bool $enabled = false): TestResponse
    {
        return $this->actingAs($this->account->user)
            ->putJson($this->base()."/monitors/{$monitor->public_id}", ['enabled' => $enabled]);
    }

    public function test_api_pause_sets_paused_clears_the_streak_and_resolves_the_open_automatic_incident(): void
    {
        $monitor = $this->downMonitor();
        $manual = $this->manualIncident();

        $this->pauseViaApi($monitor)->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.current_state', 'paused');

        $monitor->refresh();
        $this->assertFalse($monitor->enabled);
        $this->assertSame(CheckState::PAUSED, $monitor->current_state);
        $this->assertNotNull($monitor->paused_at);
        $this->assertSame(0, $monitor->consecutive_failures);
        $this->assertNull($monitor->first_failure_at);
        $this->assertNull($monitor->second_location_up_until);
        $this->assertNull($monitor->claim_token);

        $incident = Incident::query()->where('monitor_id', $monitor->id)->firstOrFail();
        $this->assertNotNull($incident->resolved_at);
        $this->assertNull($incident->resolved_flag);
        $this->assertSame(3600, $incident->duration_seconds);
        $update = $incident->updates()->sole();
        $this->assertSame('resolved', $update->status);
        $this->assertStringContainsString('paused', $update->message);

        $this->assertNull($manual->fresh()->resolved_at);
        $this->assertFalse($manual->fresh()->resolved_flag);

        $audit = AuditLog::query()->where('action', 'monitor.paused')->sole();
        $this->assertSame($monitor->public_id, $audit->resource_id);
        $this->assertSame($this->account->user->id, $audit->actor_user_id);
        $this->assertSame('api', $audit->summary_json['source']);
        $this->assertSame($incident->public_id, $audit->summary_json['resolved_incident']);
    }

    public function test_status_page_shows_a_paused_monitor_as_no_data(): void
    {
        $monitor = $this->downMonitor();
        $pageId = (int) DB::table('status_pages')->where('environment_id', $this->account->environment->id)->value('id');
        $componentId = DB::table('status_page_components')->insertGetId([
            'status_page_id' => $pageId, 'name' => 'Loja', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('status_page_component_monitors')->insert([
            'status_page_component_id' => $componentId, 'monitor_id' => $monitor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('status_page_cache')->update(['generated_at' => now()->subMinutes(2)]);
        $this->artisan('monitor:rollup')->assertSuccessful();
        $payload = fn (): array => json_decode((string) DB::table('status_page_cache')->where('status_page_id', $pageId)->value('payload_json'), true);
        $this->assertSame('major_outage', $payload()['components'][0]['state']);
        // The snapshot is older than the pause.
        DB::table('status_page_cache')->update(['generated_at' => now()->subMinute()]);

        $this->pauseViaApi($monitor)->assertOk();

        $this->assertSame('no_data', $payload()['components'][0]['state']);
        $this->assertSame('no_data', $payload()['overall_state']);
        $this->assertNotNull($payload()['incidents'][0]['resolved_at']);
    }

    public function test_resume_starts_fresh_as_pending_and_due_now(): void
    {
        $monitor = $this->downMonitor();
        $this->pauseViaApi($monitor)->assertOk();
        Monitor::query()->whereKey($monitor->id)->update(['next_check_at' => now()->addDay()]);

        $this->pauseViaApi($monitor, enabled: true)->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.current_state', 'pending')
            ->assertJsonPath('data.paused_at', null);

        $monitor->refresh();
        $this->assertSame(CheckState::PENDING, $monitor->current_state);
        $this->assertNull($monitor->paused_at);
        $this->assertSame(0, $monitor->consecutive_failures);
        $this->assertSame(0, $monitor->consecutive_successes);
        $this->assertNull($monitor->first_failure_at);
        $this->assertTrue($monitor->next_check_at->lessThanOrEqualTo(now()));
        $this->assertTrue(AuditLog::query()->where('action', 'monitor.resumed')->where('resource_id', $monitor->public_id)->exists());

        $claimed = app(DueMonitorClaimer::class)->claimDueMonitors();
        $this->assertSame([$monitor->id], $claimed->pluck('id')->all());
    }

    public function test_pausing_a_paused_monitor_and_resuming_a_running_one_change_nothing(): void
    {
        $monitor = $this->downMonitor();
        $this->pauseViaApi($monitor)->assertOk();
        $pausedAt = $monitor->fresh()->paused_at;
        $this->travel(2)->minutes();

        $this->pauseViaApi($monitor)->assertOk();
        $this->assertEquals($pausedAt, $monitor->fresh()->paused_at);
        $this->assertSame(1, AuditLog::query()->where('action', 'monitor.paused')->count());
        $this->assertSame(1, DB::table('incident_updates')->count());

        $this->pauseViaApi($monitor, enabled: true)->assertOk();
        $nextCheck = $monitor->fresh()->next_check_at;
        Monitor::query()->whereKey($monitor->id)->update(['current_state' => 'up', 'consecutive_successes' => 3]);
        $this->travel(2)->minutes();

        $this->pauseViaApi($monitor, enabled: true)->assertOk();
        $fresh = $monitor->fresh();
        $this->assertSame(CheckState::UP, $fresh->current_state);
        $this->assertSame(3, $fresh->consecutive_successes);
        $this->assertEquals($nextCheck, $fresh->next_check_at);
        $this->assertSame(1, AuditLog::query()->where('action', 'monitor.resumed')->count());

        // A change without `enabled` leaves the state alone.
        $this->actingAs($this->account->user)
            ->putJson($this->base()."/monitors/{$monitor->public_id}", ['name' => 'Loja online'])->assertOk();
        $this->assertSame(CheckState::UP, $monitor->fresh()->current_state);
        $this->assertSame(2, AuditLog::query()->whereIn('action', ['monitor.paused', 'monitor.resumed'])->count());
    }

    public function test_pausing_repairs_a_monitor_paused_before_the_fix(): void
    {
        $monitor = $this->downMonitor();
        Monitor::query()->whereKey($monitor->id)->update(['enabled' => false]);

        $this->pauseViaApi($monitor)->assertOk()->assertJsonPath('data.current_state', 'paused');

        $this->assertNotNull(Incident::query()->where('monitor_id', $monitor->id)->value('resolved_at'));
    }

    public function test_monitor_created_paused_is_in_the_paused_state(): void
    {
        $this->actingAs($this->account->user)->postJson($this->base().'/monitors', [
            'name' => 'Later', 'target' => 'http://target:8095/status/200', 'enabled' => false,
        ])->assertCreated()
            ->assertJsonPath('data.current_state', 'paused')
            ->assertJsonPath('data.enabled', false);
    }

    public function test_mcp_pause_and_resume_go_through_the_same_transition(): void
    {
        $monitor = $this->downMonitor();
        $key = app(ApiKeyService::class)->create(
            $this->account->tenant, $this->account->user, 'MCP', ['monitors:operate'], $this->account->environment,
        );
        $call = fn (string $tool) => $this->withHeaders(['Authorization' => 'Bearer '.$key['plaintext']])->postJson('/v1/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => ['monitor_id' => $monitor->public_id]],
        ])->assertJsonPath('result.isError', false);

        $call('pause_monitor');
        $monitor->refresh();
        $this->assertSame(CheckState::PAUSED, $monitor->current_state);
        $this->assertNotNull($monitor->paused_at);
        $this->assertNotNull(Incident::query()->where('monitor_id', $monitor->id)->value('resolved_at'));
        $audit = AuditLog::query()->where('action', 'monitor.paused')->sole();
        $this->assertSame('mcp', $audit->summary_json['source']);
        $this->assertNull($audit->actor_user_id);

        $call('resume_monitor');
        $monitor->refresh();
        $this->assertTrue($monitor->enabled);
        $this->assertSame(CheckState::PENDING, $monitor->current_state);
        $this->assertNull($monitor->paused_at);
        $this->assertSame('mcp', AuditLog::query()->where('action', 'monitor.resumed')->sole()->summary_json['source']);
    }

    public function test_plan_downgrade_pauses_through_the_same_transition(): void
    {
        $this->usePlanFixture();
        $this->account->tenant->forceFill(['plan' => 'pro'])->save();
        for ($i = 0; $i < 10; $i++) {
            Monitor::query()->create([
                'tenant_id' => $this->account->tenant->id,
                'environment_id' => $this->account->environment->id,
                'name' => "m{$i}", 'kind' => 'http', 'target' => "https://m{$i}.example.test",
                'interval_seconds' => 300, 'enabled' => true,
            ]);
        }
        $newest = $this->downMonitor('Newest');

        $effects = app(PlanChangeService::class)->change($this->account->tenant, 'free', null, 'test');

        $this->assertSame(1, $effects['paused']);
        $newest->refresh();
        $this->assertFalse($newest->enabled);
        $this->assertSame(CheckState::PAUSED, $newest->current_state);
        $this->assertNotNull($newest->paused_at);
        $this->assertSame(0, $newest->consecutive_failures);
        $this->assertNotNull(Incident::query()->where('monitor_id', $newest->id)->value('resolved_at'));
        $audit = AuditLog::query()->where('action', 'monitor.paused')->sole();
        $this->assertSame($newest->public_id, $audit->resource_id);
        $this->assertSame('plan_downgrade', $audit->summary_json['source']);
    }
}
