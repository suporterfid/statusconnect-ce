<?php

namespace App\Application\Tenancy;

use App\Domain\Shared\TenantRole;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cross-organization administration, for platform admins only (enforced by the controller).
 */
final class PlatformAdministrationService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly AccountProvisioningService $provisioning,
    ) {
    }

    /** @return Collection<int, User> */
    public function users(?string $search): Collection
    {
        return User::query()
            ->with(['memberships.tenant'])
            ->when($search !== null && trim($search) !== '', function ($q) use ($search): void {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($search)).'%';
                $q->where(fn ($w) => $w->where('email', 'like', $term)->orWhere('name', 'like', $term));
            })
            ->orderBy('email')
            ->limit(500)
            ->get();
    }

    public function setPlatformAdmin(User $actor, User $user, bool $value): User
    {
        if ($actor->is($user) && ! $value) {
            throw ValidationException::withMessages(['is_platform_admin' => [__('messages.cannot_demote_self')]]);
        }

        // #248: never leave the platform without an active admin.
        if (! $value && $user->isPlatformAdmin() && ! $user->isBlocked()
            && User::query()->where('is_platform_admin', true)->whereNull('blocked_at')->count() <= 1) {
            throw ValidationException::withMessages(['is_platform_admin' => [__('messages.last_platform_admin')]]);
        }

        if ((bool) $user->is_platform_admin !== $value) {
            $user->forceFill(['is_platform_admin' => $value])->save();
            $this->audit->record($actor, 'user.platform_admin_changed', 'user', $user->public_id, null, [
                'email' => $user->email, 'is_platform_admin' => $value,
            ]);
        }

        return $user->load('memberships.tenant');
    }

    public function resetPassword(User $actor, User $user): string
    {
        $password = Str::password(20, symbols: false);
        $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
        // Sign the user out where the session store allows it. Database sessions are
        // keyed by user_id; file sessions (the shared-hosting default) cannot be
        // looked up per user, so those expire on their own (SESSION_LIFETIME).
        if (config('session.driver') === 'database') {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
        $this->audit->record($actor, 'user.password_reset', 'user', $user->public_id, null, ['email' => $user->email]);

        return $password;
    }

    /** @return Collection<int, object> */
    public function tenants(): Collection
    {
        $members = DB::table('tenant_memberships')->select('tenant_id', DB::raw('count(*) as n'))->groupBy('tenant_id')->pluck('n', 'tenant_id');
        $monitors = DB::table('monitors')->select('tenant_id', DB::raw('count(*) as n'))->groupBy('tenant_id')->pluck('n', 'tenant_id');
        $pages = DB::table('status_pages')->orderBy('id')->get(['tenant_id', 'slug'])->groupBy('tenant_id');

        $billing = DB::table('billing_subscriptions')->get(['tenant_id', 'plan', 'status', 'paid_until'])->keyBy('tenant_id');

        return Tenant::query()->whereNull('archived_at')->orderByRaw('approved_at is not null')->orderBy('name')->get()->map(fn (Tenant $t) => (object) [
            'id' => $t->public_id,
            'name' => $t->name,
            'slug' => $t->slug,
            'plan' => $t->plan,
            'approved' => $t->isApproved(),
            'billing' => isset($billing[$t->id]) ? [
                'plan' => $billing[$t->id]->plan,
                'status' => $billing[$t->id]->status,
                'paid_until' => $billing[$t->id]->paid_until,
            ] : null,
            'members' => (int) ($members[$t->id] ?? 0),
            'monitors' => (int) ($monitors[$t->id] ?? 0),
            'status_pages' => $pages->get($t->id, collect())->pluck('slug')->values()->all(),
            'created_at' => $t->created_at?->toIso8601String(),
        ]);
    }

    /**
     * @return array{tenant: Tenant, temporary_password: ?string}
     */
    public function createTenant(User $actor, string $name, string $slug, string $ownerEmail, ?string $ownerName, string $locale, string $timezone): array
    {
        if (Tenant::query()->where('slug', $slug)->exists() || DB::table('status_pages')->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages(['slug' => [__('messages.status_page_slug_taken')]]);
        }

        $ownerEmail = Str::lower(trim($ownerEmail));
        $account = $this->provisioning->provision([
            'email' => $ownerEmail,
            'name' => $ownerName !== null && trim($ownerName) !== '' ? trim($ownerName) : Str::before($ownerEmail, '@'),
            'org' => $name,
            'slug' => $slug,
            'role' => TenantRole::OWNER,
            'locale' => $locale,
            'timezone' => $timezone,
        ]);

        $this->audit->record($actor, 'tenant.created', 'tenant', $account->tenant->public_id, $account->tenant, [
            'name' => $name, 'slug' => $slug, 'owner_email' => $ownerEmail,
        ]);
        if ($account->temporaryPassword !== null) {
            $this->audit->record($actor, 'user.created', 'user', $account->user->public_id, $account->tenant, ['email' => $ownerEmail]);
        }

        return ['tenant' => $account->tenant, 'temporary_password' => $account->temporaryPassword];
    }
}
