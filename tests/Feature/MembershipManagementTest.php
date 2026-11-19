<?php

namespace Tests\Feature;

use App\Application\ApiKeys\ApiKeyService;
use App\Application\Tenancy\TenantService;
use App\Domain\Shared\TenantRole;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\TenantMembership;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MembershipManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $tenant;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['email' => 'owner@acme.test']);
        $this->tenant = app(TenantService::class)->createTenant('Acme', 'acme', $this->owner);
        $this->base = "/v1/tenants/{$this->tenant->public_id}/members";
    }

    private function member(string $email, TenantRole $role, ?Tenant $tenant = null): TenantMembership
    {
        $user = User::factory()->create(['email' => $email]);

        return app(TenantService::class)->addMember($tenant ?? $this->tenant, $user, $role);
    }

    public function test_owner_adds_a_new_user_and_gets_a_one_time_temporary_password(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson($this->base, ['email' => 'New.Person@Acme.test', 'name' => 'New Person', 'role' => 'admin'])
            ->assertCreated()
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.user.email', 'new.person@acme.test');

        $password = $response->json('temporary_password');
        $this->assertIsString($password);
        $this->assertTrue(Hash::check($password, User::query()->where('email', 'new.person@acme.test')->value('password')));

        $this->getJson($this->base)->assertOk()->assertJsonCount(2, 'data')->assertJsonMissing(['temporary_password' => $password]);
        $this->assertSame(['user.created', 'member.added'], DB::table('audit_logs')->orderBy('id')->pluck('action')->all());
        $this->assertStringNotContainsString($password, DB::table('audit_logs')->pluck('summary_json')->implode(' '));
    }

    public function test_a_new_member_signs_in_with_the_temporary_password(): void
    {
        // #174: sign-in refuses unverified emails, and an added member never gets a confirmation link.
        $password = $this->actingAs($this->owner)
            ->postJson($this->base, ['email' => 'member@acme.test', 'role' => 'viewer'])
            ->assertCreated()->json('temporary_password');

        auth()->guard('web')->logout();
        $this->postJson('/v1/auth/login', ['email' => 'member@acme.test', 'password' => $password])
            ->assertOk()->assertJsonPath('data.email', 'member@acme.test');
    }

    public function test_adding_an_existing_user_returns_no_password_and_duplicates_are_refused(): void
    {
        User::factory()->create(['email' => 'existing@other.test']);

        $this->actingAs($this->owner)
            ->postJson($this->base, ['email' => 'existing@other.test', 'role' => 'viewer'])
            ->assertCreated()
            ->assertJsonPath('temporary_password', null);

        $this->postJson($this->base, ['email' => 'existing@other.test', 'role' => 'admin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_admin_manages_admins_and_viewers_but_never_owners(): void
    {
        $admin = $this->member('admin@acme.test', TenantRole::ADMIN);
        $viewer = $this->member('viewer@acme.test', TenantRole::VIEWER);
        $ownerMembership = TenantMembership::query()->where('user_id', $this->owner->id)->firstOrFail();
        $this->actingAs($admin->user);

        $this->postJson($this->base, ['email' => 'x@acme.test', 'role' => 'owner'])->assertForbidden();
        $this->postJson($this->base, ['email' => 'y@acme.test', 'role' => 'viewer'])->assertCreated();
        $this->patchJson("{$this->base}/{$viewer->public_id}", ['role' => 'admin'])->assertOk()->assertJsonPath('data.role', 'admin');
        $this->patchJson("{$this->base}/{$viewer->public_id}", ['role' => 'owner'])->assertForbidden();
        $this->patchJson("{$this->base}/{$ownerMembership->public_id}", ['role' => 'viewer'])->assertForbidden();
        $this->deleteJson("{$this->base}/{$ownerMembership->public_id}")->assertForbidden();
        $this->deleteJson("{$this->base}/{$viewer->public_id}")->assertNoContent();
    }

    public function test_viewer_can_list_but_not_change_anything(): void
    {
        $viewer = $this->member('viewer@acme.test', TenantRole::VIEWER);
        $this->actingAs($viewer->user);

        $this->getJson($this->base)->assertOk()->assertJsonPath('meta.actor_role', 'viewer');
        $this->postJson($this->base, ['email' => 'z@acme.test', 'role' => 'viewer'])->assertForbidden();
        $this->deleteJson("{$this->base}/{$viewer->public_id}")->assertForbidden();
    }

    public function test_the_last_owner_can_be_neither_demoted_nor_removed(): void
    {
        $ownerMembership = TenantMembership::query()->where('user_id', $this->owner->id)->firstOrFail();
        $this->actingAs($this->owner);

        $this->patchJson("{$this->base}/{$ownerMembership->public_id}", ['role' => 'admin'])
            ->assertUnprocessable()->assertJsonValidationErrors(['role']);
        $this->deleteJson("{$this->base}/{$ownerMembership->public_id}")->assertUnprocessable();

        // With a second owner, stepping down works.
        $this->member('second@acme.test', TenantRole::OWNER);
        $this->patchJson("{$this->base}/{$ownerMembership->public_id}", ['role' => 'admin'])->assertOk();
    }

    public function test_platform_admin_manages_any_organization_but_the_last_owner_guard_still_holds(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $ownerMembership = TenantMembership::query()->where('user_id', $this->owner->id)->firstOrFail();
        $this->actingAs($admin);

        $this->getJson($this->base)->assertOk()->assertJsonPath('meta.actor_role', 'platform_admin');
        $this->postJson($this->base, ['email' => 'p@acme.test', 'role' => 'owner'])->assertCreated();
        $this->deleteJson("{$this->base}/{$ownerMembership->public_id}")->assertNoContent();

        $remaining = TenantMembership::query()->where('tenant_id', $this->tenant->id)->where('role', 'owner')->firstOrFail();
        $this->deleteJson("{$this->base}/{$remaining->public_id}")->assertUnprocessable();
    }

    public function test_membership_ids_of_another_organization_and_non_members_get_404(): void
    {
        $otherOwner = User::factory()->create();
        $other = app(TenantService::class)->createTenant('Other', 'other', $otherOwner);
        $foreign = $this->member('foreign@other.test', TenantRole::VIEWER, $other);

        $this->actingAs($this->owner);
        $this->patchJson("{$this->base}/{$foreign->public_id}", ['role' => 'admin'])->assertNotFound();
        $this->deleteJson("{$this->base}/{$foreign->public_id}")->assertNotFound();
        $this->getJson("/v1/tenants/{$other->public_id}/members")->assertNotFound();
    }

    public function test_api_keys_cannot_manage_members(): void
    {
        $environment = $this->tenant->environments()->firstOrFail();
        $key = app(ApiKeyService::class)->create(tenant: $this->tenant, actor: $this->owner, name: 'ci', permissions: ['*'], environment: $environment);

        $this->withHeader('Authorization', 'Bearer '.$key['plaintext'])
            ->postJson($this->base, ['email' => 'k@acme.test', 'role' => 'owner'])
            ->assertForbidden();
        $this->assertSame(0, User::query()->where('email', 'k@acme.test')->count());
    }
}
