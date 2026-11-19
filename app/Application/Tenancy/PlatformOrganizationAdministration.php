<?php

namespace App\Application\Tenancy;

use App\Application\StatusPages\StatusPageCacheWriter;
use App\Application\StatusPages\StatusPageSlugRedirects;
use App\Domain\Shared\TenantRole;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\TenantMembership;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Organization administration by platform admins (#248): detail, name and slug,
 * ownership transfer. Member changes go through MembershipService, so its rules
 * (last owner, roles) and audit entries apply. Deleting is out of scope (#249).
 */
final class PlatformOrganizationAdministration
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly StatusPageSlugRedirects $redirects,
        private readonly StatusPageCacheWriter $statusPages,
    ) {
    }

    /**
     * Renames the organization and/or changes its slug. The status pages whose
     * address is the organization slug move with it, and their old address keeps
     * answering 301 to the new one.
     */
    public function update(User $actor, Tenant $tenant, ?string $name, ?string $slug): Tenant
    {
        $changes = [];

        DB::transaction(function () use ($actor, $tenant, $name, $slug, &$changes): void {
            $tenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->first();

            if ($name !== null && trim($name) !== $tenant->name) {
                $changes['name'] = ['from' => $tenant->name, 'to' => trim($name)];
                $tenant->name = trim($name);
            }

            if ($slug !== null && $slug !== $tenant->slug) {
                $pages = DB::table('status_pages')->where('tenant_id', $tenant->id)->where('slug', $tenant->slug)->pluck('id')->all();
                $taken = Tenant::query()->where('slug', $slug)->whereKeyNot($tenant->id)->exists()
                    || DB::table('status_pages')->where('slug', $slug)->whereNotIn('id', $pages)->exists();
                if ($taken) {
                    throw ValidationException::withMessages(['slug' => [__('messages.status_page_slug_taken')]]);
                }

                $changes['slug'] = ['from' => $tenant->slug, 'to' => $slug];
                $changes['status_pages_moved'] = count($pages);
                foreach ($pages as $pageId) {
                    DB::table('status_pages')->where('id', $pageId)->update(['slug' => $slug, 'updated_at' => now()]);
                    $this->redirects->record($pageId, $tenant->slug, $slug);
                }
                $tenant->slug = $slug;
            }

            if ($changes === []) {
                return;
            }

            $tenant->save();
            $this->audit->record($actor, 'tenant.updated', 'tenant', $tenant->public_id, $tenant, $changes);
        });

        if (isset($changes['slug'])) {
            // Snapshots carry the page address (logo URL); render the moved pages now.
            $this->statusPages->refreshStalePages();
        }

        return $tenant->refresh();
    }

    /**
     * Makes the member an owner and every other owner an admin. The organization
     * always has exactly one owner afterwards.
     */
    public function transferOwnership(User $actor, Tenant $tenant, string $membershipId): TenantMembership
    {
        return DB::transaction(function () use ($actor, $tenant, $membershipId): TenantMembership {
            $target = TenantMembership::query()->with('user')
                ->where('tenant_id', $tenant->id)->where('public_id', $membershipId)
                ->lockForUpdate()->firstOrFail();
            $previous = TenantMembership::query()->with('user')
                ->where('tenant_id', $tenant->id)->where('role', TenantRole::OWNER->value)
                ->whereKeyNot($target->id)->lockForUpdate()->get();

            if ($target->role === TenantRole::OWNER->value && $previous->isEmpty()) {
                return $target;
            }

            $target->update(['role' => TenantRole::OWNER->value]);
            foreach ($previous as $membership) {
                $membership->update(['role' => TenantRole::ADMIN->value]);
            }

            $this->audit->record($actor, 'tenant.ownership_transferred', 'tenant', $tenant->public_id, $tenant, [
                'to' => $target->user->email,
                'from' => $previous->map(fn (TenantMembership $m) => $m->user->email)->values()->all(),
            ]);

            return $target;
        });
    }

    /** Everything the Administration detail panel shows about one organization. */
    public function detail(Tenant $tenant): array
    {
        $members = TenantMembership::query()->with('user')->where('tenant_id', $tenant->id)->get()
            ->sortBy(fn (TenantMembership $m) => [array_search($m->role, ['owner', 'admin', 'viewer'], true), mb_strtolower($m->user->name)])
            ->values();

        $monitors = DB::table('monitors')->where('tenant_id', $tenant->id)
            ->select('kind', 'enabled', DB::raw('count(*) as n'))->groupBy('kind', 'enabled')->get();
        $byKind = [];
        foreach ($monitors as $row) {
            $byKind[$row->kind] ??= ['kind' => $row->kind, 'enabled' => 0, 'paused' => 0];
            $byKind[$row->kind][(bool) $row->enabled ? 'enabled' : 'paused'] += (int) $row->n;
        }
        ksort($byKind);

        $pages = DB::table('status_pages as page')
            ->join('environments as env', 'env.id', '=', 'page.environment_id')
            ->where('page.tenant_id', $tenant->id)->orderBy('page.id')
            ->get(['page.id', 'page.slug', 'page.title', 'page.visibility', 'page.custom_domain', 'env.name as environment']);

        $subscription = DB::table('billing_subscriptions')->where('tenant_id', $tenant->id)->first();
        $invoices = DB::table('billing_invoices')->where('tenant_id', $tenant->id)
            ->orderByDesc('effective_date')->orderByDesc('id')->limit(5)->get();

        $audit = DB::table('audit_logs as log')
            ->leftJoin('users as actor', 'actor.id', '=', 'log.actor_user_id')
            ->where(fn ($q) => $q->where('log.tenant_id', $tenant->id)
                ->orWhere(fn ($r) => $r->where('log.resource_type', 'tenant')->where('log.resource_id', $tenant->public_id)))
            ->orderByDesc('log.id')->limit(20)
            ->get(['log.action', 'log.resource_type', 'log.summary_json', 'log.created_at', 'actor.email as actor_email']);

        return [
            'members' => $members,
            'monitors' => [
                'total' => array_sum(array_map(fn ($k) => $k['enabled'] + $k['paused'], $byKind)),
                'enabled' => array_sum(array_column($byKind, 'enabled')),
                'paused' => array_sum(array_column($byKind, 'paused')),
                'by_kind' => array_values($byKind),
            ],
            'status_pages' => $pages->map(fn (object $p) => [
                'slug' => $p->slug,
                'title' => $p->title,
                'visibility' => $p->visibility,
                'environment' => $p->environment,
                'url' => url('/status/'.$p->slug),
                'custom_domain' => $p->custom_domain,
                'old_slugs' => $this->redirects->oldSlugs((int) $p->id),
            ])->all(),
            'subscription' => $subscription,
            'invoices' => $invoices,
            'audit' => $audit,
        ];
    }
}
