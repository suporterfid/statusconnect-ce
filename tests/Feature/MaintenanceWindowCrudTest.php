<?php

namespace Tests\Feature;

use App\Application\ApiKeys\ApiKeyService;
use App\Application\Monitoring\MonitorService;
use App\Application\Tenancy\TenantService;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceWindowCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Environment $environment;

    private Monitor $monitor;

    private string $apiKeyPlain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        /** @var TenantService $tenantService */
        $tenantService = app(TenantService::class);
        $this->tenant = $tenantService->createTenant('Acme Corp', 'acme-mw', $this->user);
        $this->environment = $this->tenant->environments()->firstOrFail();

        /** @var MonitorService $monitorService */
        $monitorService = app(MonitorService::class);
        $this->monitor = $monitorService->createMonitor($this->tenant, $this->environment, [
            'name' => 'API Server',
            'kind' => 'http',
            'target' => 'http://target:8095/status/200',
        ]);

        /** @var ApiKeyService $keyService */
        $keyService = app(ApiKeyService::class);
        $result = $keyService->create(
            tenant: $this->tenant,
            actor: $this->user,
            name: 'Test Key',
            permissions: ['*'],
            environment: $this->environment,
        );
        $this->apiKeyPlain = $result['plaintext'];
    }

    public function test_can_crud_maintenance_windows(): void
    {
        $baseUrl = "/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}/maintenance-windows";

        // 1. Create Maintenance Window
        $createResponse = $this->withHeader('Authorization', 'Bearer '.$this->apiKeyPlain)
            ->postJson($baseUrl, [
                'name' => 'Database Upgrade',
                'description' => 'Upgrading MySQL to 8.0.35',
                'starts_at' => now()->addHour()->toIso8601String(),
                'ends_at' => now()->addHours(3)->toIso8601String(),
                'suppress_notifications' => true,
                'recurring_weekly' => false,
                'monitor_ids' => [$this->monitor->public_id],
            ]);

        $createResponse->assertStatus(201);
        $windowId = $createResponse->json('data.public_id');
        $this->assertNotNull($windowId);
        $this->assertStringStartsWith('mw_', $windowId);

        // 2. List Maintenance Windows
        $listResponse = $this->withHeader('Authorization', 'Bearer '.$this->apiKeyPlain)
            ->getJson($baseUrl);

        $listResponse->assertStatus(200);
        $this->assertCount(1, $listResponse->json('data'));

        // 3. Show Maintenance Window
        $showResponse = $this->withHeader('Authorization', 'Bearer '.$this->apiKeyPlain)
            ->getJson("{$baseUrl}/{$windowId}");

        $showResponse->assertStatus(200);
        $this->assertEquals('Database Upgrade', $showResponse->json('data.name'));

        // 4. Update Maintenance Window
        $updateResponse = $this->withHeader('Authorization', 'Bearer '.$this->apiKeyPlain)
            ->putJson("{$baseUrl}/{$windowId}", [
                'name' => 'Database & OS Upgrade',
            ]);

        $updateResponse->assertStatus(200);
        $this->assertEquals('Database & OS Upgrade', $updateResponse->json('data.name'));

        // 5. Delete Maintenance Window
        $deleteResponse = $this->withHeader('Authorization', 'Bearer '.$this->apiKeyPlain)
            ->deleteJson("{$baseUrl}/{$windowId}");

        $deleteResponse->assertStatus(204);

        $this->withHeader('Authorization', 'Bearer '.$this->apiKeyPlain)
            ->getJson("{$baseUrl}/{$windowId}")
            ->assertStatus(404);
    }
}
