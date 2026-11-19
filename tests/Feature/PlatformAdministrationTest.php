<?php

namespace Tests\Feature;

use App\Application\Tenancy\TenantService;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlatformAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['email' => 'root@platform.test', 'is_platform_admin' => true]);
    }

    public function test_non_platform_admins_get_403_everywhere(): void
    {
        $owner = User::factory()->create();
        app(TenantService::class)->createTenant('Acme', 'acme', $owner);
        $this->actingAs($owner);

        $this->getJson('/v1/platform/users')->assertForbidden();
        $this->getJson('/v1/platform/tenants')->assertForbidden();
        $this->postJson('/v1/platform/tenants', ['name' => 'X', 'slug' => 'x', 'owner_email' => 'x@x.test'])->assertForbidden();
        $this->patchJson("/v1/platform/users/{$owner->public_id}", ['is_platform_admin' => true])->assertForbidden();
        $this->postJson("/v1/platform/users/{$this->admin->public_id}/reset-password")->assertForbidden();
        $this->assertFalse($owner->fresh()->isPlatformAdmin());
    }

    public function test_lists_users_with_their_organizations_and_searches(): void
    {
        $owner = User::factory()->create(['email' => 'ana@acme.test', 'name' => 'Ana']);
        app(TenantService::class)->createTenant('Acme', 'acme', $owner);
        $this->actingAs($this->admin);

        $this->getJson('/v1/platform/users')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/v1/platform/users?q=acme')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.memberships.0.tenant.name', 'Acme')
            ->assertJsonPath('data.0.memberships.0.role', 'owner');
    }

    public function test_grants_and_revokes_platform_admin_but_not_on_yourself(): void
    {
        $user = User::factory()->create();
        $this->actingAs($this->admin);

        $this->patchJson("/v1/platform/users/{$user->public_id}", ['is_platform_admin' => true])->assertOk()->assertJsonPath('data.is_platform_admin', true);
        $this->patchJson("/v1/platform/users/{$user->public_id}", ['is_platform_admin' => false])->assertOk();
        $this->patchJson("/v1/platform/users/{$this->admin->public_id}", ['is_platform_admin' => false])
            ->assertUnprocessable()->assertJsonValidationErrors(['is_platform_admin']);
        $this->assertTrue($this->admin->fresh()->isPlatformAdmin());
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'user.platform_admin_changed')->count());
    }

    public function test_password_reset_returns_a_working_temporary_password_and_is_audited_without_it(): void
    {
        $user = User::factory()->create(['password' => 'old-password-123']);
        $this->actingAs($this->admin);

        $password = $this->postJson("/v1/platform/users/{$user->public_id}/reset-password")->assertOk()->json('data.temporary_password');

        $this->assertTrue(Hash::check($password, $user->fresh()->password));
        $this->assertFalse(Hash::check('old-password-123', $user->fresh()->password));
        $log = DB::table('audit_logs')->where('action', 'user.password_reset')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString($password, $log->summary_json);
    }

    public function test_creates_an_organization_with_owner_environment_and_status_page(): void
    {
        $this->actingAs($this->admin);

        $response = $this->postJson('/v1/platform/tenants', [
            'name' => 'Nova Org', 'slug' => 'nova-org', 'owner_email' => 'Dona@Nova.test', 'owner_name' => 'Dona',
        ])->assertCreated()->assertJsonPath('data.slug', 'nova-org');

        $this->assertIsString($response->json('temporary_password'));
        // #174: the new owner can sign in; no confirmation link is ever sent.
        $this->assertNotNull(User::query()->where('email', 'dona@nova.test')->value('email_verified_at'));
        $this->get('/status/nova-org')->assertOk();
        $this->getJson('/status/nova-org.json')->assertJsonPath('locale', 'pt_BR');
        $this->getJson('/v1/platform/tenants')->assertOk()
            ->assertJsonPath('data.0.slug', 'nova-org')
            ->assertJsonPath('data.0.members', 1)
            ->assertJsonPath('data.0.status_pages', ['nova-org'])
            // The plans an admin can assign (#250).
            ->assertJsonPath('meta.plans', app(\App\Application\Plans\PlanCatalog::class)->all());

        $this->postJson('/v1/platform/tenants', ['name' => 'Dup', 'slug' => 'nova-org', 'owner_email' => 'x@x.test'])
            ->assertUnprocessable()->assertJsonValidationErrors(['slug']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'tenant.created')->count());
    }

    public function test_existing_owner_gets_no_new_password_when_creating_an_organization(): void
    {
        $existing = User::factory()->create(['email' => 'busy@owner.test']);
        $hash = $existing->password;
        $this->actingAs($this->admin);

        $this->postJson('/v1/platform/tenants', ['name' => 'Second', 'slug' => 'second', 'owner_email' => 'busy@owner.test'])
            ->assertCreated()->assertJsonPath('temporary_password', null);
        $this->assertSame($hash, $existing->fresh()->password);
    }
}
