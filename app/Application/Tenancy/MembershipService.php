<?php

namespace App\Application\Tenancy;

use App\Domain\Shared\MembershipRules;
use App\Domain\Shared\TenantRole;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\TenantMembership;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Organization member management. Every write is checked against MembershipRules,
 * guarded against removing the last owner, and audited.
 */
final class MembershipService
{
    public function __construct(private readonly AuditRecorder $audit)
    {
    }

    /** The actor's effective role in the tenant; platform admins act as owners. */
    public function actorRole(User $actor, Tenant $tenant): ?TenantRole
    {
        if ($actor->isPlatformAdmin()) {
            return TenantRole::OWNER;
        }

        return TenantRole::tryFrom((string) $actor->memberships()->where('tenant_id', $tenant->id)->value('role'));
    }

    /** @return Collection<int, TenantMembership> */
    public function list(User $actor, Tenant $tenant): Collection
    {
        $this->authorize(MembershipRules::canList($this->actorRole($actor, $tenant)));

        return TenantMembership::query()
            ->with('user')
            ->where('tenant_id', $tenant->id)
            ->get()
            ->sortBy(fn (TenantMembership $m) => [array_search($m->role, ['owner', 'admin', 'viewer'], true), Str::lower($m->user->name)])
            ->values();
    }

    /**
     * @return array{membership: TenantMembership, temporary_password: ?string}
     */
    public function add(User $actor, Tenant $tenant, string $email, ?string $name, TenantRole $role): array
    {
        $this->authorize(MembershipRules::canGrant($this->actorRole($actor, $tenant), $role));
        $email = Str::lower(trim($email));

        return DB::transaction(function () use ($actor, $tenant, $email, $name, $role): array {
            $temporaryPassword = null;
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                $temporaryPassword = Str::password(20, symbols: false);
                // The admin typed the address and hands over the password, so it counts as verified (#174).
                $user = User::query()->forceCreate([
                    'name' => $name !== null && trim($name) !== '' ? trim($name) : Str::before($email, '@'),
                    'email' => $email,
                    'password' => $temporaryPassword,
                    'email_verified_at' => now(),
                ]);
                $this->audit->record($actor, 'user.created', 'user', $user->public_id, $tenant, ['email' => $email]);
            } elseif (TenantMembership::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['email' => [__('messages.member_already_exists')]]);
            }

            $membership = TenantMembership::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'role' => $role->value,
            ]);
            $this->audit->record($actor, 'member.added', 'tenant_membership', $membership->public_id, $tenant, [
                'email' => $email, 'role' => $role->value, 'new_user' => $temporaryPassword !== null,
            ]);

            return ['membership' => $membership->load('user'), 'temporary_password' => $temporaryPassword];
        });
    }

    public function changeRole(User $actor, Tenant $tenant, string $membershipId, TenantRole $role): TenantMembership
    {
        return DB::transaction(function () use ($actor, $tenant, $membershipId, $role): TenantMembership {
            $membership = $this->find($tenant, $membershipId, lock: true);
            $current = $membership->getRoleEnum();
            $actorRole = $this->actorRole($actor, $tenant);

            $this->authorize(MembershipRules::canManage($actorRole, $current) && MembershipRules::canGrant($actorRole, $role));
            $this->guardLastOwner($tenant, $current, $role);

            if ($current !== $role) {
                $membership->update(['role' => $role->value]);
                $this->audit->record($actor, 'member.role_changed', 'tenant_membership', $membership->public_id, $tenant, [
                    'email' => $membership->user->email, 'from' => $current->value, 'to' => $role->value,
                ]);
            }

            return $membership->load('user');
        });
    }

    public function remove(User $actor, Tenant $tenant, string $membershipId): void
    {
        DB::transaction(function () use ($actor, $tenant, $membershipId): void {
            $membership = $this->find($tenant, $membershipId, lock: true);
            $current = $membership->getRoleEnum();

            $this->authorize(MembershipRules::canManage($this->actorRole($actor, $tenant), $current));
            $this->guardLastOwner($tenant, $current, null);

            $membership->delete();
            $this->audit->record($actor, 'member.removed', 'tenant_membership', $membership->public_id, $tenant, [
                'email' => $membership->user->email, 'role' => $current->value,
            ]);
        });
    }

    private function find(Tenant $tenant, string $membershipId, bool $lock = false): TenantMembership
    {
        $query = TenantMembership::query()->with('user')->where('tenant_id', $tenant->id)->where('public_id', $membershipId);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    private function guardLastOwner(Tenant $tenant, TenantRole $current, ?TenantRole $next): void
    {
        if ($current !== TenantRole::OWNER || $next === TenantRole::OWNER) {
            return;
        }

        $owners = TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('role', TenantRole::OWNER->value)
            ->lockForUpdate()
            ->count();

        if (MembershipRules::removesLastOwner($current, $next, $owners)) {
            throw ValidationException::withMessages(['role' => [__('messages.last_owner')]]);
        }
    }

    private function authorize(bool $allowed): void
    {
        if (! $allowed) {
            abort(403, __('messages.member_action_forbidden'));
        }
    }
}
