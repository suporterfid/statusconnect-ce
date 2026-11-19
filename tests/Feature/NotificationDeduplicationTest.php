<?php

namespace Tests\Feature;

use App\Application\Notifications\Services\NotificationDispatcherService;
use App\Application\Tenancy\TenantService;
use App\Domain\Secrets\SecretRedactor;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Incident;
use App\Infrastructure\Persistence\Eloquent\NotificationChannel;
use App\Infrastructure\Persistence\Eloquent\NotificationDelivery;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Environment $environment;

    private NotificationChannel $channel;

    private Incident $incident;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        /** @var TenantService $tenantService */
        $tenantService = app(TenantService::class);
        $this->tenant = $tenantService->createTenant('Acme Corp', 'acme-corp', $user);
        $this->environment = $this->tenant->environments()->firstOrFail();

        $this->channel = NotificationChannel::query()->create([
            'public_id' => 'nc_test_channel_123',
            'tenant_id' => $this->tenant->id,
            'environment_id' => $this->environment->id,
            'name' => 'Test Email Channel',
            'type' => 'email',
            'config_json' => ['email' => 'alerts@example.com'],
            'enabled' => true,
        ]);

        $this->incident = Incident::query()->create([
            'public_id' => 'inc_test_dedup_123',
            'tenant_id' => $this->tenant->id,
            'environment_id' => $this->environment->id,
            'started_at' => now()->subMinutes(5),
            'confirmed_at' => now()->subMinutes(5),
            'severity' => 'major',
            'summary' => 'Service failed with Authorization: Bearer secret-token-xyz',
        ]);
    }

    public function test_duplicate_tick_does_not_double_send_notification(): void
    {
        /** @var NotificationDispatcherService $dispatcher */
        $dispatcher = app(NotificationDispatcherService::class);

        // Tick 1: Dispatches notification
        $dispatcher->dispatchForIncident($this->incident, 'opened');

        $deliveriesCount1 = NotificationDelivery::query()
            ->where('incident_id', $this->incident->id)
            ->where('channel_id', $this->channel->id)
            ->where('event', 'opened')
            ->count();

        $this->assertEquals(1, $deliveriesCount1);

        // Tick 2 (Duplicate run): Attempts to dispatch for the exact same event
        $dispatcher->dispatchForIncident($this->incident, 'opened');

        $deliveriesCount2 = NotificationDelivery::query()
            ->where('incident_id', $this->incident->id)
            ->where('channel_id', $this->channel->id)
            ->where('event', 'opened')
            ->count();

        // Must still be 1 (deduplicated by unique constraint)
        $this->assertEquals(1, $deliveriesCount2);
    }

    public function test_redacts_secrets_from_summary_and_payload(): void
    {
        $redactor = app(SecretRedactor::class);
        $summary = 'Authorization: Bearer my-secret-token-12345';

        $redacted = $redactor->redactString($summary);

        $this->assertStringNotContainsString('my-secret-token-12345', $redacted);
        $this->assertStringContainsString('[REDACTED]', $redacted);
    }
}
