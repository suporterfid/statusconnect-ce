<?php

namespace Tests\Feature;

use App\Application\StatusPages\StatusPageService;
use App\Application\Tenancy\AccountProvisioningService;
use App\Application\Tenancy\PlatformAdministrationService;
use App\Application\Tenancy\PlatformUserAdministration;
use App\Application\Tenancy\ProvisionedAccount;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\TenantMembership;
use App\Infrastructure\Persistence\Eloquent\User;
use App\Mail\AccountMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Platform administration of users and organizations (#248).
 */
class PlatformUserAndOrganizationManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ProvisionedAccount $acme;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['legal.require_acceptance' => false]);
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->admin = User::factory()->create(['email' => 'root@platform.test', 'is_platform_admin' => true]);
        $this->acme = app(AccountProvisioningService::class)->provision([
            'email' => 'owner@acme.test', 'name' => 'Owner', 'org' => 'Acme', 'slug' => 'acme', 'password' => 'owner-password-123',
        ]);
    }

    private function user(string $email, array $attributes = []): User
    {
        return User::factory()->create(['email' => $email, 'password' => 'secret-password-1'] + $attributes);
    }

    /** Each test request runs in the same app; a real request would load the user again. */
    private function nextRequest(): void
    {
        Auth::forgetGuards();
    }

    public function test_non_platform_admins_get_403_on_every_new_endpoint(): void
    {
        $owner = $this->acme->user;
        $tenant = $this->acme->tenant->public_id;
        $membership = $this->acme->membership->public_id;
        $this->actingAs($owner);

        $this->postJson('/v1/platform/users', ['name' => 'X', 'email' => 'x@x.test'])->assertForbidden();
        $this->getJson("/v1/platform/users/{$this->admin->public_id}")->assertForbidden();
        $this->patchJson("/v1/platform/users/{$this->admin->public_id}", ['name' => 'Hacked'])->assertForbidden();
        $this->postJson("/v1/platform/users/{$this->admin->public_id}/block")->assertForbidden();
        $this->postJson("/v1/platform/users/{$this->admin->public_id}/unblock")->assertForbidden();
        $this->deleteJson("/v1/platform/users/{$this->admin->public_id}/sessions")->assertForbidden();
        $this->getJson("/v1/platform/tenants/{$tenant}")->assertForbidden();
        $this->patchJson("/v1/platform/tenants/{$tenant}", ['slug' => 'stolen'])->assertForbidden();
        $this->postJson("/v1/platform/tenants/{$tenant}/members", ['email' => 'y@y.test', 'role' => 'owner'])->assertForbidden();
        $this->patchJson("/v1/platform/tenants/{$tenant}/members/{$membership}", ['role' => 'viewer'])->assertForbidden();
        $this->deleteJson("/v1/platform/tenants/{$tenant}/members/{$membership}")->assertForbidden();
        $this->postJson("/v1/platform/tenants/{$tenant}/transfer-ownership", ['membership_id' => $membership])->assertForbidden();

        $this->assertSame('acme', $this->acme->tenant->fresh()->slug);
        $this->assertNull($this->admin->fresh()->blocked_at);
        $this->assertSame(0, DB::table('audit_logs')->where('actor_user_id', $owner->id)->count());
    }

    public function test_creates_a_user_who_sets_the_password_from_the_emailed_link(): void
    {
        $this->actingAs($this->admin);

        $this->postJson('/v1/platform/users', ['name' => 'Bia', 'email' => 'Bia@Example.test'])
            ->assertCreated()
            ->assertJsonPath('data.email', 'bia@example.test')
            ->assertJsonPath('data.email_verified', false)
            ->assertJsonPath('data.memberships', []);

        $url = null;
        Mail::assertSent(AccountMail::class, function (AccountMail $mail) use (&$url): bool {
            $url = $mail->data['url'] ?? null;

            return $mail->kind === 'invite' && $mail->hasTo('bia@example.test');
        });
        $this->assertTrue(DB::table('audit_logs')->where('action', 'user.created')->where('actor_user_id', $this->admin->id)->exists());

        // The link is the forgot-password reset: choosing the password confirms the address.
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $token = basename((string) parse_url($url, PHP_URL_PATH));
        $this->post('/redefinir-senha', [
            'token' => $token, 'email' => $query['email'],
            'password' => 'brand-new-pass-1', 'password_confirmation' => 'brand-new-pass-1',
        ])->assertOk();
        $this->assertNotNull(User::query()->where('email', 'bia@example.test')->value('email_verified_at'));

        $this->postJson('/v1/platform/users', ['name' => 'Dup', 'email' => 'bia@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email']);
    }

    public function test_creates_a_user_inside_an_organization_with_a_role(): void
    {
        $this->actingAs($this->admin);

        $id = $this->postJson('/v1/platform/users', [
            'name' => 'Caio', 'email' => 'caio@acme.test', 'tenant_id' => $this->acme->tenant->public_id, 'role' => 'admin',
        ])->assertCreated()
            ->assertJsonPath('data.memberships.0.tenant.name', 'Acme')
            ->assertJsonPath('data.memberships.0.role', 'admin')
            ->json('data.id');

        $this->assertTrue(DB::table('audit_logs')->where('action', 'member.added')->where('tenant_id', $this->acme->tenant->id)->exists());
        $this->getJson("/v1/platform/users/{$id}")->assertOk()->assertJsonPath('data.sign_in_methods', ['password']);
        $this->postJson('/v1/platform/users', ['name' => 'X', 'email' => 'x@acme.test', 'tenant_id' => 'ten_missing', 'role' => 'viewer'])
            ->assertUnprocessable()->assertJsonValidationErrors(['tenant_id']);
    }

    public function test_edits_name_and_email_and_a_new_email_must_be_confirmed(): void
    {
        $user = $this->user('old@example.test', ['google_sub' => 'google-123']);
        $this->user('taken@example.test');
        $this->actingAs($this->admin);

        $this->patchJson("/v1/platform/users/{$user->public_id}", ['name' => 'New Name', 'email' => 'New@Example.test'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.email', 'new@example.test')
            ->assertJsonPath('data.email_verified', false)
            ->assertJsonPath('data.sign_in_methods', ['password']);

        $user->refresh();
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->google_sub);
        Mail::assertSent(AccountMail::class, fn (AccountMail $m) => $m->kind === 'verify' && $m->hasTo('new@example.test'));
        $log = DB::table('audit_logs')->where('action', 'user.updated')->first();
        $this->assertStringContainsString('old@example.test', $log->summary_json);

        $this->patchJson("/v1/platform/users/{$user->public_id}", ['email' => 'taken@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email']);
        $this->patchJson("/v1/platform/users/{$this->admin->public_id}", ['email' => 'other@platform.test'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email']);
        $this->patchJson("/v1/platform/users/{$user->public_id}", [])->assertUnprocessable();

        // An unverified address cannot sign in until the link is opened.
        Auth::logout();
        $this->postJson('/v1/auth/login', ['email' => 'new@example.test', 'password' => 'secret-password-1'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', __('messages.email_not_verified'));
    }

    public function test_blocked_user_cannot_sign_in_with_a_password_and_unblocking_restores_it(): void
    {
        $owner = $this->acme->user;
        $this->actingAs($this->admin);

        $this->postJson("/v1/platform/users/{$owner->public_id}/block")->assertOk()->assertJsonPath('data.blocked', true);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'user.blocked')->exists());

        Auth::logout();
        $this->postJson('/v1/auth/login', ['email' => 'owner@acme.test', 'password' => 'owner-password-123'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', __('messages.account_blocked'));
        $this->assertGuest();
        $this->assertNull($owner->fresh()->last_login_at);

        $this->actingAs($this->admin);
        $this->postJson("/v1/platform/users/{$owner->public_id}/unblock")->assertOk()->assertJsonPath('data.blocked', false);
        Auth::logout();
        $this->postJson('/v1/auth/login', ['email' => 'owner@acme.test', 'password' => 'owner-password-123'])->assertOk();
        $owner->refresh();
        $this->assertNotNull($owner->last_login_at);
        $this->assertSame('password', $owner->last_login_method);
    }

    public function test_blocking_ends_open_sessions_even_with_file_sessions(): void
    {
        $owner = $this->acme->user;
        $this->postJson('/v1/auth/login', ['email' => 'owner@acme.test', 'password' => 'owner-password-123'])->assertOk();
        $this->getJson('/v1/me')->assertOk();

        app(PlatformUserAdministration::class)->block($this->admin, $owner);
        $this->nextRequest();

        $this->getJson('/v1/me')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_blocking_deletes_database_sessions(): void
    {
        config(['session.driver' => 'database']);
        $owner = $this->acme->user;
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $owner->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($this->admin);

        $this->postJson("/v1/platform/users/{$owner->public_id}/block")->assertOk();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $owner->id)->count());
    }

    public function test_end_all_sessions_signs_the_user_out_who_can_sign_in_again(): void
    {
        $owner = $this->acme->user;
        $this->postJson('/v1/auth/login', ['email' => 'owner@acme.test', 'password' => 'owner-password-123'])->assertOk();
        $this->getJson('/v1/me')->assertOk();

        app(PlatformUserAdministration::class)->endSessions($this->admin, $owner);
        $this->nextRequest();

        $this->getJson('/v1/me')->assertUnauthorized();
        $this->postJson('/v1/auth/login', ['email' => 'owner@acme.test', 'password' => 'owner-password-123'])->assertOk();
        $this->getJson('/v1/me')->assertOk();
        $this->assertSame(1, $owner->fresh()->session_version);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'user.sessions_ended')->exists());
    }

    public function test_end_sessions_endpoint(): void
    {
        $owner = $this->acme->user;
        $this->actingAs($this->admin);

        $this->deleteJson("/v1/platform/users/{$owner->public_id}/sessions")->assertOk()->assertJsonPath('data.tokens', 0);

        $this->assertSame(1, $owner->fresh()->session_version);
    }

    public function test_admin_cannot_block_themselves_and_the_last_active_admin_is_protected(): void
    {
        $this->actingAs($this->admin);
        $this->postJson("/v1/platform/users/{$this->admin->public_id}/block")
            ->assertUnprocessable()->assertJsonValidationErrors(['user']);
        $this->assertNull($this->admin->fresh()->blocked_at);

        // Outside the API (an actor that is not an active admin) the last admin still cannot be blocked or demoted.
        $other = $this->user('other@example.test');
        try {
            app(PlatformUserAdministration::class)->block($other, $this->admin);
            $this->fail('The last platform admin was blocked.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('user', $e->errors());
        }
        try {
            app(PlatformAdministrationService::class)->setPlatformAdmin($other, $this->admin, false);
            $this->fail('The last platform admin was demoted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('is_platform_admin', $e->errors());
        }

        // With a second admin, one can block the other.
        $second = $this->user('second@platform.test', ['is_platform_admin' => true]);
        $this->postJson("/v1/platform/users/{$second->public_id}/block")->assertOk();
    }

    public function test_user_detail_shows_organizations_dates_and_methods(): void
    {
        $owner = $this->acme->user;
        $owner->forceFill(['google_sub' => 'g-1', 'sign_in_methods' => ['password', 'grandpasson'], 'last_login_method' => 'grandpasson'])->save();
        $this->actingAs($this->admin);

        $this->getJson("/v1/platform/users/{$owner->public_id}")
            ->assertOk()
            ->assertJsonPath('data.memberships.0.role', 'owner')
            ->assertJsonPath('data.sign_in_methods', ['password', 'google', 'grandpasson'])
            ->assertJsonPath('data.blocked', false)
            ->assertJsonPath('data.is_platform_admin', false)
            ->assertJsonStructure(['data' => ['created_at', 'last_login_at', 'email_verified_at', 'blocked_at', 'last_login_method']]);
        $this->getJson('/v1/platform/users/usr_missing')->assertNotFound();
    }

    public function test_organization_detail(): void
    {
        $tenant = $this->acme->tenant;
        $env = $this->acme->environment;
        foreach ([['http', true], ['http', false], ['tcp', true]] as $i => [$kind, $enabled]) {
            Monitor::query()->create([
                'tenant_id' => $tenant->id, 'environment_id' => $env->id, 'name' => "m{$i}", 'kind' => $kind,
                'target' => $kind === 'tcp' ? 'example.test:443' : "https://m{$i}.example.test", 'enabled' => $enabled,
            ]);
        }
        $subscriptionId = DB::table('billing_subscriptions')->insertGetId([
            'tenant_id' => $tenant->id, 'provider' => 'asaas', 'subscription_id' => 'sub_1', 'plan' => 'pro', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('billing_invoices')->insert([
            'tenant_id' => $tenant->id, 'billing_subscription_id' => $subscriptionId, 'asaas_invoice_id' => 'inv_1',
            'status' => 'AUTHORIZED', 'number' => '42', 'value_cents' => 1900, 'effective_date' => '2026-11-10',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->admin);
        $this->patchJson("/v1/platform/tenants/{$tenant->public_id}", ['name' => 'Acme Ltda'])->assertOk();

        $this->getJson("/v1/platform/tenants/{$tenant->public_id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Acme Ltda')
            ->assertJsonPath('data.members.0.user.email', 'owner@acme.test')
            ->assertJsonPath('data.members.0.role', 'owner')
            ->assertJsonPath('data.monitors.total', 3)
            ->assertJsonPath('data.monitors.enabled', 2)
            ->assertJsonPath('data.monitors.paused', 1)
            ->assertJsonPath('data.monitors.by_kind.0', ['kind' => 'http', 'enabled' => 1, 'paused' => 1])
            ->assertJsonPath('data.status_pages.0.slug', 'acme')
            ->assertJsonPath('data.status_pages.0.url', url('/status/acme'))
            ->assertJsonPath('data.subscription.status', 'active')
            ->assertJsonPath('data.invoices.0.number', '42')
            ->assertJsonPath('data.audit.0.action', 'tenant.updated')
            ->assertJsonPath('data.audit.0.actor_email', 'root@platform.test');
    }

    public function test_changing_the_slug_moves_the_status_page_and_the_old_address_redirects(): void
    {
        $tenant = $this->acme->tenant;
        $this->actingAs($this->admin);

        $this->patchJson("/v1/platform/tenants/{$tenant->public_id}", ['slug' => 'acme-brasil'])
            ->assertOk()->assertJsonPath('data.slug', 'acme-brasil');

        $this->assertSame('acme-brasil', DB::table('status_pages')->where('tenant_id', $tenant->id)->value('slug'));
        Auth::logout();
        $this->get('/status/acme-brasil')->assertOk();
        $this->get('/status/acme')->assertStatus(301)->assertRedirect(url('/status/acme-brasil'));
        $this->get('/status/acme.json')->assertStatus(301)->assertRedirect(url('/status/acme-brasil.json'));
        $this->get('/status/acme/history?days=30')->assertStatus(301)->assertRedirect(url('/status/acme-brasil/history?days=30'));
        $this->get('/status/nobody')->assertNotFound();

        // A second rename: both old addresses lead to the current one.
        $this->actingAs($this->admin);
        $this->patchJson("/v1/platform/tenants/{$tenant->public_id}", ['slug' => 'acme-global'])->assertOk();
        Auth::logout();
        $this->get('/status/acme')->assertRedirect(url('/status/acme-global'));
        $this->get('/status/acme-brasil')->assertRedirect(url('/status/acme-global'));

        $log = DB::table('audit_logs')->where('action', 'tenant.updated')->latest('id')->first();
        $this->assertStringContainsString('acme-brasil', $log->summary_json);
    }

    public function test_slug_validation_is_the_same_as_creation_and_a_live_page_wins_over_a_redirect(): void
    {
        $other = app(AccountProvisioningService::class)->provision([
            'email' => 'owner@beta.test', 'name' => 'Beta', 'org' => 'Beta', 'slug' => 'beta',
        ]);
        $this->actingAs($this->admin);

        $this->patchJson("/v1/platform/tenants/{$this->acme->tenant->public_id}", ['slug' => 'beta'])
            ->assertUnprocessable()->assertJsonValidationErrors(['slug']);
        $this->patchJson("/v1/platform/tenants/{$this->acme->tenant->public_id}", ['slug' => 'Not Valid'])
            ->assertUnprocessable()->assertJsonValidationErrors(['slug']);

        // acme moves away; beta then takes "acme", which stops redirecting.
        $this->patchJson("/v1/platform/tenants/{$this->acme->tenant->public_id}", ['slug' => 'acme-new'])->assertOk();
        $this->patchJson("/v1/platform/tenants/{$other->tenant->public_id}", ['slug' => 'acme'])->assertOk();
        Auth::logout();
        $this->get('/status/acme')->assertOk();
        $this->get('/status/beta')->assertRedirect(url('/status/acme'));
    }

    public function test_operators_renaming_their_status_page_also_keep_a_redirect(): void
    {
        app(StatusPageService::class)->upsert($this->acme->tenant, $this->acme->environment, ['slug' => 'acme-status']);

        $this->get('/status/acme')->assertStatus(301)->assertRedirect(url('/status/acme-status'));
    }

    public function test_manages_members_without_ever_leaving_an_organization_without_owner(): void
    {
        $tenant = $this->acme->tenant->public_id;
        $existing = $this->user('dev@example.test');
        $this->actingAs($this->admin);

        $added = $this->postJson("/v1/platform/tenants/{$tenant}/members", ['email' => 'dev@example.test', 'role' => 'viewer'])
            ->assertCreated()->assertJsonPath('data.user.id', $existing->public_id)->assertJsonPath('temporary_password', null)
            ->json('data.id');
        $this->assertIsString(
            $this->postJson("/v1/platform/tenants/{$tenant}/members", ['email' => 'novo@example.test', 'role' => 'admin'])
                ->assertCreated()->json('temporary_password')
        );
        $this->postJson("/v1/platform/tenants/{$tenant}/members", ['email' => 'dev@example.test', 'role' => 'admin'])
            ->assertUnprocessable();

        $this->patchJson("/v1/platform/tenants/{$tenant}/members/{$added}", ['role' => 'admin'])->assertOk()->assertJsonPath('data.role', 'admin');
        $this->patchJson("/v1/platform/tenants/{$tenant}/members/{$this->acme->membership->public_id}", ['role' => 'admin'])
            ->assertUnprocessable()->assertJsonValidationErrors(['role']);
        $this->deleteJson("/v1/platform/tenants/{$tenant}/members/{$this->acme->membership->public_id}")
            ->assertUnprocessable()->assertJsonValidationErrors(['role']);
        $this->deleteJson("/v1/platform/tenants/{$tenant}/members/{$added}")->assertNoContent();

        $this->assertSame('owner', $this->acme->membership->fresh()->role);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'member.removed')->where('actor_user_id', $this->admin->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'member.role_changed')->where('actor_user_id', $this->admin->id)->count());
    }

    public function test_transfers_ownership_to_another_member(): void
    {
        $tenant = $this->acme->tenant->public_id;
        $this->actingAs($this->admin);
        $newOwner = $this->postJson("/v1/platform/tenants/{$tenant}/members", ['email' => 'next@acme.test', 'role' => 'viewer'])->json('data.id');

        $this->postJson("/v1/platform/tenants/{$tenant}/transfer-ownership", ['membership_id' => $newOwner])
            ->assertOk()->assertJsonPath('data.role', 'owner')->assertJsonPath('data.user.email', 'next@acme.test');

        $this->assertSame('admin', $this->acme->membership->fresh()->role);
        $this->assertSame(1, TenantMembership::query()->where('tenant_id', $this->acme->tenant->id)->where('role', 'owner')->count());
        $this->assertTrue(DB::table('audit_logs')->where('action', 'tenant.ownership_transferred')->exists());
        $this->postJson("/v1/platform/tenants/{$tenant}/transfer-ownership", ['membership_id' => 'mem_missing'])->assertNotFound();
    }

    public function test_sensitive_writes_are_rate_limited_per_admin(): void
    {
        $owner = $this->acme->user;
        $this->actingAs($this->admin);

        for ($i = 0; $i < 30; $i++) {
            $this->postJson("/v1/platform/users/{$owner->public_id}/unblock")->assertOk();
        }

        $this->postJson("/v1/platform/users/{$owner->public_id}/unblock")->assertStatus(429);
    }
}
