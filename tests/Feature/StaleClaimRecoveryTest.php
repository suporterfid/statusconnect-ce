<?php

namespace Tests\Feature;

use App\Application\Scheduling\StaleClaimRecovery;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaleClaimRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function makeMonitor(Tenant $tenant, Environment $environment, array $overrides = []): Monitor
    {
        return Monitor::query()->create(array_merge([
            'tenant_id' => $tenant->id,
            'environment_id' => $environment->id,
            'name' => 'M',
            'kind' => 'http',
            'target' => 'https://example.test',
        ], $overrides));
    }

    public function test_releases_only_claims_past_their_expiry(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $environment = Environment::query()->create([
            'tenant_id' => $tenant->id, 'name' => 'Production', 'slug' => 'production', 'is_default' => true,
        ]);

        $expired = $this->makeMonitor($tenant, $environment, [
            'claim_token' => 'tok-expired', 'claimed_at' => now()->subMinutes(10), 'claim_expires_at' => now()->subMinutes(5),
        ]);
        $stillHeld = $this->makeMonitor($tenant, $environment, [
            'claim_token' => 'tok-active', 'claimed_at' => now(), 'claim_expires_at' => now()->addMinutes(5),
        ]);
        $unclaimed = $this->makeMonitor($tenant, $environment);

        $recovered = app(StaleClaimRecovery::class)->recover();

        $this->assertSame(1, $recovered);
        $this->assertNull($expired->fresh()->claim_token);
        $this->assertSame('tok-active', $stillHeld->fresh()->claim_token);
        $this->assertNull($unclaimed->fresh()->claim_token);
    }

    public function test_respects_batch_size(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $environment = Environment::query()->create([
            'tenant_id' => $tenant->id, 'name' => 'Production', 'slug' => 'production', 'is_default' => true,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->makeMonitor($tenant, $environment, [
                'claim_token' => "tok-{$i}", 'claimed_at' => now()->subMinutes(10), 'claim_expires_at' => now()->subMinutes(5),
            ]);
        }

        $recovered = app(StaleClaimRecovery::class)->recover(batchSize: 2);

        $this->assertSame(2, $recovered);
        $this->assertSame(3, Monitor::query()->whereNotNull('claim_token')->count());
    }

    public function test_returns_zero_when_nothing_is_expired(): void
    {
        $recovered = app(StaleClaimRecovery::class)->recover();

        $this->assertSame(0, $recovered);
    }
}
