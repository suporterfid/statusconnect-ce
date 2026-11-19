<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Tenancy\PlatformAdministrationService;
use App\Application\Tenancy\PlatformUserAdministration;
use App\Domain\Shared\TenantRole;
use App\Http\Controllers\Concerns\AuthorizesPlatformAdmin;
use App\Http\Controllers\Controller;
use App\Http\Presenters\PlatformPresenter;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Platform administration of users (#248). Platform admins only.
 */
class PlatformUserController extends Controller
{
    use AuthorizesPlatformAdmin;

    public function __construct(private readonly PlatformUserAdministration $users)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->platformAdmin($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'tenant_id' => ['nullable', 'string', 'required_with:role'],
            'role' => ['nullable', 'in:owner,admin,viewer'],
        ]);

        $tenant = null;
        if (! empty($validated['tenant_id'])) {
            $tenant = Tenant::query()->where('public_id', $validated['tenant_id'])->first()
                ?? throw ValidationException::withMessages(['tenant_id' => [__('validation.exists', ['attribute' => 'tenant_id'])]]);
        }

        $user = $this->users->create(
            $actor,
            $validated['name'],
            $validated['email'],
            $tenant,
            isset($validated['role']) ? TenantRole::from($validated['role']) : null,
        );

        return response()->json(['data' => PlatformPresenter::userDetail($user->fresh())], 201);
    }

    public function show(Request $request, string $userId): JsonResponse
    {
        $this->platformAdmin($request);

        return response()->json(['data' => PlatformPresenter::userDetail($this->find($userId))]);
    }

    /** Name, email and platform admin access; any subset. */
    public function update(Request $request, string $userId, PlatformAdministrationService $platform): JsonResponse
    {
        $actor = $this->platformAdmin($request);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', 'regex:/\S/'],
            'email' => ['sometimes', 'email', 'max:255'],
            'is_platform_admin' => ['sometimes', 'boolean'],
        ]);
        if ($validated === []) {
            throw ValidationException::withMessages(['name' => [__('validation.required', ['attribute' => 'name'])]]);
        }

        $user = $this->find($userId);
        if (isset($validated['name']) || isset($validated['email'])) {
            $user = $this->users->update($actor, $user, $validated['name'] ?? null, $validated['email'] ?? null);
        }
        if (array_key_exists('is_platform_admin', $validated)) {
            $user = $platform->setPlatformAdmin($actor, $user, (bool) $validated['is_platform_admin']);
        }

        return response()->json(['data' => PlatformPresenter::userDetail($user->fresh())]);
    }

    public function block(Request $request, string $userId): JsonResponse
    {
        $user = $this->users->block($this->platformAdmin($request), $this->find($userId));

        return response()->json(['data' => PlatformPresenter::userDetail($user)]);
    }

    public function unblock(Request $request, string $userId): JsonResponse
    {
        $user = $this->users->unblock($this->platformAdmin($request), $this->find($userId));

        return response()->json(['data' => PlatformPresenter::userDetail($user)]);
    }

    public function endSessions(Request $request, string $userId): JsonResponse
    {
        $ended = $this->users->endSessions($this->platformAdmin($request), $this->find($userId));

        return response()->json(['data' => $ended]);
    }

    private function find(string $publicId): User
    {
        return User::query()->where('public_id', $publicId)->firstOrFail();
    }
}
