<?php

namespace Tests\Feature;

use App\Application\Tenancy\TenantService;
use App\Domain\Monitoring\MonitorKind;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The `array` cache store used in tests persists for the whole test
        // run, not per-test, and the /ping throttle key is IP-based — reset
        // it here so each test starts with a clean budget regardless of
        // what earlier tests in this (or another) class already consumed.
        \Illuminate\Support\Facades\Cache::flush();
    }

    public function test_valid_ping_records_last_ping_at_and_returns_204(): void
    {
        $user = User::factory()->create();
        /** @var TenantService $tenantService */
        $tenantService = app(TenantService::class);
        $tenant = $tenantService->createTenant('Acme Corp', 'acme-ping', $user);
        $env = $tenant->environments()->firstOrFail();

        $monitor = Monitor::create([
            'tenant_id' => $tenant->id,
            'environment_id' => $env->id,
            'name' => 'Test Ping Monitor',
            'kind' => MonitorKind::HEARTBEAT,
            'target' => "heartbeat://{$tenant->public_id}",
            'heartbeat_token' => 'hb_testtoken1234567890abcdef',
            'enabled' => true,
            'last_ping_at' => null,
        ]);

        $response = $this->getJson('/ping/hb_testtoken1234567890abcdef');

        $response->assertStatus(204);
        $this->assertEmpty($response->getContent());

        $monitor->refresh();
        $this->assertNotNull($monitor->last_ping_at);
    }

    public function test_post_ping_works_identically(): void
    {
        $user = User::factory()->create();
        /** @var TenantService $tenantService */
        $tenantService = app(TenantService::class);
        $tenant = $tenantService->createTenant('Acme Corp', 'acme-post-ping', $user);
        $env = $tenant->environments()->firstOrFail();

        $monitor = Monitor::create([
            'tenant_id' => $tenant->id,
            'environment_id' => $env->id,
            'name' => 'Post Ping Monitor',
            'kind' => MonitorKind::HEARTBEAT,
            'target' => "heartbeat://{$tenant->public_id}",
            'heartbeat_token' => 'hb_testtoken9876543210abcdef',
            'enabled' => true,
            'last_ping_at' => null,
        ]);

        $response = $this->postJson('/ping/hb_testtoken9876543210abcdef');

        $response->assertStatus(204);
        $monitor->refresh();
        $this->assertNotNull($monitor->last_ping_at);
    }

    public function test_unknown_token_returns_404(): void
    {
        $response = $this->getJson('/ping/hb_nonexistent_token');

        $response->assertStatus(404);
    }

    /**
     * Regression for #38: the /ping route had no HTTP-layer throttle, so a
     * flood always reached HeartbeatPingService's own DB-backed limiter
     * (a per-request DB round-trip) instead of being rejected cheaply.
     */
    public function test_flooding_the_ping_route_is_throttled(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/ping/hb_flood_test_token')->assertStatus(404);
        }

        $this->getJson('/ping/hb_flood_test_token')->assertStatus(429);
    }
}
