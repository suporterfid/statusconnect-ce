<?php

namespace Tests\Feature;

use App\Application\Tenancy\MembershipService;
use App\Application\Tenancy\TenantService;
use App\Domain\Shared\TenantRole;
use App\Infrastructure\Google\GoogleIdTokenVerifier;
use App\Infrastructure\Google\GoogleOidcClient;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use App\Mail\AccountMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Sign in with Google (#244). Google's token and JWKS endpoints are faked; the
 * ID tokens are real RS256 JWTs signed with a key generated for the test.
 */
class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = 'client-123.apps.googleusercontent.com';

    /** @var array{private: string, n: string, e: string}|null */
    private static ?array $key = null;

    /** @var array{private: string, n: string, e: string}|null */
    private static ?array $otherKey = null;

    /** Claims the fake token endpoint signs next. */
    private array $claims = [];

    private ?string $challenge = null;

    private ?string $signWith = null;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        config([
            'google.login_enabled' => true,
            'google.client_id' => self::CLIENT_ID,
            'google.client_secret' => 'test-secret',
            'google.redirect_uri' => '',
            'app.url' => 'https://app.example.test',
            'signup.mode' => 'open',
            'legal.require_acceptance' => false,
        ]);
        self::$key ??= self::generateKey();
        self::$otherKey ??= self::generateKey();
        $this->fakeGoogle();
    }

    /** @return array{private: string, n: string, e: string} */
    private static function generateKey(): array
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $private);
        $details = openssl_pkey_get_details($resource);

        return ['private' => $private, 'n' => self::b64url($details['rsa']['n']), 'e' => self::b64url($details['rsa']['e'])];
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function jwt(array $claims, string $kid = 'key-1', ?string $privateKey = null): string
    {
        $header = self::b64url(json_encode(['alg' => 'RS256', 'kid' => $kid, 'typ' => 'JWT']));
        $payload = self::b64url(json_encode($claims));
        openssl_sign($header.'.'.$payload, $signature, $privateKey ?? self::$key['private'], OPENSSL_ALGO_SHA256);

        return $header.'.'.$payload.'.'.self::b64url($signature);
    }

    private function fakeGoogle(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => function (HttpRequest $request) {
                // PKCE: the verifier sent now must hash to the challenge sent in the redirect.
                if ($this->challenge === null || GoogleOidcClient::codeChallenge((string) ($request->data()['code_verifier'] ?? '')) !== $this->challenge) {
                    return Http::response(['error' => 'invalid_grant'], 400);
                }
                if (($request->data()['client_secret'] ?? null) !== 'test-secret' || ($request->data()['redirect_uri'] ?? null) !== 'https://app.example.test/auth/google/callback') {
                    return Http::response(['error' => 'invalid_client'], 401);
                }

                return Http::response(['access_token' => 'ya29.x', 'token_type' => 'Bearer', 'expires_in' => 3599, 'id_token' => $this->jwt($this->claims, 'key-1', $this->signWith)]);
            },
            'www.googleapis.com/oauth2/v3/certs' => Http::response(['keys' => [
                ['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'key-1', 'n' => self::$key['n'], 'e' => self::$key['e']],
            ]], 200, ['Cache-Control' => 'public, max-age=21600']),
        ]);
    }

    /** Starts the flow and returns the authorization request's query. */
    private function start(): array
    {
        $location = $this->get('/auth/google/redirect')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->challenge = $query['code_challenge'];

        return $query;
    }

    private function claims(array $overrides = [], ?array $query = null): array
    {
        return array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID,
            'azp' => self::CLIENT_ID,
            'sub' => '1098765432101234567890',
            'email' => 'ana@acme.com.br',
            'email_verified' => true,
            'name' => 'Ana Souza',
            'picture' => 'https://lh3.googleusercontent.com/a/photo',
            'iat' => time(),
            'exp' => time() + 3600,
            'nonce' => $query['nonce'] ?? '',
        ], $overrides);
    }

    /** Runs the whole flow with these claims and returns the callback response. */
    private function signInWithGoogle(array $overrides = [])
    {
        $query = $this->start();
        $this->claims = $this->claims($overrides, $query);

        return $this->get('/auth/google/callback?'.http_build_query(['state' => $query['state'], 'code' => '4/0Aexample']));
    }

    private function userWithTenant(string $email, array $attributes = []): User
    {
        $user = User::factory()->create(['email' => $email] + $attributes);
        app(TenantService::class)->createTenant('Acme', 'acme-'.substr(md5($email), 0, 6), $user);

        return $user;
    }

    public function test_everything_is_404_while_disabled(): void
    {
        config(['google.login_enabled' => false]);

        $this->get('/auth/google/redirect')->assertNotFound();
        $this->get('/auth/google/callback?state=x&code=y')->assertNotFound();
        $this->get('/cadastro/google')->assertNotFound();
        $this->post('/cadastro/google', [])->assertNotFound();
        $this->getJson('/v1/setup')->assertOk()->assertJsonPath('data.google_login', false);
        $this->get('/cadastro')->assertOk()->assertDontSee('class="gsi-button"', false);

        // Enabled without a secret is still off.
        config(['google.login_enabled' => true, 'google.client_secret' => '']);
        $this->get('/auth/google/redirect')->assertNotFound();

        config(['google.client_secret' => 'test-secret']);
        $this->getJson('/v1/setup')->assertOk()->assertJsonPath('data.google_login', true);
        $this->get('/cadastro')->assertOk()->assertSee('class="gsi-button" href="'.url('/auth/google/redirect').'"', false);
    }

    public function test_redirect_uses_pkce_state_nonce_and_minimal_scopes(): void
    {
        $query = $this->start();

        $this->assertSame(self::CLIENT_ID, $query['client_id']);
        $this->assertSame('https://app.example.test/auth/google/callback', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('openid email profile', $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('select_account', $query['prompt']);
        $this->assertGreaterThanOrEqual(32, strlen($query['state']));
        $this->assertGreaterThanOrEqual(32, strlen($query['nonce']));
        $this->assertNotSame($query['state'], $query['nonce']);
    }

    public function test_existing_user_is_linked_by_verified_email_and_signed_in(): void
    {
        $user = $this->userWithTenant('ana@acme.com.br');

        $this->signInWithGoogle(['email' => 'Ana@Acme.com.br'])->assertRedirect('/app/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertSame('1098765432101234567890', $user->fresh()->google_sub);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'user.google_linked')->exists());
        // Nothing else from Google is stored.
        $this->assertStringNotContainsString('lh3.googleusercontent.com', json_encode($user->fresh()->getAttributes()));
        $this->getJson('/v1/me')->assertOk()->assertJsonMissingPath('data.google_sub');
    }

    public function test_the_linked_google_account_signs_in_even_after_an_email_change(): void
    {
        $linked = $this->userWithTenant('old@acme.com.br', ['google_sub' => '1098765432101234567890']);
        $this->userWithTenant('ana@acme.com.br');

        $this->signInWithGoogle()->assertRedirect('/app/dashboard');

        $this->assertAuthenticatedAs($linked);
    }

    public function test_unconfirmed_password_signup_is_taken_over_safely(): void
    {
        $user = $this->userWithTenant('ana@acme.com.br', ['email_verified_at' => null, 'password' => 'chosen-by-someone']);

        $this->signInWithGoogle()->assertRedirect('/app/dashboard');

        $user->refresh();
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->email_verified_at);
        // Whoever registered the address without proving the mailbox loses the password.
        $this->assertFalse(Hash::check('chosen-by-someone', $user->password));
    }

    public function test_unverified_google_email_is_rejected(): void
    {
        $user = $this->userWithTenant('ana@acme.com.br');

        $this->signInWithGoogle(['email_verified' => false])->assertRedirect('/app/login?google_error=email_unverified');

        $this->assertGuest();
        $this->assertNull($user->fresh()->google_sub);
    }

    public function test_state_mismatch_is_rejected(): void
    {
        $this->userWithTenant('ana@acme.com.br');
        $query = $this->start();
        $this->claims = $this->claims([], $query);

        $this->get('/auth/google/callback?state=forged&code=4/0Aexample')->assertRedirect('/app/login?google_error=failed');
        $this->assertGuest();

        // The flow is single-use: the right state no longer works after a failed attempt.
        $this->get('/auth/google/callback?'.http_build_query(['state' => $query['state'], 'code' => '4/0Aexample']))->assertRedirect('/app/login?google_error=failed');
        $this->assertGuest();

        // A callback without any flow in the session.
        $this->get('/auth/google/callback?state=x&code=y')->assertRedirect('/app/login?google_error=failed');
    }

    public function test_nonce_mismatch_is_rejected(): void
    {
        $this->userWithTenant('ana@acme.com.br');

        $this->signInWithGoogle(['nonce' => 'another-nonce'])->assertRedirect('/app/login?google_error=failed');

        $this->assertGuest();
    }

    public function test_bad_signature_audience_issuer_or_expiry_are_rejected(): void
    {
        $user = $this->userWithTenant('ana@acme.com.br');

        $this->signWith = self::$otherKey['private'];
        $this->signInWithGoogle()->assertRedirect('/app/login?google_error=failed');
        $this->signWith = null;

        $this->signInWithGoogle(['aud' => 'someone-else.apps.googleusercontent.com', 'azp' => 'someone-else.apps.googleusercontent.com'])->assertRedirect('/app/login?google_error=failed');
        $this->signInWithGoogle(['iss' => 'https://evil.example'])->assertRedirect('/app/login?google_error=failed');
        $this->signInWithGoogle(['exp' => time() - 3600])->assertRedirect('/app/login?google_error=failed');

        $this->assertGuest();
        $this->assertNull($user->fresh()->google_sub);
    }

    public function test_cancelled_consent_returns_to_login(): void
    {
        $query = $this->start();

        $this->get('/auth/google/callback?'.http_build_query(['state' => $query['state'], 'error' => 'access_denied']))
            ->assertRedirect('/app/login?google_error=cancelled');
        Http::assertNothingSent();
    }

    public function test_suspended_or_pending_organization_stays_blocked(): void
    {
        $user = $this->userWithTenant('ana@acme.com.br');
        Tenant::query()->whereIn('id', $user->memberships()->pluck('tenant_id'))->update(['approved_at' => null]);

        $this->signInWithGoogle()->assertRedirect('/app/login?google_error=pending');

        $this->assertGuest();
    }

    public function test_blocked_user_cannot_sign_in_with_google(): void
    {
        $this->userWithTenant('ana@acme.com.br', ['google_sub' => '1098765432101234567890', 'blocked_at' => now()]);

        $this->signInWithGoogle()->assertRedirect('/app/login?google_error=blocked');

        $this->assertGuest();
    }

    public function test_google_sign_in_records_the_time_and_the_method(): void
    {
        $user = $this->userWithTenant('ana@acme.com.br');

        $this->signInWithGoogle()->assertRedirect('/app/dashboard');

        $user->refresh();
        $this->assertNotNull($user->last_login_at);
        $this->assertSame('google', $user->last_login_method);
        $this->assertSame(['google'], $user->sign_in_methods);
    }

    public function test_email_linked_to_another_google_account_is_refused(): void
    {
        $this->userWithTenant('ana@acme.com.br', ['google_sub' => 'another-google-account']);

        $this->signInWithGoogle()->assertRedirect('/app/login?google_error=mismatch');

        $this->assertGuest();
    }

    public function test_added_member_accepts_the_invitation_with_google(): void
    {
        $owner = $this->userWithTenant('owner@acme.com.br');
        $tenant = Tenant::query()->whereIn('id', $owner->memberships()->pluck('tenant_id'))->firstOrFail();
        app(MembershipService::class)->add($owner, $tenant, 'convidada@gmail.com', 'Convidada', TenantRole::VIEWER);

        $this->signInWithGoogle(['email' => 'convidada@gmail.com', 'sub' => '555'])->assertRedirect('/app/dashboard');

        $member = User::query()->where('email', 'convidada@gmail.com')->firstOrFail();
        $this->assertAuthenticatedAs($member);
        $this->assertSame('555', $member->google_sub);
        $this->getJson('/v1/me/memberships')->assertOk()->assertJsonFragment(['role' => 'viewer']);
    }

    public function test_new_address_with_sign_up_closed_is_refused(): void
    {
        config(['signup.mode' => 'closed']);

        $this->signInWithGoogle()->assertRedirect('/app/login?google_error=no_account');

        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
    }

    public function test_new_address_in_open_mode_creates_a_verified_account(): void
    {
        $this->signInWithGoogle()->assertRedirect(route('signup.google'));
        $this->assertSame(0, User::query()->count());

        $this->get('/cadastro/google')->assertOk()
            ->assertSee('ana@acme.com.br')
            ->assertSee('value="Ana Souza"', false);

        $this->post('/cadastro/google', ['name' => 'Ana Souza', 'organization' => 'Acme Ltda'])
            ->assertSessionHasErrors('accept_terms');

        $this->post('/cadastro/google', ['name' => 'Ana Souza', 'organization' => 'Acme Ltda', 'accept_terms' => '1'])
            ->assertRedirect('/app/dashboard');

        $user = User::query()->where('email', 'ana@acme.com.br')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('1098765432101234567890', $user->google_sub);
        $this->assertNotNull($user->email_verified_at);
        $tenant = Tenant::query()->whereIn('id', $user->memberships()->pluck('tenant_id'))->firstOrFail();
        $this->assertNotNull($tenant->approved_at);
        $this->assertNotNull($tenant->signed_up_at);
        $this->assertTrue(DB::table('terms_acceptances')->where('user_id', $user->id)->where('method', 'signup')->exists());
        // Google verified the address: no confirmation email.
        Mail::assertNotSent(AccountMail::class, fn (AccountMail $mail) => $mail->kind === 'verify');
        // The pending identity is gone.
        $this->get('/cadastro/google')->assertRedirect(route('signup'));
    }

    public function test_new_address_in_approval_mode_waits_for_approval(): void
    {
        config(['signup.mode' => 'approval']);
        User::factory()->create(['email' => 'admin@example.test', 'is_platform_admin' => true]);

        $this->signInWithGoogle()->assertRedirect(route('signup.google'));
        $this->post('/cadastro/google', ['name' => 'Ana Souza', 'organization' => 'Acme Ltda', 'accept_terms' => '1'])
            ->assertOk()
            ->assertSee('aguarda aprovação');

        $this->assertGuest();
        $user = User::query()->where('email', 'ana@acme.com.br')->firstOrFail();
        $tenant = Tenant::query()->whereIn('id', $user->memberships()->pluck('tenant_id'))->firstOrFail();
        $this->assertNull($tenant->approved_at);
        Mail::assertSent(AccountMail::class, fn (AccountMail $mail) => $mail->kind === 'admin_new_signup');

        // Signing in again with Google is refused until a platform admin approves.
        $this->signInWithGoogle()->assertRedirect('/app/login?google_error=pending');
        $this->assertGuest();
    }

    public function test_google_sign_up_follows_the_abuse_rules(): void
    {
        // The sign-up limits (#218) apply too. The core has none; one refusing acme.com.br stands in (#250).
        // Bound before the first request: the route keeps its controller, and with it the sign-up service.
        $this->app->instance(\App\Application\Signup\SignupLimits::class, new class implements \App\Application\Signup\SignupLimits
        {
            public function assertCanSignUp(string $emailDomain): void
            {
                if ($emailDomain !== 'acme.com.br') {
                    return;
                }
                throw \Illuminate\Validation\ValidationException::withMessages(['email' => [__('messages.signup_too_many')]]);
            }

            public function recordSignUp(): void
            {
            }

            public function assertNewAccountRate(\App\Infrastructure\Persistence\Eloquent\Tenant $tenant, string $kind, int $intervalSeconds, ?int $exceptMonitorId = null): void
            {
            }
        });

        $this->signInWithGoogle(['email' => 'bot@mailinator.com'])->assertRedirect(route('signup.google'));

        $this->post('/cadastro/google', ['name' => 'Bot', 'organization' => 'Bots', 'accept_terms' => '1'])
            ->assertSessionHasErrors('email');
        $this->assertSame(0, User::query()->count());

        $this->signInWithGoogle()->assertRedirect(route('signup.google'));
        $this->post('/cadastro/google', ['name' => 'Ana', 'organization' => 'Acme', 'accept_terms' => '1'])
            ->assertSessionHasErrors('email');
        $this->assertNull(User::query()->where('email', 'ana@acme.com.br')->first());
    }

    public function test_signing_keys_are_cached_and_refetched_for_an_unknown_key_id(): void
    {
        $this->userWithTenant('ana@acme.com.br');
        $this->signInWithGoogle()->assertRedirect('/app/dashboard');
        $this->signInWithGoogle()->assertRedirect('/app/dashboard');

        Http::assertSentCount(3); // two token calls, one JWKS fetch
        $this->assertIsArray(Cache::get('google-login:jwks'));
    }

    public function test_rsa_pem_from_jwk_matches_openssl(): void
    {
        $resource = openssl_pkey_get_private(self::$key['private']);
        $expected = openssl_pkey_get_details($resource)['key'];

        $this->assertSame(
            str_replace("\n", '', $expected),
            str_replace("\n", '', GoogleIdTokenVerifier::rsaPem(GoogleIdTokenVerifier::base64UrlDecode(self::$key['n']), GoogleIdTokenVerifier::base64UrlDecode(self::$key['e']))),
        );
    }
}
