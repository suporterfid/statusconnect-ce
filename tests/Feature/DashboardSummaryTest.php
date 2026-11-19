<?php

namespace Tests\Feature;

use App\Application\ApiKeys\ApiKeyService;
use App\Application\Tenancy\TenantService;
use App\Infrastructure\Persistence\Eloquent\CheckResult;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Incident;
use App\Infrastructure\Persistence\Eloquent\MaintenanceWindow;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage for #39: DashboardView.vue showed a hardcoded "99.98%" uptime
 * and "0" active-maintenance count regardless of real data.
 */
class DashboardSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Environment $environment;

    private string $apiKeyPlain;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        /** @var TenantService $tenantService */
        $tenantService = app(TenantService::class);
        $this->tenant = $tenantService->createTenant('Acme Corp', 'acme-corp', $user);
        $this->environment = $this->tenant->environments()->firstOrFail();

        /** @var ApiKeyService $keyService */
        $keyService = app(ApiKeyService::class);
        $result = $keyService->create(tenant: $this->tenant, actor: $user, name: 'Test Key', permissions: ['*'], environment: $this->environment);
        $this->apiKeyPlain = $result['plaintext'];
    }

    public function test_summary_reflects_real_monitors_incidents_and_uptime(): void
    {
        $monitor = Monitor::query()->create([
            'tenant_id' => $this->tenant->id, 'environment_id' => $this->environment->id,
            'name' => 'API', 'kind' => 'http', 'target' => 'https://example.test',
        ]);

        // 3 up, 1 down in the last 24h -> 75% healthy
        for ($i = 0; $i < 3; $i++) {
            CheckResult::query()->create([
                'tenant_id' => $this->tenant->id, 'environment_id' => $this->environment->id,
                'monitor_id' => $monitor->id, 'state' => 'up', 'latency_ms' => 10, 'checked_at' => now(),
            ]);
        }
        CheckResult::query()->create([
            'tenant_id' => $this->tenant->id, 'environment_id' => $this->environment->id,
            'monitor_id' => $monitor->id, 'state' => 'down', 'latency_ms' => 10, 'checked_at' => now(),
        ]);
        // Outside the 24h window — must not count
        CheckResult::query()->create([
            'tenant_id' => $this->tenant->id, 'environment_id' => $this->environment->id,
            'monitor_id' => $monitor->id, 'state' => 'down', 'latency_ms' => 10, 'checked_at' => now()->subDays(2),
        ]);

        Incident::query()->create([
            'tenant_id' => $this->tenant->id, 'environment_id' => $this->environment->id,
            'monitor_id' => $monitor->id, 'manual' => false, 'started_at' => now(), 'severity' => 'major',
            'summary' => 'Check failed',
        ]);

        MaintenanceWindow::query()->create([
            'tenant_id' => $this->tenant->id, 'environment_id' => $this->environment->id,
            'name' => 'Active window', 'starts_at' => now()->subMinutes(5), 'ends_at' => now()->addMinutes(5),
            'recurring_weekly' => false,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->apiKeyPlain)
            ->getJson("/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}/dashboard-summary");

        $response->assertStatus(200);
        $response->assertJsonPath('data.total_monitors', 1);
        $response->assertJsonPath('data.open_incidents', 1);
        $response->assertJsonPath('data.active_maintenance_windows', 1);
        $response->assertJsonPath('data.uptime_24h_percent', 75);
    }

    public function test_uptime_is_null_when_no_checks_exist_yet(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->apiKeyPlain)
            ->getJson("/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}/dashboard-summary");

        $response->assertStatus(200);
        $response->assertJsonPath('data.uptime_24h_percent', null);
        $response->assertJsonPath('data.total_monitors', 0);
    }
}
