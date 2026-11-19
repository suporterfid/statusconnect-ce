<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Regression coverage for a bug where the `/tenants/{tenantId}/environments/{environmentId}/*`
 * route group was missing the `web` middleware that every other session-authenticated
 * group has. Without it, Sanctum's cookie-session guard never runs (no StartSession),
 * so a browser user logged into the operator SPA got a 401 on every tenant-scoped
 * route (monitors, incidents, api-keys, ...) despite /v1/me working fine.
 *
 * $this->actingAs() does NOT catch this class of bug: it sets the authenticated
 * user directly on the guard, bypassing the middleware stack entirely, so a test
 * using actingAs() passes whether or not `web` is present. Asserting the resolved
 * middleware list is the only way to pin this down.
 */
class TenantScopedRoutesSessionAuthTest extends TestCase
{
    public function test_tenant_scoped_monitor_routes_include_web_middleware(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'v1/tenants/{tenantId}/environments/{environmentId}/monitors'
                && in_array('GET', $r->methods(), true)
        );

        $this->assertNotNull($route, 'monitors index route not found');
        $this->assertContains('web', $route->gatherMiddleware());
    }

    public function test_tenant_scoped_incident_routes_include_web_middleware(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'v1/tenants/{tenantId}/environments/{environmentId}/incidents'
                && in_array('GET', $r->methods(), true)
        );

        $this->assertNotNull($route, 'incidents index route not found');
        $this->assertContains('web', $route->gatherMiddleware());
    }

    public function test_tenant_scoped_api_keys_routes_include_web_middleware(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'v1/tenants/{tenantId}/environments/{environmentId}/api-keys'
                && in_array('GET', $r->methods(), true)
        );

        $this->assertNotNull($route, 'api-keys index route not found');
        $this->assertContains('web', $route->gatherMiddleware());
    }

    public function test_grandpasson_tenant_mappings_route_includes_web_middleware(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'v1/platform/grandpasson/tenant-mappings'
                && in_array('POST', $r->methods(), true)
        );

        $this->assertNotNull($route, 'grandpasson tenant-mappings route not found');
        $this->assertContains('web', $route->gatherMiddleware());
    }
}
