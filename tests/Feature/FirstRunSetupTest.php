<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FirstRunSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // `stc test` runs with the file cache store, so throttle hits survive between
        // runs. The throttle itself is asserted on the route definition below.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_setup_routes_are_throttled(): void
    {
        $post = Route::getRoutes()->match(request()->create('/v1/setup', 'POST'));
        $this->assertContains('throttle:5,1', $post->gatherMiddleware());
    }

    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'a-long-password-123',
            'password_confirmation' => 'a-long-password-123',
            'org' => 'Acme',
            'slug' => 'acme',
            'locale' => 'pt-BR',
            ...$overrides,
        ];
    }

    public function test_reports_setup_required_only_while_there_are_no_users(): void
    {
        $this->getJson('/v1/setup')->assertOk()->assertJsonPath('data.setup_required', true);

        User::factory()->create();

        $this->getJson('/v1/setup')->assertOk()->assertJsonPath('data.setup_required', false);
    }

    public function test_creates_the_first_account_signs_in_and_publishes_the_status_page(): void
    {
        $this->postJson('/v1/setup', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.user.email', 'owner@example.com')
            ->assertJsonPath('data.user.is_platform_admin', true);

        $this->assertAuthenticated('web');
        $this->getJson('/v1/me')->assertOk();
        $this->assertSame(1, DB::table('status_pages')->count());
        $this->get('/status/acme')->assertOk()->assertSee('Acme');
    }

    public function test_is_refused_once_any_user_exists(): void
    {
        User::factory()->create();

        $this->postJson('/v1/setup', $this->payload())->assertForbidden();
        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(0, DB::table('tenants')->count());
    }

    public function test_allowlist_rejects_other_addresses(): void
    {
        config()->set('setup.allowed_emails', ['allowed@example.com']);

        $this->postJson('/v1/setup', $this->payload())->assertForbidden();
        $this->postJson('/v1/setup', $this->payload(['email' => 'Allowed@Example.com']))->assertCreated();
    }

    public function test_production_requires_an_allowlist(): void
    {
        config()->set('setup.allowed_emails', []);
        $this->app->detectEnvironment(fn () => 'production');
        // Outside testing, Sanctum enforces CSRF; this test is about the allowlist gate.
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
            \App\Http\Middleware\ValidateCsrfTokenUnlessBearer::class,
        ]);

        $this->postJson('/v1/setup', $this->payload())->assertForbidden();
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_validates_password_strength_and_slug(): void
    {
        $this->postJson('/v1/setup', $this->payload([
            'password' => 'short', 'password_confirmation' => 'short', 'slug' => 'Not A Slug',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['password', 'slug']);
    }
}
