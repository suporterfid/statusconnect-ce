<?php

namespace Tests\Feature;

use App\Http\Middleware\ValidateCsrfTokenUnlessBearer;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * #153: API-key and token-in-path requests must not be blocked by CSRF
 * verification, while session requests still are. Laravel skips CSRF in
 * tests, so these run the middleware with that shortcut turned off.
 */
final class CsrfApiKeyTest extends TestCase
{
    private function middleware(): ValidateCsrfTokenUnlessBearer
    {
        return new class($this->app, $this->app['encrypter']) extends ValidateCsrfTokenUnlessBearer
        {
            protected function runningUnitTests()
            {
                return false;
            }
        };
    }

    private function handle(Request $request): Response
    {
        $request->setLaravelSession($this->app['session.store']);

        return $this->middleware()->handle($request, fn () => new Response('passed'));
    }

    public function test_bearer_token_writes_skip_csrf(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $request = Request::create('/v1/tenants/ten_x/environments/env_x/monitors', $method, server: ['HTTP_AUTHORIZATION' => 'Bearer sc_example']);
            $this->assertSame('passed', $this->handle($request)->getContent(), $method);
        }
    }

    public function test_session_writes_still_need_the_csrf_token(): void
    {
        $this->expectException(TokenMismatchException::class);
        $this->handle(Request::create('/v1/tenants/ten_x/environments/env_x/monitors', 'POST'));
    }

    public function test_an_empty_bearer_header_does_not_skip_csrf(): void
    {
        $this->expectException(TokenMismatchException::class);
        $this->handle(Request::create('/v1/tenants/ten_x/environments/env_x/monitors', 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer ']));
    }

    public function test_heartbeat_and_ingest_posts_are_exempt(): void
    {
        $this->assertSame('passed', $this->handle(Request::create('/ping/abc123', 'POST'))->getContent());
        $this->assertSame('passed', $this->handle(Request::create('/ingest/sca_abc', 'POST'))->getContent());
    }

    public function test_web_group_uses_the_bearer_aware_middleware(): void
    {
        $route = Route::getRoutes()->match(Request::create('/v1/tenants/ten_x/environments/env_x/monitors', 'POST'));
        $middleware = app('router')->gatherRouteMiddleware($route);

        $this->assertContains(ValidateCsrfTokenUnlessBearer::class, $middleware);
        $this->assertNotContains(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, $middleware);
    }
}
