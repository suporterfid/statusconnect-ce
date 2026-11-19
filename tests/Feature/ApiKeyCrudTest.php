<?php

namespace Tests\Feature;

use App\Application\ApiKeys\ApiKeyService;
use App\Application\Tenancy\TenantService;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeyCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Environment $environment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        /** @var TenantService $tenantService */
        $tenantService = app(TenantService::class);
        $this->tenant = $tenantService->createTenant('Acme Corp', 'acme-corp', $this->user);
        $this->environment = $this->tenant->environments()->firstOrFail();
    }

    public function test_signed_in_user_can_create_list_and_revoke_a_key(): void
    {
        $createResponse = $this->actingAs($this->user)
            ->postJson("/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}/api-keys", [
                'name' => 'CI/CD Ingestion Key',
                'permissions' => ['monitors:read'],
            ]);

        $createResponse->assertStatus(201);
        $createResponse->assertJsonStructure(['data' => ['id', 'name', 'prefix', 'permissions', 'plaintext']]);
        $this->assertStringStartsWith('sc_', $createResponse->json('data.plaintext'));
        $keyId = $createResponse->json('data.id');

        $listResponse = $this->actingAs($this->user)
            ->getJson("/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}/api-keys");

        $listResponse->assertOk();
        $listResponse->assertJsonCount(1, 'data');
        $this->assertArrayNotHasKey('plaintext', $listResponse->json('data.0'));

        $this->actingAs($this->user)
            ->deleteJson("/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}/api-keys/{$keyId}")
            ->assertStatus(204);

        $this->actingAs($this->user)
            ->getJson("/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}/api-keys")
            ->assertJsonCount(0, 'data');
    }

    public function test_rejects_unknown_permission(): void
    {
        $this->actingAs($this->user)
            ->postJson("/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}/api-keys", [
                'name' => 'Bad Key',
                'permissions' => ['not-a-real-permission'],
            ])
            ->assertStatus(422);
    }

    public function test_an_api_key_actor_cannot_mint_more_api_keys(): void
    {
        /** @var ApiKeyService $keyService */
        $keyService = app(ApiKeyService::class);
        $result = $keyService->create(
            tenant: $this->tenant,
            actor: $this->user,
            name: 'Bootstrap Key',
            permissions: ['*'],
            environment: $this->environment,
        );

        $this->withHeader('Authorization', 'Bearer '.$result['plaintext'])
            ->postJson("/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}/api-keys", [
                'name' => 'Second Key',
                'permissions' => ['monitors:read'],
            ])
            ->assertStatus(403);
    }
}
