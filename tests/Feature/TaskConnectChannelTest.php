<?php

namespace Tests\Feature;

use App\Application\Notifications\Services\NotificationDispatcherService;
use App\Application\Tenancy\TenantService;
use App\Domain\Notifications\Transports\TaskConnectNotificationTransport;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Incident;
use App\Infrastructure\Persistence\Eloquent\NotificationChannel;
use App\Infrastructure\Persistence\Eloquent\NotificationDelivery;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskConnectChannelTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Environment $environment;

    private Incident $incident;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        /** @var TenantService $tenantService */
        $tenantService = app(TenantService::class);
        $this->tenant = $tenantService->createTenant('Acme Corp', 'acme-corp', $user);
        $this->environment = $this->tenant->environments()->firstOrFail();

        $this->incident = Incident::query()->create([
            'public_id' => 'inc_taskconnect_test_123',
            'tenant_id' => $this->tenant->id,
            'environment_id' => $this->environment->id,
            'started_at' => now()->subMinutes(5),
            'confirmed_at' => now()->subMinutes(5),
            'severity' => 'critical',
            'summary' => 'TaskConnect Integration Incident Test',
        ]);
    }

    public function test_blocks_taskconnect_targeting_loopback_ssrf(): void
    {
        $channel = NotificationChannel::query()->create([
            'public_id' => 'nc_tc_ssrf',
            'tenant_id' => $this->tenant->id,
            'environment_id' => $this->environment->id,
            'name' => 'Malicious TaskConnect Channel',
            'type' => 'taskconnect',
            'config_json' => [
                'url' => 'http://127.0.0.1:8080/internal-tasks',
                'api_key' => 'secret_token',
            ],
            'enabled' => true,
        ]);

        /** @var TaskConnectNotificationTransport $transport */
        $transport = app(TaskConnectNotificationTransport::class);
        $result = $transport->send($channel, [
            'incident_id' => 'inc_123',
            'event' => 'opened',
            'summary' => 'Test incident',
        ]);

        $this->assertEquals('failed', $result['status']);
        $this->assertEquals(403, $result['response_code']);
        $this->assertStringContainsString('Outbound policy violation', $result['error_excerpt']);
    }

    public function test_returns_failed_status_when_url_is_missing(): void
    {
        $channel = NotificationChannel::query()->create([
            'public_id' => 'nc_tc_nourl',
            'tenant_id' => $this->tenant->id,
            'environment_id' => $this->environment->id,
            'name' => 'No URL Channel',
            'type' => 'taskconnect',
            'config_json' => [],
            'enabled' => true,
        ]);

        /** @var TaskConnectNotificationTransport $transport */
        $transport = app(TaskConnectNotificationTransport::class);
        $result = $transport->send($channel, [
            'incident_id' => 'inc_123',
            'event' => 'opened',
        ]);

        $this->assertEquals('failed', $result['status']);
        $this->assertEquals(400, $result['response_code']);
        $this->assertEquals('No valid TaskConnect URL configured.', $result['error_excerpt']);
    }

    public function test_dispatcher_creates_delivery_record_for_taskconnect_channel(): void
    {
        $channel = NotificationChannel::query()->create([
            'public_id' => 'nc_tc_dispatcher',
            'tenant_id' => $this->tenant->id,
            'environment_id' => $this->environment->id,
            'name' => 'TaskConnect Channel',
            'type' => 'taskconnect',
            'config_json' => [
                'url' => 'http://127.0.0.1:8080/v1/tasks',
                'api_key' => 'tc_key_123',
            ],
            'enabled' => true,
        ]);

        /** @var NotificationDispatcherService $dispatcher */
        $dispatcher = app(NotificationDispatcherService::class);
        $delivery = $dispatcher->dispatchToChannel($this->incident, $channel, 'opened');

        $this->assertNotNull($delivery);
        $this->assertEquals($this->tenant->id, $delivery->tenant_id);
        $this->assertEquals($this->incident->id, $delivery->incident_id);
        $this->assertEquals($channel->id, $delivery->channel_id);
        $this->assertEquals('opened', $delivery->event);
        // Since 127.0.0.1 is blocked by SSRF protection, status should be failed
        $this->assertEquals('failed', $delivery->status);
        $this->assertEquals(403, $delivery->response_code);
    }
}
