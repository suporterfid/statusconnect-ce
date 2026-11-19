<?php

namespace Tests\Feature;

use App\Application\ApiKeys\ApiKeyService;
use App\Application\Tenancy\TenantService;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AssertionOperatorValidationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Environment $environment;

    private string $apiKeyPlain;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        /** @var TenantService $tenantService */
        $tenantService = app(TenantService::class);
        $this->tenant = $tenantService->createTenant('Acme Corp', 'acme-corp', $user);
        $this->environment = $this->tenant->environments()->firstOrFail();

        /** @var ApiKeyService $keyService */
        $keyService = app(ApiKeyService::class);
        $result = $keyService->create(tenant: $this->tenant, actor: $user, name: 'Test Key', permissions: ['*'], environment: $this->environment);
        $this->apiKeyPlain = $result['plaintext'];
    }

    public function test_rejects_body_matches_with_a_non_regex_operator(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->apiKeyPlain)
            ->postJson("/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}/monitors", [
                'name' => 'Bad assertion monitor',
                'kind' => 'http',
                'target' => 'http://target:8095/status/200',
                'assertions' => [
                    ['type' => 'body_matches', 'operator' => 'equals', 'expected_value' => 'user.name'],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['assertions.0.operator']);
    }

    public function test_rejects_tls_expires_in_days_with_a_non_gte_operator(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->apiKeyPlain)
            ->postJson("/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}/monitors", [
                'name' => 'Bad tls assertion monitor',
                'kind' => 'http',
                'target' => 'http://target:8095/status/200',
                'assertions' => [
                    ['type' => 'tls_expires_in_days', 'operator' => 'lt', 'expected_value' => '14'],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['assertions.0.operator']);
    }

    public function test_accepts_json_path_numeric_thresholds(): void
    {
        $response = $this->createMonitorWithAssertions([
            ['type' => 'json_path', 'operator' => 'lt', 'target_property' => 'mem.percent', 'expected_value' => '90'],
            ['type' => 'json_path', 'operator' => 'gte', 'target_property' => 'load.cpucore', 'expected_value' => '0.5'],
            ['type' => 'json_path', 'operator' => 'between', 'target_property' => 'temp', 'expected_value' => '10,40'],
            ['type' => 'json_path', 'operator' => 'lte', 'target_property' => 'queue', 'expected_value' => '100'],
        ]);

        $response->assertStatus(201);
    }

    public function test_rejects_json_path_numeric_operator_with_non_numeric_expected_value(): void
    {
        $response = $this->createMonitorWithAssertions([
            ['type' => 'json_path', 'operator' => 'lt', 'target_property' => 'mem.percent', 'expected_value' => 'ninety'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['assertions.0.expected_value']);
    }

    public function test_rejects_json_path_between_with_inverted_range(): void
    {
        $response = $this->createMonitorWithAssertions([
            ['type' => 'json_path', 'operator' => 'between', 'target_property' => 'temp', 'expected_value' => '40,10'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['assertions.0.expected_value']);
    }

    public function test_rejects_json_path_with_unsupported_operator(): void
    {
        $response = $this->createMonitorWithAssertions([
            ['type' => 'json_path', 'operator' => 'in', 'target_property' => 'status', 'expected_value' => 'ok,up'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['assertions.0.operator']);
    }

    /**
     * @param  list<array<string, string>>  $assertions
     */
    private function createMonitorWithAssertions(array $assertions): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->apiKeyPlain)
            ->postJson("/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}/monitors", [
                'name' => 'Agent API monitor',
                'kind' => 'http',
                'target' => 'http://target:8095/status/200',
                'assertions' => $assertions,
            ]);
    }

    public function test_accepts_body_matches_with_regex_operator(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->apiKeyPlain)
            ->postJson("/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}/monitors", [
                'name' => 'Good assertion monitor',
                'kind' => 'http',
                'target' => 'http://target:8095/status/200',
                'assertions' => [
                    ['type' => 'body_matches', 'operator' => 'regex', 'expected_value' => '/ok/'],
                ],
            ]);

        $response->assertStatus(201);
    }
}
