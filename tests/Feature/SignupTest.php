<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use App\Mail\AccountMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Public sign-up, email verification, approval and password reset (#120).
 */
class SignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Cache::flush(); // route throttles persist in the file cache between tests
        Mail::fake();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function form(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Ana Souza',
            'email' => 'ana@acme.com.br',
            'organization' => 'Acme Ltda',
            'password' => 'uma-senha-longa',
            'password_confirmation' => 'uma-senha-longa',
            'accept_terms' => '1',
        ];
    }

    private function login(string $email = 'ana@acme.com.br', string $password = 'uma-senha-longa')
    {
        return $this->postJson('/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    public function test_closed_mode_has_no_signup_page(): void
    {
        config(['signup.mode' => 'closed']);

        $this->get('/cadastro')->assertNotFound();
        $this->post('/cadastro', $this->form())->assertNotFound();
        $this->assertSame(0, User::query()->count());
    }

    public function test_approval_mode_flow_verify_then_approve_then_login(): void
    {
        config(['signup.mode' => 'approval']);
        $admin = User::factory()->create(['is_platform_admin' => true, 'email' => 'admin@example.com']);

        $this->get('/cadastro')->assertOk()->assertSee('Criar conta');
        $this->post('/cadastro', $this->form())->assertRedirect('/cadastro/enviado');

        $user = User::query()->where('email', 'ana@acme.com.br')->firstOrFail();
        $tenant = Tenant::query()->where('name', 'Acme Ltda')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertSame(app(\App\Application\Plans\PlanCatalog::class)->defaultPlan(), $tenant->plan);
        $this->assertFalse($tenant->isApproved());
        $this->assertSame('acme-ltda', $tenant->slug);
        $this->assertTrue(DB::table('status_pages')->where('tenant_id', $tenant->id)->exists());

        $verifyUrl = null;
        Mail::assertSent(AccountMail::class, function (AccountMail $mail) use (&$verifyUrl) {
            if ($mail->kind === 'verify' && $mail->hasTo('ana@acme.com.br')) {
                $verifyUrl = $mail->data['url'];

                return true;
            }

            return false;
        });
        Mail::assertSent(AccountMail::class, fn (AccountMail $m) => $m->kind === 'admin_new_signup' && $m->hasTo('admin@example.com'));

        // Unverified: login refused.
        $this->login()->assertStatus(422)->assertJsonPath('errors.email.0', __('messages.email_not_verified'));

        $this->get($verifyUrl)->assertOk()->assertSee(__('messages.page_verified_pending_body'));
        $this->assertNotNull($user->refresh()->email_verified_at);

        // Verified but awaiting approval: login refused, tenant routes closed.
        $this->login()->assertStatus(422)->assertJsonPath('errors.email.0', __('messages.account_pending_approval'));
        $this->actingAs($user)->getJson("/v1/tenants/{$tenant->public_id}/members")->assertForbidden();
        auth()->guard('web')->logout();

        $this->actingAs($admin)->patchJson("/v1/platform/tenants/{$tenant->public_id}", ['approved' => true])
            ->assertOk()->assertJsonPath('data.approved', true);
        Mail::assertSent(AccountMail::class, fn (AccountMail $m) => $m->kind === 'approved' && $m->hasTo('ana@acme.com.br'));
        auth()->guard('web')->logout();

        $this->login()->assertOk();
    }

    public function test_open_mode_needs_only_verification(): void
    {
        config(['signup.mode' => 'open']);

        $this->post('/cadastro', $this->form())->assertRedirect('/cadastro/enviado');
        $this->assertTrue(Tenant::query()->where('name', 'Acme Ltda')->firstOrFail()->isApproved());
        Mail::assertNotSent(AccountMail::class, fn (AccountMail $m) => $m->kind === 'admin_new_signup');

        $user = User::query()->where('email', 'ana@acme.com.br')->firstOrFail();
        $url = URL::temporarySignedRoute('signup.verify', now()->addHour(), ['user' => $user->public_id, 'hash' => sha1($user->email)], absolute: false);
        $this->get($url)->assertOk()->assertSee(__('messages.page_verified_body'));

        $this->login()->assertOk();
    }

    public function test_tampered_or_expired_verification_links_are_refused(): void
    {
        config(['signup.mode' => 'open']);
        $this->post('/cadastro', $this->form());
        $user = User::query()->where('email', 'ana@acme.com.br')->firstOrFail();

        $expired = URL::temporarySignedRoute('signup.verify', now()->subMinute(), ['user' => $user->public_id, 'hash' => sha1($user->email)], absolute: false);
        $this->get($expired)->assertOk()->assertSee(__('messages.page_verify_failed_title'));
        $wrongHash = URL::temporarySignedRoute('signup.verify', now()->addHour(), ['user' => $user->public_id, 'hash' => sha1('other@example.com')], absolute: false);
        $this->get($wrongHash)->assertOk()->assertSee(__('messages.page_verify_failed_title'));
        $this->get("/cadastro/verificar/{$user->public_id}/".sha1($user->email))->assertOk()->assertSee(__('messages.page_verify_failed_title'));

        $this->assertNull($user->refresh()->email_verified_at);
    }

    public function test_validation_honeypot_blocked_domains_and_duplicates(): void
    {
        config(['signup.mode' => 'open']);

        $this->post('/cadastro', $this->form(['accept_terms' => null]))->assertSessionHasErrors('accept_terms');
        $this->post('/cadastro', $this->form(['password' => 'curta', 'password_confirmation' => 'curta']))->assertSessionHasErrors('password');
        $this->post('/cadastro', $this->form(['email' => 'x@mailinator.com']))->assertSessionHasErrors('email');

        $this->post('/cadastro', $this->form(['website' => 'http://spam.example']))->assertRedirect('/cadastro/enviado');
        $this->assertSame(0, User::query()->count());
        // Five sign-up posts per ten minutes per IP.
        $this->post('/cadastro', $this->form(['name' => '']))->assertSessionHasErrors('name');
        $this->post('/cadastro', $this->form())->assertStatus(429);
        \Illuminate\Support\Facades\Cache::flush();

        $this->post('/cadastro', $this->form())->assertRedirect('/cadastro/enviado');
        $this->post('/cadastro', $this->form(['organization' => 'Outra']))->assertSessionHasErrors('email');

        // Same organization name from someone else gets a distinct slug.
        $this->post('/cadastro', $this->form(['email' => 'bruno@acme.com.br']))->assertRedirect('/cadastro/enviado');
        $this->assertSame(2, Tenant::query()->where('name', 'Acme Ltda')->distinct()->count('slug'));
    }

    public function test_resend_answers_the_same_for_unknown_addresses(): void
    {
        config(['signup.mode' => 'open']);
        $this->post('/cadastro', $this->form());
        Mail::fake();

        $this->post('/cadastro/reenviar', ['email' => 'nobody@example.com'])->assertOk()->assertSee(__('messages.page_resend_body'));
        Mail::assertNothingSent();

        $this->post('/cadastro/reenviar', ['email' => 'ana@acme.com.br'])->assertOk()->assertSee(__('messages.page_resend_body'));
        Mail::assertSent(AccountMail::class, fn (AccountMail $m) => $m->kind === 'verify');
    }

    public function test_password_reset_flow(): void
    {
        $user = User::factory()->create(['email' => 'ana@acme.com.br', 'password' => 'senha-antiga-123']);

        $this->get('/esqueci-senha')->assertOk();
        $this->post('/esqueci-senha', ['email' => 'nobody@example.com'])->assertOk()->assertSee(__('messages.page_forgot_sent_body'));
        Mail::assertNothingSent();

        $this->post('/esqueci-senha', ['email' => 'ana@acme.com.br'])->assertOk();
        $url = null;
        Mail::assertSent(AccountMail::class, function (AccountMail $m) use (&$url) {
            $url = $m->data['url'];

            return $m->kind === 'reset';
        });
        $token = basename(parse_url($url, PHP_URL_PATH));

        $this->get($url)->assertOk()->assertSee('ana@acme.com.br');
        $this->post('/redefinir-senha', ['token' => 'wrong', 'email' => 'ana@acme.com.br', 'password' => 'nova-senha-123', 'password_confirmation' => 'nova-senha-123'])
            ->assertSessionHasErrors('email');
        $this->post('/redefinir-senha', ['token' => $token, 'email' => 'ana@acme.com.br', 'password' => 'nova-senha-123', 'password_confirmation' => 'nova-senha-123'])
            ->assertOk()->assertSee(__('messages.page_reset_done_title'));

        $this->login('ana@acme.com.br', 'senha-antiga-123')->assertStatus(422);
        $this->login('ana@acme.com.br', 'nova-senha-123')->assertOk();
    }
}
