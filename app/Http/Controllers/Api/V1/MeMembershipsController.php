<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lists the tenants (and their environments) the authenticated user can act
 * within, so the operator SPA can resolve a real tenant/environment context
 * after login instead of falling back to placeholder IDs.
 */
class MeMembershipsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $tenants = $user->isPlatformAdmin()
            ? Tenant::query()->whereNull('archived_at')->get()
            : Tenant::query()
                ->whereNull('archived_at')
                ->whereIn('id', $user->memberships()->pluck('tenant_id'))
                ->get();

        $tenantIds = $tenants->pluck('id');
        $environmentsByTenant = Environment::query()
            ->whereIn('tenant_id', $tenantIds)
            ->whereNull('archived_at')
            ->get()
            ->groupBy('tenant_id');

        $roles = $user->memberships()->pluck('role', 'tenant_id');

        $data = $tenants->map(fn (Tenant $tenant) => [
            'tenant' => [
                'id' => $tenant->public_id,
                'name' => $tenant->name,
            ],
            'role' => $user->isPlatformAdmin() ? 'platform_admin' : $roles->get($tenant->id),
            'environments' => $environmentsByTenant->get($tenant->id, collect())
                ->map(fn (Environment $environment) => [
                    'id' => $environment->public_id,
                    'name' => $environment->name,
                ])->values(),
        ])->values();

        return response()->json(['data' => $data]);
    }
}
