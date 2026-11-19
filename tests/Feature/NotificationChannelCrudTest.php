<?php

namespace Tests\Feature;

use App\Application\ApiKeys\ApiKeyService;
use App\Application\Tenancy\TenantService;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationChannelCrudTest extends TestCase
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

    public function test_can_crud_notification_channels_and_send_test(): void
    {
        $baseUrl = "/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}/notification-channels";

        // 1. Create email notification channel
        $createResponse = $this->withHeader('Authorization', 'Bearer '.$this->apiKeyPlain)
            ->postJson($baseUrl, [
                'name' => 'Devops Email Alerts',
                'type' => 'email',
                'config_json' => [
                    'email' => 'devops@example.com',
                ],
                'enabled' => true,
            ]);

        $createResponse->assertStatus(201);
        $channelData = $createResponse->json('data');
        $this->assertNotNull($channelData['public_id']);
        $this->assertEquals('Devops Email Alerts', $channelData['name']);
        $this->assertEquals('email', $channelData['type']);
        $channelId = $channelData['public_id'];

        // 2. List notification channels
        $listResponse = $this->withHeader('Authorization', 'Bearer '.$this->apiKeyPlain)
            ->getJson($baseUrl);

        $listResponse->assertStatus(200);
        $this->assertCount(1, $listResponse->json('data'));

        // 3. Show notification channel
        $showResponse = $this->withHeader('Authorization', 'Bearer '.$this->apiKeyPlain)
            ->getJson("{$baseUrl}/{$channelId}");

        $showResponse->assertStatus(200);
        $this->assertEquals('Devops Email Alerts', $showResponse->json('data.name'));

        // 4. Update channel
        $updateResponse = $this->withHeader('Authorization', 'Bearer '.$this->apiKeyPlain)
            ->putJson("{$baseUrl}/{$channelId}", [
                'name' => 'Updated Email Alerts',
                'enabled' => false,
            ]);

        $updateResponse->assertStatus(200);
        $this->assertEquals('Updated Email Alerts', $updateResponse->json('data.name'));
        $this->assertFalse($updateResponse->json('data.enabled'));

        // 5. Test send notification
        $testResponse = $this->withHeader('Authorization', 'Bearer '.$this->apiKeyPlain)
            ->postJson("{$baseUrl}/{$channelId}/test");

        $testResponse->assertStatus(200);
        $this->assertEquals('test', $testResponse->json('data.event'));

        // 6. Delete channel
        $deleteResponse = $this->withHeader('Authorization', 'Bearer '.$this->apiKeyPlain)
            ->deleteJson("{$baseUrl}/{$channelId}");

        $deleteResponse->assertStatus(204);
    }
}
