<?php

namespace Tests\Feature;

use App\Application\ApiKeys\ApiKeyService;
use App\Application\Tenancy\TenantService;
use App\Infrastructure\Persistence\Eloquent\CheckResult;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage for #36: MonitorDetailView.vue rendered a hardcoded fake latency
 * chart and check-history table because no endpoint existed to fetch a
 * monitor's real check-result history.
 */
class MonitorCheckResultsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Environment $environment;

    private string $apiKeyPlain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        /** @var TenantService $tenantService */
        $tenantService = app(TenantService::class);
        $this->tenant = $tenantService->createTenant('Acme Corp', 'acme-corp', $this->user);
        $this->environment = $this->tenant->environments()->firstOrFail();

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

    public function test_returns_check_results_for_a_monitor_newest_first(): void
    {
        $monitor = Monitor::query()->create([
            'tenant_id' => $this->tenant->id,
            'environment_id' => $this->environment->id,
            'name' => 'API Health Check',
            'kind' => 'http',
            'target' => 'https://example.test',
        ]);

        CheckResult::query()->create([
            'tenant_id' => $this->tenant->id, 'environment_id' => $this->environment->id,
            'monitor_id' => $monitor->id, 'state' => 'up', 'latency_ms' => 100,
            'status_code' => 200, 'checked_at' => now()->subMinutes(2),
        ]);
        CheckResult::query()->create([
            'tenant_id' => $this->tenant->id, 'environment_id' => $this->environment->id,
            'monitor_id' => $monitor->id, 'state' => 'down', 'latency_ms' => 50,
            'status_code' => 500, 'failure_reason' => 'Server error', 'checked_at' => now()->subMinute(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->apiKeyPlain)
            ->getJson("/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}/monitors/{$monitor->public_id}/check-results");

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.state', 'down');
        $response->assertJsonPath('data.0.latency_ms', 50);
        $response->assertJsonPath('data.1.state', 'up');
    }

    public function test_check_results_are_scoped_to_the_requested_monitor_only(): void
    {
        $monitorA = Monitor::query()->create([
            'tenant_id' => $this->tenant->id, 'environment_id' => $this->environment->id,
            'name' => 'A', 'kind' => 'http', 'target' => 'https://a.test',
        ]);
        $monitorB = Monitor::query()->create([
            'tenant_id' => $this->tenant->id, 'environment_id' => $this->environment->id,
            'name' => 'B', 'kind' => 'http', 'target' => 'https://b.test',
        ]);
        CheckResult::query()->create([
            'tenant_id' => $this->tenant->id, 'environment_id' => $this->environment->id,
            'monitor_id' => $monitorA->id, 'state' => 'up', 'latency_ms' => 10, 'checked_at' => now(),
        ]);
        CheckResult::query()->create([
            'tenant_id' => $this->tenant->id, 'environment_id' => $this->environment->id,
            'monitor_id' => $monitorB->id, 'state' => 'up', 'latency_ms' => 20, 'checked_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->apiKeyPlain)
            ->getJson("/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}/monitors/{$monitorA->public_id}/check-results");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        // Internal ids are not exposed (#155); monitor A's result is the one with latency 10.
        $response->assertJsonPath('data.0.latency_ms', 10);
        $response->assertJsonMissingPath('data.0.monitor_id');
    }

    public function test_unknown_monitor_id_returns_404(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->apiKeyPlain)
            ->getJson("/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}/monitors/mon_doesnotexist/check-results")
            ->assertStatus(404);
    }
}
