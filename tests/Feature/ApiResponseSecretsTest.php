<?php

namespace Tests\Feature;

use App\Application\ApiKeys\ApiKeyService;
use App\Application\Secrets\ResponseSecretMask;
use App\Application\Tenancy\TenantService;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\NotificationChannel;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #155: API responses never return channel credentials, credential headers or
 * internal fields, and a client that echoes masked values back keeps the stored ones.
 */
final class ApiResponseSecretsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Environment $environment;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->tenant = app(TenantService::class)->createTenant('Acme', 'acme', $user);
        $this->environment = $this->tenant->environments()->firstOrFail();
        $this->key = app(ApiKeyService::class)->create($this->tenant, $user, 'k', ['*'], $this->environment)['plaintext'];
    }

    private function base(): string
    {
        return "/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}";
    }

    private function api(): self
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->key);
    }

    private const INTERNAL = ['"id":', '"tenant_id"', '"environment_id"', '"monitor_id"', '"channel_id"', '"claim_token"', '"claimed_at"', '"claim_expires_at"'];

    private function assertNoInternalFields(string $json): void
    {
        foreach (self::INTERNAL as $field) {
            $this->assertStringNotContainsString($field, $json, "Response exposes {$field}");
        }
    }

    public function test_webhook_and_taskconnect_credentials_are_masked_and_kept_on_update(): void
    {
        $created = $this->api()->postJson($this->base().'/notification-channels', [
            'name' => 'Hook', 'type' => 'webhook',
            'config_json' => ['url' => 'https://hooks.example.com/services/T000/B000/abcSECRETxyz', 'secret' => 'whsec_supersecret'],
        ])->assertCreated();

        $json = $created->getContent();
        $this->assertStringNotContainsString('whsec_supersecret', $json);
        $this->assertStringNotContainsString('abcSECRETxyz', $json);
        $this->assertSame('https://hooks.example.com/'.ResponseSecretMask::MASK, $created->json('data.config_json.url'));
        $this->assertSame(ResponseSecretMask::MASK, $created->json('data.config_json.secret'));
        $this->assertNoInternalFields($json);

        $id = $created->json('data.public_id');
        $list = $this->api()->getJson($this->base().'/notification-channels')->assertOk()->getContent();
        $this->assertStringNotContainsString('whsec_supersecret', $list);
        $this->assertNoInternalFields($list);
        $this->assertStringNotContainsString('whsec_supersecret', $this->api()->getJson($this->base().'/notification-channels/'.$id)->getContent());

        // Echoing the masked config back (only the name changes) keeps the stored credentials.
        $config = $created->json('data.config_json');
        $this->api()->putJson($this->base().'/notification-channels/'.$id, ['name' => 'Hook 2', 'config_json' => $config])->assertOk();
        $stored = NotificationChannel::query()->where('public_id', $id)->firstOrFail()->config_json;
        $this->assertSame('whsec_supersecret', $stored['secret']);
        $this->assertSame('https://hooks.example.com/services/T000/B000/abcSECRETxyz', $stored['url']);

        // A new value replaces it.
        $this->api()->putJson($this->base().'/notification-channels/'.$id, ['config_json' => ['url' => 'https://hooks.example.com/new', 'secret' => 'whsec_rotated']])->assertOk();
        $stored = NotificationChannel::query()->where('public_id', $id)->firstOrFail()->config_json;
        $this->assertSame('whsec_rotated', $stored['secret']);
        $this->assertSame('https://hooks.example.com/new', $stored['url']);

        $tc = $this->api()->postJson($this->base().'/notification-channels', [
            'name' => 'TC', 'type' => 'taskconnect', 'config_json' => ['url' => 'https://tc.example.com', 'api_key' => 'tc_secretkey'],
        ])->assertCreated();
        $this->assertStringNotContainsString('tc_secretkey', $tc->getContent());
        $this->assertSame('https://tc.example.com', $tc->json('data.config_json.url'));
    }

    public function test_monitor_credential_headers_and_internal_fields_are_hidden(): void
    {
        $monitor = Monitor::query()->create([
            'tenant_id' => $this->tenant->id,
            'environment_id' => $this->environment->id,
            'name' => 'API', 'kind' => 'http', 'target' => 'https://example.test',
            'request_headers_json' => ['Authorization' => 'Bearer prod-token-123', 'X-Api-Key' => 'k-456', 'Accept' => 'application/json'],
        ]);
        $monitor->forceFill(['claim_token' => 'lease-abc'])->save();
        \App\Infrastructure\Persistence\Eloquent\CheckResult::query()->create([
            'tenant_id' => $this->tenant->id, 'environment_id' => $this->environment->id,
            'monitor_id' => $monitor->id, 'state' => 'up', 'latency_ms' => 10, 'status_code' => 200, 'checked_at' => now(),
        ]);

        foreach ([$this->base().'/monitors', $this->base().'/monitors/'.$monitor->public_id] as $url) {
            $response = $this->api()->getJson($url)->assertOk();
            $json = $response->getContent();
            $this->assertStringNotContainsString('prod-token-123', $json);
            $this->assertStringNotContainsString('k-456', $json);
            $this->assertStringContainsString('application\/json', $json);
            $this->assertNoInternalFields($json);
        }

        $results = $this->api()->getJson($this->base().'/monitors/'.$monitor->public_id.'/check-results')->assertOk();
        $this->assertNoInternalFields($results->getContent());
        $this->assertNotEmpty($results->json('data.0.public_id'));

        // Echoing masked headers back keeps them; the plain header can change.
        $headers = $this->api()->getJson($this->base().'/monitors/'.$monitor->public_id)->json('data.request_headers_json');
        $headers['Accept'] = 'text/plain';
        $this->api()->putJson($this->base().'/monitors/'.$monitor->public_id, ['request_headers_json' => $headers])->assertOk();
        $stored = $monitor->fresh()->request_headers_json;
        $this->assertSame('Bearer prod-token-123', $stored['Authorization']);
        $this->assertSame('k-456', $stored['X-Api-Key']);
        $this->assertSame('text/plain', $stored['Accept']);
    }

    public function test_maintenance_windows_list_monitors_by_public_id_only(): void
    {
        $monitor = Monitor::query()->create([
            'tenant_id' => $this->tenant->id,
            'environment_id' => $this->environment->id,
            'name' => 'API', 'kind' => 'http', 'target' => 'https://example.test',
            'request_headers_json' => ['Authorization' => 'Bearer prod-token-123'],
        ]);
        $created = $this->api()->postJson($this->base().'/maintenance-windows', [
            'name' => 'Deploy', 'starts_at' => now()->addHour()->toIso8601String(), 'ends_at' => now()->addHours(2)->toIso8601String(),
            'monitor_ids' => [$monitor->public_id],
        ])->assertCreated();

        $list = $this->api()->getJson($this->base().'/maintenance-windows')->assertOk();
        $json = $list->getContent().$created->getContent();
        $this->assertStringNotContainsString('prod-token-123', $json);
        $this->assertNoInternalFields($json);
        $this->assertSame($monitor->public_id, $list->json('data.0.monitors.0.public_id'));
    }

    public function test_mask_helpers(): void
    {
        $this->assertSame(['Authorization' => ResponseSecretMask::MASK, 'Accept' => 'x'], ResponseSecretMask::headers(['Authorization' => 'Bearer a', 'Accept' => 'x']));
        $this->assertSame([['name' => 'Cookie', 'value' => ResponseSecretMask::MASK]], ResponseSecretMask::headers([['name' => 'Cookie', 'value' => 's=1']]));
        $this->assertSame('https://h.example.com', ResponseSecretMask::config(['url' => 'https://h.example.com'])['url']);
        $this->assertSame(['email' => 'ops@example.com'], ResponseSecretMask::config(['email' => 'ops@example.com']));
    }
}
