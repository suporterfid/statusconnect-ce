<?php

namespace Tests\Feature;

use App\Application\Monitoring\MonitorService;
use App\Application\Scheduling\CheckExecutor;
use App\Application\Tenancy\TenantService;
use App\Domain\Monitoring\CheckState;
use App\Domain\Monitoring\MonitorKind;
use App\Infrastructure\Persistence\Eloquent\Incident;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HeartbeatIncidentTest extends TestCase
{
    use RefreshDatabase;

    public function test_heartbeat_token_generation_and_rotation(): void
    {
        $user = User::factory()->create();
        /** @var TenantService $tenantService */
        $tenantService = app(TenantService::class);
        $tenant = $tenantService->createTenant('Acme Corp', 'acme-hb-gen', $user);
        $env = $tenant->environments()->firstOrFail();

        /** @var MonitorService $monitorService */
        $monitorService = app(MonitorService::class);

        $monitor = $monitorService->createMonitor($tenant, $env, [
            'name' => 'Cron Backup Heartbeat',
            'kind' => 'heartbeat',
            'interval_seconds' => 3600,
            'heartbeat_grace_seconds' => 300,
        ]);

        $this->assertNotNull($monitor->heartbeat_token);
        $this->assertStringStartsWith('hb_', $monitor->heartbeat_token);

        $oldToken = $monitor->heartbeat_token;
        $rotated = $monitorService->rotateHeartbeatToken($tenant, $env, $monitor->public_id);

        $this->assertNotEquals($oldToken, $rotated->heartbeat_token);
        $this->assertStringStartsWith('hb_', $rotated->heartbeat_token);
    }

    public function test_missed_heartbeat_opens_incident_after_confirmation_threshold(): void
    {
        $user = User::factory()->create();
        /** @var TenantService $tenantService */
        $tenantService = app(TenantService::class);
        $tenant = $tenantService->createTenant('Acme Corp', 'acme-hb-inc', $user);
        $env = $tenant->environments()->firstOrFail();

        $monitor = Monitor::create([
            'tenant_id' => $tenant->id,
            'environment_id' => $env->id,
            'name' => 'Missed Heartbeat Monitor',
            'kind' => MonitorKind::HEARTBEAT,
            'current_state' => CheckState::PENDING,
            'target' => "heartbeat://{$tenant->public_id}",
            'heartbeat_token' => 'hb_incident_test_token',
            'interval_seconds' => 60,
            'heartbeat_grace_seconds' => 30,
            'confirmation_threshold' => 2,
            'last_ping_at' => now()->subMinutes(10), // Ping missed long ago
        ]);

        /** @var CheckExecutor $executor */
        $executor = app(CheckExecutor::class);

        // First missed check -> state DOWN, consecutive_failures = 1, no incident yet
        $result1 = $executor->execute($monitor);
        $monitor->refresh();
        $this->assertEquals(CheckState::DOWN, $result1->state);
        $this->assertEquals(1, $monitor->consecutive_failures);
        $this->assertDatabaseCount('incidents', 0);

        // Second missed check -> reaches confirmation_threshold 2 -> opens incident!
        $result2 = $executor->execute($monitor);
        $monitor->refresh();
        $this->assertEquals(2, $monitor->consecutive_failures);
        $this->assertDatabaseCount('incidents', 1);

        $incident = Incident::first();
        $this->assertFalse($incident->resolved_flag);
        $this->assertNull($incident->resolved_at);
    }
}
