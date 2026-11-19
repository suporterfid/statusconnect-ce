<?php

namespace Tests\Feature;

use App\Application\Tenancy\TenantService;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeMembershipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_tenants_and_environments_the_user_belongs_to(): void
    {
        $user = User::factory()->create();
        /** @var TenantService $tenantService */
        $tenantService = app(TenantService::class);
        $tenant = $tenantService->createTenant('Acme Corp', 'acme-corp', $user);
        $environment = $tenant->environments()->firstOrFail();

        $response = $this->actingAs($user)->getJson('/v1/me/memberships');

        $response->assertOk();
        $response->assertJsonPath('data.0.tenant.id', $tenant->public_id);
        $response->assertJsonPath('data.0.environments.0.id', $environment->public_id);
    }

    public function test_user_with_no_memberships_gets_an_empty_list(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/v1/me/memberships');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/v1/me/memberships')->assertStatus(401);
    }
}
