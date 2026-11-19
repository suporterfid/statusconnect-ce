<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Plans\PlanCatalog;
use App\Application\Plans\PlanChangeService;
use App\Application\Signup\SignupService;
use App\Application\Tenancy\MembershipService;
use App\Application\Tenancy\PlatformOrganizationAdministration;
use App\Domain\Shared\TenantRole;
use App\Http\Controllers\Concerns\AuthorizesPlatformAdmin;
use App\Http\Controllers\Controller;
use App\Http\Presenters\PlatformPresenter;
use App\Http\Support\ApiDate;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\TenantMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform administration of one organization (#248): detail, name and slug,
 * plan and approval, members and ownership. Platform admins only.
 */
class PlatformTenantController extends Controller
{
    use AuthorizesPlatformAdmin;

    /** Same rule as creating an organization or editing a status page. */
    private const SLUG_RULE = 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public function __construct(
        private readonly PlatformOrganizationAdministration $organizations,
        private readonly MembershipService $members,
    ) {
    }

    public function show(Request $request, string $tenantPublicId): JsonResponse
    {
        $this->platformAdmin($request);
        $tenant = $this->find($tenantPublicId);
        $detail = $this->organizations->detail($tenant);

        return response()->json(['data' => [
            'id' => $tenant->public_id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'plan' => $tenant->plan,
            'approved' => $tenant->isApproved(),
            'approved_at' => ApiDate::format($tenant->approved_at),
            'signed_up_at' => ApiDate::format($tenant->signed_up_at),
            'trial' => [
                'started_at' => ApiDate::format($tenant->trial_started_at),
                'ends_at' => ApiDate::format($tenant->trial_ends_at),
                'ended_at' => ApiDate::format($tenant->trial_ended_at),
                'active' => $tenant->inTrial(),
            ],
            'created_at' => ApiDate::format($tenant->created_at),
            'members' => $detail['members']->map(fn (TenantMembership $m) => PlatformPresenter::member($m))->values(),
            'monitors' => $detail['monitors'],
            'status_pages' => $detail['status_pages'],
            'subscription' => $detail['subscription'] === null ? null : [
                'provider' => $detail['subscription']->provider,
                'plan' => $detail['subscription']->plan,
                'cycle' => $detail['subscription']->cycle ?? 'monthly',
                'status' => $detail['subscription']->status,
                'subscription_id' => $detail['subscription']->subscription_id,
                'paid_until' => ApiDate::format($detail['subscription']->paid_until),
                'overdue_since' => ApiDate::format($detail['subscription']->overdue_since),
            ],
            'invoices' => $detail['invoices']->map(fn (object $i) => [
                'number' => $i->number,
                'status' => $i->status,
                'value_cents' => (int) $i->value_cents,
                'effective_date' => $i->effective_date,
                'pdf_url' => $i->pdf_url,
            ])->values(),
            'audit' => $detail['audit']->map(fn (object $a) => [
                'action' => $a->action,
                'resource_type' => $a->resource_type,
                'actor_email' => $a->actor_email,
                'summary' => json_decode((string) $a->summary_json, true) ?: (object) [],
                'created_at' => ApiDate::format($a->created_at),
            ])->values(),
        ]]);
    }

    /** Name, slug, plan (#119) and approval or suspension (#120, #218); any subset. */
    public function update(Request $request, string $tenantPublicId, PlanCatalog $plans, PlanChangeService $planChanges, SignupService $signup): JsonResponse
    {
        $actor = $this->platformAdmin($request);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', 'regex:/\S/'],
            'slug' => ['sometimes', 'string', 'max:120', self::SLUG_RULE],
            'plan' => ['sometimes', 'string', 'in:'.implode(',', $plans->all())],
            // true approves a sign-up; false suspends the organization (#218).
            'approved' => ['sometimes', 'boolean'],
        ]);
        $tenant = $this->find($tenantPublicId);

        if (isset($validated['name']) || isset($validated['slug'])) {
            $tenant = $this->organizations->update($actor, $tenant, $validated['name'] ?? null, $validated['slug'] ?? null);
        }
        $effects = null;
        if (isset($validated['plan'])) {
            $effects = $planChanges->change($tenant, $validated['plan'], $actor, 'platform_admin');
        }
        if (array_key_exists('approved', $validated)) {
            $validated['approved'] ? $signup->approve($actor, $tenant) : $signup->suspend($actor, $tenant);
        }

        $tenant->refresh();

        return response()->json(['data' => [
            'id' => $tenant->public_id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'plan' => $tenant->plan,
            'approved' => $tenant->isApproved(),
        ], 'meta' => ['plan_effects' => $effects]]);
    }

    public function storeMember(Request $request, string $tenantPublicId): JsonResponse
    {
        $actor = $this->platformAdmin($request);
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'role' => ['required', 'in:owner,admin,viewer'],
        ]);

        $result = $this->members->add($actor, $this->find($tenantPublicId), $validated['email'], $validated['name'] ?? null, TenantRole::from($validated['role']));

        return response()->json([
            'data' => PlatformPresenter::member($result['membership']),
            'temporary_password' => $result['temporary_password'],
        ], 201);
    }

    public function updateMember(Request $request, string $tenantPublicId, string $membershipId): JsonResponse
    {
        $actor = $this->platformAdmin($request);
        $validated = $request->validate(['role' => ['required', 'in:owner,admin,viewer']]);

        $membership = $this->members->changeRole($actor, $this->find($tenantPublicId), $membershipId, TenantRole::from($validated['role']));

        return response()->json(['data' => PlatformPresenter::member($membership)]);
    }

    public function destroyMember(Request $request, string $tenantPublicId, string $membershipId): JsonResponse
    {
        $this->members->remove($this->platformAdmin($request), $this->find($tenantPublicId), $membershipId);

        return response()->json(null, 204);
    }

    public function transferOwnership(Request $request, string $tenantPublicId): JsonResponse
    {
        $actor = $this->platformAdmin($request);
        $validated = $request->validate(['membership_id' => ['required', 'string']]);

        $membership = $this->organizations->transferOwnership($actor, $this->find($tenantPublicId), $validated['membership_id']);

        return response()->json(['data' => PlatformPresenter::member($membership->load('user'))]);
    }

    private function find(string $publicId): Tenant
    {
        return Tenant::query()->where('public_id', $publicId)->firstOrFail();
    }
}
