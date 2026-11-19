<?php

namespace Tests\Feature;

use App\Application\ApiKeys\ApiKeyService;
use App\Application\Tenancy\TenantService;
use App\Domain\Outbound\DnsResolverInterface;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for a bug where MonitorService::createMonitor()/updateMonitor()
 * never forwarded the request's `egress_profile` to OutboundPolicy::validateUrl(),
 * which meant every HTTP monitor was always validated against the `internal` egress
 * profile (requiring a pre-allowlisted host) no matter what the caller asked for.
 *
 * `public.example` below resolves (via a fake DNS resolver) to a public IP that is
 * on neither the platform nor tenant allowlist, so it fails under `internal` but
 * succeeds under `public-crawl` — the exact distinction the bug erased.
 */
class MonitorEgressProfileRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Environment $environment;

    private string $apiKeyPlain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(DnsResolverInterface::class, fn () => new class implements DnsResolverInterface {
            public function resolve(string $hostname): array
            {
                return match ($hostname) {
                    'public.example' => ['93.184.216.34'],
                    'target' => ['127.0.0.1'],
                    default => [],
                };
            }
        });

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

    public function test_internal_profile_rejects_a_non_allowlisted_public_host(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->apiKeyPlain)
            ->postJson("/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}/monitors", [
                'name' => 'Internal profile monitor',
                'kind' => 'http',
                'target' => 'https://public.example/status',
                'egress_profile' => 'internal',
            ]);

        // #156: refused by the outbound policy as a validation error (it used to be a 500).
        $response->assertStatus(422)->assertJsonValidationErrors('target');
    }

    public function test_public_crawl_profile_is_actually_honored_on_create(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->apiKeyPlain)
            ->postJson("/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}/monitors", [
                'name' => 'Public crawl monitor',
                'kind' => 'http',
                'target' => 'https://public.example/status',
                'egress_profile' => 'public-crawl',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.egress_profile', 'public-crawl');
    }

    public function test_public_crawl_profile_is_actually_honored_on_update(): void
    {
        $monitor = app(\App\Application\Monitoring\MonitorService::class)->createMonitor($this->tenant, $this->environment, [
            'name' => 'Existing monitor',
            'kind' => 'http',
            'target' => 'http://target:8095/status/200',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->apiKeyPlain)
            ->putJson("/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}/monitors/{$monitor->public_id}", [
                'target' => 'https://public.example/status',
                'egress_profile' => 'public-crawl',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.target', 'https://public.example/status');
    }
}
