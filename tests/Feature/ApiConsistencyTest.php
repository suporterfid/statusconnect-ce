<?php

namespace Tests\Feature;

use App\Application\ApiKeys\ApiKeyService;
use App\Application\Tenancy\TenantService;
use App\Http\Support\ApiDate;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #156: smaller API fixes found while documenting the API.
 */
final class ApiConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/';

    private User $owner;

    private Tenant $tenant;

    private Environment $environment;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.locale', 'en');
        $this->owner = User::factory()->create();
        $this->tenant = app(TenantService::class)->createTenant('Acme', 'acme', $this->owner);
        $this->environment = $this->tenant->environments()->firstOrFail();
        $this->key = app(ApiKeyService::class)->create($this->tenant, $this->owner, 'k', ['*'], $this->environment)['plaintext'];
    }

    private function base(): string
    {
        return "/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}";
    }

    private function api(): self
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->key);
    }

    public function test_blocked_target_is_a_validation_error(): void
    {
        $this->api()->postJson($this->base().'/monitors', ['name' => 'Local', 'kind' => 'http', 'target' => 'http://127.0.0.1/admin'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('target');

        $monitor = Monitor::query()->create([
            'tenant_id' => $this->tenant->id, 'environment_id' => $this->environment->id,
            'name' => 'Site', 'kind' => 'http', 'target' => 'https://example.test',
        ])->public_id;
        $this->api()->putJson($this->base().'/monitors/'.$monitor, ['target' => 'http://169.254.169.254/latest'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('target');
        $this->assertSame('https://example.test', Monitor::query()->value('target'));
    }

    public function test_maintenance_window_end_can_be_changed_alone(): void
    {
        $id = $this->api()->postJson($this->base().'/maintenance-windows', [
            'name' => 'Deploy',
            'starts_at' => now()->addHour()->toIso8601String(),
            'ends_at' => now()->addHours(2)->toIso8601String(),
        ])->assertCreated()->json('data.public_id');

        $this->api()->putJson($this->base().'/maintenance-windows/'.$id, ['ends_at' => now()->addHours(5)->toIso8601String()])->assertOk();
        $this->api()->putJson($this->base().'/maintenance-windows/'.$id, ['ends_at' => now()->toIso8601String()])
            ->assertStatus(422)->assertJsonValidationErrors('ends_at');
        $this->api()->putJson($this->base().'/maintenance-windows/'.$id, ['starts_at' => now()->addHours(6)->toIso8601String()])
            ->assertStatus(422)->assertJsonValidationErrors('starts_at');
    }

    public function test_heartbeat_grace_is_settable(): void
    {
        $id = $this->api()->postJson($this->base().'/monitors', ['name' => 'Cron', 'kind' => 'heartbeat', 'target' => 'nightly', 'heartbeat_grace_seconds' => 900])
            ->assertCreated()
            ->assertJsonPath('data.heartbeat_grace_seconds', 900)
            ->json('data.public_id');

        $this->api()->putJson($this->base().'/monitors/'.$id, ['heartbeat_grace_seconds' => 120])
            ->assertOk()->assertJsonPath('data.heartbeat_grace_seconds', 120);
        $this->api()->putJson($this->base().'/monitors/'.$id, ['heartbeat_grace_seconds' => -1])->assertStatus(422);
    }

    public function test_dates_share_one_format(): void
    {
        $this->assertSame('2026-11-09T14:30:00.000000Z', ApiDate::format('2026-11-09 14:30:00'));
        $this->assertSame('2026-11-09T14:30:00.000000Z', ApiDate::format(new \DateTimeImmutable('2026-11-09T11:30:00-03:00')));
        $this->assertNull(ApiDate::format(null));

        $incident = $this->api()->withHeader('Idempotency-Key', 'inc-1')->postJson($this->base().'/incidents', ['summary' => 'Slow responses', 'severity' => 'minor'])->json('data');
        $this->assertMatchesRegularExpression(self::DATE, (string) $incident['started_at']);

        $key = $this->api()->getJson($this->base().'/api-keys')->assertOk()->json('data.0');
        $this->assertMatchesRegularExpression(self::DATE, (string) $key['created_at']);

        $this->actingAs($this->owner);
        $page = $this->putJson($this->base().'/status-page', ['slug' => 'acme', 'title' => 'Acme'])->json('data');
        $this->assertMatchesRegularExpression(self::DATE, (string) $page['updated_at']);
    }

    public function test_api_key_errors_follow_accept_language_then_the_key_creator(): void
    {
        $english = fn (string $m) => str_contains($m, 'field is required');

        // The test client sends Accept-Language: en-us by default; an empty header stands for a client that sends none.
        // No Accept-Language and no preference: server default (en).
        $this->api()->withHeader('Accept-Language', '')->postJson($this->base().'/monitors', [])->assertStatus(422)->assertJsonPath('errors.name.0', $english);

        // The creator's language.
        $this->owner->preferences()->updateOrCreate([], ['locale' => 'pt_BR']);
        $this->api()->withHeader('Accept-Language', '')->postJson($this->base().'/monitors', [])->assertJsonPath('errors.name.0', fn (string $m) => ! $english($m));

        // Accept-Language wins over the creator's language.
        $this->flushHeaders();
        $this->api()->withHeader('Accept-Language', 'en-US,en;q=0.9')
            ->postJson($this->base().'/monitors', [])->assertJsonPath('errors.name.0', $english);
    }
}
