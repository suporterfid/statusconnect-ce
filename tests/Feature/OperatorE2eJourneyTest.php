<?php

namespace Tests\Feature;

use App\Application\Tenancy\TenantService;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperatorE2eJourneyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Environment $environment;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'operator@example.com',
            'password' => bcrypt('password123'),
        ]);

        /** @var TenantService $tenantService */
        $tenantService = app(TenantService::class);
        $this->tenant = $tenantService->createTenant('Acme Operations', 'acme-ops', $this->user);
        $this->environment = $this->tenant->environments()->firstOrFail();
    }

    public function test_full_operator_journey_login_dashboard_and_preferences(): void
    {
        // 1. Unauthenticated request to SPA returns index asset
        $spaResponse = $this->get('/app');
        $spaResponse->assertStatus(200);

        // 2. Perform Local Login
        $loginResponse = $this->postJson('/v1/auth/login', [
            'email' => 'operator@example.com',
            'password' => 'password123',
        ]);
        $loginResponse->assertStatus(200);
        $loginResponse->assertJsonPath('data.email', 'operator@example.com');

        // 3. Fetch authenticated user preferences (/v1/me)
        $meResponse = $this->getJson('/v1/me');
        $meResponse->assertStatus(200);
        $meResponse->assertJsonPath('data.email', 'operator@example.com');
        $meResponse->assertJsonStructure(['data' => ['id', 'email', 'name', 'preferences']]);

        // 4. Update locale preference to pt_BR
        $prefResponse = $this->patchJson('/v1/me/preferences', [
            'locale' => 'pt_BR',
        ]);
        $prefResponse->assertStatus(200);
        $prefResponse->assertJsonPath('data.preferences.locale', 'pt_BR');

        // 5. Verify tenant environment monitors list API
        $monitorsResponse = $this->getJson("/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}/monitors");
        $monitorsResponse->assertStatus(200);
        $monitorsResponse->assertJsonPath('data', []);

        // 6. Logout
        $logoutResponse = $this->postJson('/v1/auth/logout');
        $logoutResponse->assertStatus(204);
    }
}
