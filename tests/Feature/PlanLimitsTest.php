<?php

namespace Tests\Feature;

use App\Application\Plans\PlanChangeService;
use App\Application\Tenancy\TenantService;
use App\Infrastructure\Persistence\Eloquent\AuditLog;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PlanFixture;
use Tests\TestCase;

/**
 * Plan limits (#119).
 */
class PlanLimitsTest extends TestCase
{
    use PlanFixture;
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Environment $environment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usePlanFixture();
        config(['agent.enabled' => true]);

        $this->user = User::factory()->create();
        $this->tenant = app(TenantService::class)->createTenant('Acme', 'acme', $this->user);
        $this->tenant->forceFill(['plan' => 'free'])->save();
        $this->environment = $this->tenant->environments()->firstOrFail();
    }

    private function base(): string
    {
        return "/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}";
    }

    private function createMonitor(array $overrides = [])
    {
        return $this->actingAs($this->user)->postJson($this->base().'/monitors', $overrides + [
            'name' => 'Site',
            'target' => 'http://target:8095/status/200',
        ]);
    }

    public function test_existing_and_cli_tenants_have_no_limits(): void
    {
        $other = app(TenantService::class)->createTenant('Other', 'other', $this->user);

        $this->assertSame('unlimited', $other->refresh()->plan);
    }

    public function test_free_plan_caps_enabled_monitors(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->createMonitor(['name' => "m{$i}"])->assertCreated();
        }

        $this->createMonitor()->assertStatus(422)->assertJsonValidationErrors('plan');
        // A paused monitor costs the checker nothing and is allowed.
        $paused = $this->createMonitor(['enabled' => false])->assertCreated()->json('data.public_id');

        $this->actingAs($this->user)->putJson($this->base()."/monitors/{$paused}", ['enabled' => true])
            ->assertStatus(422)->assertJsonValidationErrors('plan');
    }

    public function test_missing_interval_defaults_to_plan_minimum_and_lower_is_refused(): void
    {
        $created = $this->createMonitor()->assertCreated();
        $this->assertSame(180, $created->json('data.interval_seconds'));

        $this->createMonitor(['interval_seconds' => 60])->assertStatus(422)->assertJsonValidationErrors('interval_seconds');

        $id = $created->json('data.public_id');
        $this->actingAs($this->user)->putJson($this->base()."/monitors/{$id}", ['interval_seconds' => 120])
            ->assertStatus(422)->assertJsonValidationErrors('interval_seconds');
        $this->actingAs($this->user)->putJson($this->base()."/monitors/{$id}", ['interval_seconds' => 600])->assertOk();
    }

    public function test_free_plan_allows_one_agent_monitor(): void
    {
        $this->createMonitor(['kind' => 'agent', 'target' => null])->assertCreated();
        $this->createMonitor(['kind' => 'agent', 'target' => null])->assertStatus(422)->assertJsonValidationErrors('plan');

        $this->tenant->forceFill(['plan' => 'pro'])->save();
        $this->createMonitor(['kind' => 'agent', 'target' => null])->assertCreated();
    }

    public function test_free_plan_has_no_custom_domain(): void
    {
        $this->actingAs($this->user)->putJson($this->base().'/status-page', ['slug' => 'acme', 'title' => 'Acme', 'custom_domain' => 'status.acme.test'])
            ->assertStatus(422)->assertJsonValidationErrors('custom_domain');
    }

    public function test_downgrade_pauses_extra_monitors_raises_intervals_and_removes_custom_domain(): void
    {
        $this->tenant->forceFill(['plan' => 'pro'])->save();
        $ids = [];
        for ($i = 0; $i < 11; $i++) {
            $ids[] = $this->createMonitor(['name' => "m{$i}", 'interval_seconds' => 60])->assertCreated()->json('data.public_id');
        }
        $this->createMonitor(['kind' => 'agent', 'target' => null])->assertCreated();
        $this->createMonitor(['kind' => 'agent', 'target' => null])->assertCreated();
        $this->actingAs($this->user)->putJson($this->base().'/status-page', ['slug' => 'acme', 'title' => 'Acme', 'custom_domain' => 'status.acme.test'])->assertSuccessful();

        $effects = app(PlanChangeService::class)->change($this->tenant, 'free', null, 'test');

        $this->assertSame(3, $effects['paused']); // the newest agent monitor, then the two newest of the rest
        $this->assertSame(10, Monitor::query()->where('tenant_id', $this->tenant->id)->where('enabled', true)->count());
        $this->assertTrue(Monitor::query()->where('public_id', $ids[0])->value('enabled'));
        $this->assertFalse(Monitor::query()->where('public_id', $ids[10])->value('enabled'));
        $this->assertSame(0, Monitor::query()->where('tenant_id', $this->tenant->id)->where('interval_seconds', '<', 180)->count());
        $this->assertNull(DB::table('status_pages')->where('tenant_id', $this->tenant->id)->value('custom_domain'));
        $this->assertTrue(AuditLog::query()->where('action', 'tenant.plan_changed')->exists());
    }

    public function test_platform_admin_changes_plan_and_others_cannot(): void
    {
        $this->actingAs($this->user)->patchJson("/v1/platform/tenants/{$this->tenant->public_id}", ['plan' => 'pro'])->assertForbidden();

        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($admin)->patchJson("/v1/platform/tenants/{$this->tenant->public_id}", ['plan' => 'business'])
            ->assertOk()->assertJsonPath('data.plan', 'business');
        $this->actingAs($admin)->patchJson("/v1/platform/tenants/{$this->tenant->public_id}", ['plan' => 'gold'])->assertStatus(422);

        $this->actingAs($admin)->getJson('/v1/platform/tenants')->assertOk()->assertJsonPath('data.0.plan', 'business');
    }
}
