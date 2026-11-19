<?php

namespace Tests\Feature;

use App\Http\Views\ApiReference;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Public API reference (#151). The document must cover every public /v1 route
 * and list no route that does not exist, and /api must render it.
 */
final class ApiReferenceTest extends TestCase
{
    /** Session-only or platform-internal endpoints that are deliberately not documented. */
    private const EXCLUDED = [
        'POST /v1/auth/login',
        'POST /v1/auth/logout',
        'GET /v1/setup',
        'POST /v1/setup',
        'PATCH /v1/me/preferences',
        'PUT /v1/me/password',
        'POST /v1/me/terms-acceptance',
        // Platform users and organizations are documented since #248; the checker stays internal.
        'GET /v1/platform/checker',
        'POST /v1/platform/grandpasson/tenant-mappings',
        // MCP (#184): only answer 405; POST /v1/mcp is documented.
        'GET /v1/mcp',
        'DELETE /v1/mcp',
    ];

    /** Documented only when they work with an API key. */
    private const OPTIONAL = [
        'GET /v1/me',
        'GET /v1/me/memberships',
    ];

    /**
     * Enterprise edition operations (#250). The reference documents them; a copy of the
     * source without routes/enterprise.php (the Community Edition) has no such routes.
     */
    private const ENTERPRISE = [
        'GET /v1/tenants/{tenantId}/plan',
        'POST /v1/tenants/{tenantId}/plan/checkout',
        'POST /v1/tenants/{tenantId}/plan/cancel',
        'POST /v1/tenants/{tenantId}/plan/trial',
        'POST /v1/tenants/{tenantId}/environments/{environmentId}/notification-channels/whatsapp-links',
        'GET /v1/tenants/{tenantId}/environments/{environmentId}/notification-channels/whatsapp-links/{linkId}',
    ];

    /** Public token endpoints outside /v1 that belong in the reference. */
    private const PUBLIC_EXTRA = [
        'GET /ping/{token}',
        'POST /ping/{token}',
        'POST /ingest/{token}',
        'POST /ingest/{token}/v1/metrics',
    ];

    /** @return list<string> */
    private function routeKeys(): array
    {
        $keys = [];
        foreach (Route::getRoutes() as $route) {
            $uri = '/'.ltrim($route->uri(), '/');
            if (! str_starts_with($uri, '/v1/') && ! in_array($uri, ['/ping/{token}', '/ingest/{token}', '/ingest/{token}/v1/metrics'], true)) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $keys[] = $method.' '.$uri;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    public function test_document_covers_every_public_route_and_nothing_else(): void
    {
        $routes = $this->routeKeys();
        $documented = (new ApiReference)->operationKeys();

        $expected = array_diff($routes, self::EXCLUDED, self::OPTIONAL);
        $this->assertSame([], array_values(array_diff($expected, $documented)), 'Routes missing from resources/api/openapi.json');
        $unrouted = array_diff($documented, $routes);
        if (! is_file(base_path('routes/enterprise.php'))) {
            $unrouted = array_diff($unrouted, self::ENTERPRISE);
        }
        $this->assertSame([], array_values($unrouted), 'Documented operations with no matching route');
        $this->assertSame([], array_values(array_intersect($documented, self::EXCLUDED)), 'Excluded endpoints must not be documented');
        foreach (self::PUBLIC_EXTRA as $key) {
            $this->assertContains($key, $documented);
        }
    }

    public function test_every_operation_is_described(): void
    {
        $doc = (new ApiReference)->document();
        $this->assertSame('3.1.0', $doc['openapi'] ?? null);
        $tagNames = array_column($doc['tags'] ?? [], 'name');
        $ids = [];
        foreach ($doc['paths'] as $path => $item) {
            foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
                if (! isset($item[$method])) {
                    continue;
                }
                $op = $item[$method];
                $where = strtoupper($method).' '.$path;
                $this->assertNotEmpty($op['operationId'] ?? null, $where);
                $ids[] = $op['operationId'];
                $this->assertNotEmpty($op['summary'] ?? null, $where);
                $this->assertCount(1, $op['tags'] ?? [], $where);
                $this->assertContains($op['tags'][0], $tagNames, $where);
                $this->assertNotEmpty($op['responses'] ?? null, $where);
                $public = isset($op['security']) && $op['security'] === [];
                if (! $public) {
                    $this->assertNotEmpty($op['x-roles'] ?? null, $where.' needs x-roles');
                }
            }
        }
        $this->assertSame(count($ids), count(array_unique($ids)), 'operationId values must be unique');
    }

    /**
     * #154: x-roles and x-api-key-permission match what EnforceTenantPermission enforces.
     */
    public function test_documented_permissions_match_the_middleware(): void
    {
        $doc = (new ApiReference)->document();
        $checked = 0;
        foreach ($doc['paths'] as $path => $item) {
            if (! str_contains($path, '{environmentId}')) {
                continue;
            }
            foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
                if (! isset($item[$method])) {
                    continue;
                }
                $op = $item[$method];
                $where = strtoupper($method).' '.$path;
                $request = \Illuminate\Http\Request::create(ltrim($path, '/'), strtoupper($method));
                $route = (new \Illuminate\Routing\Route(strtoupper($method), ltrim($path, '/'), fn () => null));
                $request->setRouteResolver(fn () => $route);
                $this->assertSame(\App\Http\Middleware\EnforceTenantPermission::requiredPermission($request), $op['x-api-key-permission'] ?? null, $where);
                $this->assertSame($method === 'get' ? ['owner', 'admin', 'viewer'] : ['owner', 'admin'], $op['x-roles'], $where);
                $checked++;
            }
        }
        $this->assertGreaterThan(40, $checked);
    }

    public function test_reference_page_renders_without_scripts(): void
    {
        $api = new ApiReference;
        $response = $this->get('/api')->assertOk()
            ->assertSee('Referência da API')
            ->assertSee('Authorization')
            ->assertSee('/api/openapi.json', false)
            ->assertDontSee('<script', false);

        foreach ($api->sections() as $section) {
            $response->assertSee($section['name']);
            foreach ($section['operations'] as $op) {
                $response->assertSee('id="'.$op['id'].'"', false);
            }
        }
    }

    public function test_document_is_served_as_json(): void
    {
        $response = $this->get('/api/openapi.json')->assertOk();
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertSame('3.1.0', json_decode((string) file_get_contents(ApiReference::path()), true)['openapi']);
    }

    public function test_inline_markup_is_escaped(): void
    {
        $this->assertSame('Use <code>sc_</code> e <strong>só</strong> &lt;b&gt;', ApiReference::inline('Use `sc_` e **só** <b>'));
    }

    public function test_site_footer_links_to_the_reference(): void
    {
        $this->get('/')->assertOk()->assertSee('href="'.url('/api').'"', false);
    }
}
