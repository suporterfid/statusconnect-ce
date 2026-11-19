<?php

namespace Tests\Feature;

use App\Domain\Notifications\Transports\WebhookNotificationTransport;
use App\Infrastructure\Persistence\Eloquent\NotificationChannel;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Application\Tenancy\TenantService;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookSsrfProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_blocks_webhook_targeting_loopback_and_metadata(): void
    {
        $user = User::factory()->create();
        /** @var TenantService $tenantService */
        $tenantService = app(TenantService::class);
        $tenant = $tenantService->createTenant('Acme Corp', 'acme-corp', $user);
        $environment = $tenant->environments()->firstOrFail();

        $channel = NotificationChannel::query()->create([
            'public_id' => 'nc_ssrf_test',
            'tenant_id' => $tenant->id,
            'environment_id' => $environment->id,
            'name' => 'Malicious Loopback Webhook',
            'type' => 'webhook',
            'config_json' => [
                'url' => 'http://127.0.0.1:8080/internal-admin',
                'secret' => 'webhook-secret',
            ],
            'enabled' => true,
        ]);

        /** @var WebhookNotificationTransport $transport */
        $transport = app(WebhookNotificationTransport::class);
        $result = $transport->send($channel, [
            'event' => 'opened',
            'summary' => 'Test incident',
        ]);

        $this->assertEquals('failed', $result['status']);
        $this->assertEquals(403, $result['response_code']);
        $this->assertStringContainsString('Outbound policy violation', $result['error_excerpt']);
    }
}
