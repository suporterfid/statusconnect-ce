<?php

namespace Tests\Feature;

use App\Application\ApiKeys\ApiKeyService;
use App\Application\Tenancy\TenantService;
use App\Domain\Shared\TenantRole;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * #154: viewers are read-only and API keys only do what their permissions allow.
 */
final class TenantPermissionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $tenant;

    private Environment $environment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->tenant = app(TenantService::class)->createTenant('Acme', 'acme', $this->owner);
        $this->environment = $this->tenant->environments()->firstOrFail();
    }

    /**
     * Every route under an environment, with its parameters filled in.
     *
     * @return list<array{0: string, 1: string}> [method, uri]
     */
    private function environmentRoutes(): array
    {
        $rows = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_contains($uri, '{environmentId}')) {
                continue;
            }
            $path = '/'.strtr($uri, ['{tenantId}' => $this->tenant->public_id, '{environmentId}' => $this->environment->public_id]);
            $path = (string) preg_replace('/\{[^}]+\}/', '1', $path);
            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $rows[] = [$method, $path];
                }
            }
        }
        $this->assertGreaterThan(40, count($rows));

        return $rows;
    }

    private function key(array $permissions): string
    {
        return app(ApiKeyService::class)->create($this->tenant, $this->owner, 'k', $permissions, $this->environment)['plaintext'];
    }

    private function isWrite(string $method): bool
    {
        return ! in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    public function test_viewer_can_read_every_environment_route_but_write_none(): void
    {
        $viewer = User::factory()->create();
        app(TenantService::class)->addMember($this->tenant, $viewer, TenantRole::VIEWER);
        $this->actingAs($viewer);

        foreach ($this->environmentRoutes() as [$method, $path]) {
            $status = $this->json($method, $path)->getStatusCode();
            if ($this->isWrite($method)) {
                $this->assertSame(403, $status, "Viewer got {$status} on {$method} {$path}");
            } else {
                $this->assertNotSame(403, $status, "Viewer could not read {$method} {$path}");
            }
        }
    }

    public function test_owner_and_admin_are_not_blocked(): void
    {
        $admin = User::factory()->create();
        app(TenantService::class)->addMember($this->tenant, $admin, TenantRole::ADMIN);

        foreach ([$this->owner, $admin] as $user) {
            $this->actingAs($user);
            foreach ($this->environmentRoutes() as [$method, $path]) {
                $status = $this->json($method, $path)->getStatusCode();
                $this->assertNotSame(403, $status, "{$method} {$path} forbidden");
            }
        }
    }

    public function test_viewer_cannot_create_monitors_or_api_keys(): void
    {
        $viewer = User::factory()->create();
        app(TenantService::class)->addMember($this->tenant, $viewer, TenantRole::VIEWER);
        $base = "/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}";

        $this->actingAs($viewer)
            ->postJson($base.'/monitors', ['name' => 'X', 'type' => 'http', 'target' => 'https://example.com', 'interval_seconds' => 300])
            ->assertForbidden()
            ->assertJsonPath('message', __('messages.viewer_read_only'));
        $this->postJson($base.'/api-keys', ['name' => 'escalate', 'permissions' => ['*']])->assertForbidden();

        $this->assertSame(0, Monitor::query()->count());
        $this->assertSame(0, \App\Infrastructure\Persistence\Eloquent\ApiKey::query()->count());
    }

    public function test_read_only_key_reads_but_cannot_write(): void
    {
        $key = $this->key(['monitors:read']);

        foreach ($this->environmentRoutes() as [$method, $path]) {
            $status = $this->withHeader('Authorization', 'Bearer '.$key)->json($method, $path)->getStatusCode();
            $apiKeys = str_contains($path, '/api-keys');
            if ($this->isWrite($method) || $apiKeys) {
                $this->assertSame(403, $status, "Read-only key got {$status} on {$method} {$path}");
            } else {
                $this->assertNotSame(403, $status, "Read-only key could not read {$method} {$path}");
            }
        }
    }

    public function test_each_permission_opens_only_its_routes(): void
    {
        $cases = [
            'monitors:write' => ['POST /monitors' => true, 'PUT /status-page' => true, 'POST /maintenance-windows' => true, 'POST /incidents' => false, 'POST /notification-channels' => false, 'GET /api-keys' => false, 'GET /monitors' => true],
            'monitors:operate' => ['POST /incidents' => true, 'POST /status-page/updates' => true, 'POST /notification-channels/1/test' => true, 'POST /monitors' => false, 'GET /monitors' => true],
            'secrets:manage' => ['POST /notification-channels' => true, 'PUT /notification-channels/1' => true, 'POST /monitors' => false, 'GET /notification-channels' => true],
            'api_keys:manage' => ['GET /api-keys' => true, 'DELETE /api-keys/1' => true, 'GET /monitors' => false, 'POST /monitors' => false],
            '*' => ['POST /monitors' => true, 'POST /incidents' => true, 'POST /notification-channels' => true, 'GET /api-keys' => true],
        ];
        $base = "/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}";

        foreach ($cases as $permission => $routes) {
            $key = $this->key([$permission]);
            foreach ($routes as $route => $allowed) {
                [$method, $path] = explode(' ', $route);
                $status = $this->withHeader('Authorization', 'Bearer '.$key)->json($method, $base.$path)->getStatusCode();
                $allowed
                    ? $this->assertNotSame(403, $status, "{$permission} blocked on {$route}")
                    : $this->assertSame(403, $status, "{$permission} allowed {$route} ({$status})");
            }
        }
    }

    public function test_denied_key_message_names_the_missing_permission(): void
    {
        $key = $this->key(['monitors:read']);
        $base = "/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}";

        $this->withHeader('Authorization', 'Bearer '.$key)
            ->postJson($base.'/monitors', [])
            ->assertForbidden()
            ->assertJsonPath('message', __('messages.api_key_permission_denied', ['permission' => 'monitors:write']));
    }
}
