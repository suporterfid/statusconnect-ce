<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Tenancy\MembershipService;
use App\Domain\Shared\TenantRole;
use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\TenantMembership;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Organization members. Session users only: an API key must never be able to
 * grant itself (or anyone) access to an organization.
 */
class MemberController extends Controller
{
    public function __construct(private readonly MembershipService $members)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $tenant = $this->tenant($request);

        return response()->json([
            'data' => $this->members->list($actor, $tenant)->map(fn (TenantMembership $m) => $this->present($m))->values(),
            'meta' => ['actor_role' => $actor->isPlatformAdmin() ? 'platform_admin' : $this->members->actorRole($actor, $tenant)?->value],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'role' => ['required', 'in:owner,admin,viewer'],
        ]);

        $result = $this->members->add(
            $this->actor($request),
            $this->tenant($request),
            $validated['email'],
            $validated['name'] ?? null,
            TenantRole::from($validated['role']),
        );

        return response()->json([
            'data' => $this->present($result['membership']),
            'temporary_password' => $result['temporary_password'],
        ], 201);
    }

    public function update(Request $request, string $tenantId, string $membershipId): JsonResponse
    {
        $validated = $request->validate(['role' => ['required', 'in:owner,admin,viewer']]);

        $membership = $this->members->changeRole($this->actor($request), $this->tenant($request), $membershipId, TenantRole::from($validated['role']));

        return response()->json(['data' => $this->present($membership)]);
    }

    public function destroy(Request $request, string $tenantId, string $membershipId): JsonResponse
    {
        $this->members->remove($this->actor($request), $this->tenant($request), $membershipId);

        return response()->json(null, 204);
    }

    private function present(TenantMembership $m): array
    {
        return [
            'id' => $m->public_id,
            'role' => $m->role,
            'user' => ['id' => $m->user->public_id, 'name' => $m->user->name, 'email' => $m->user->email],
            'created_at' => \App\Http\Support\ApiDate::format($m->created_at),
        ];
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function tenant(Request $request): Tenant
    {
        return $request->attributes->get('tenant');
    }
}
