<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\ApiKeys\ApiKeyService;
use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\ApiKey;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApiKeyController extends Controller
{
    public function __construct(
        private readonly ApiKeyService $apiKeyService,
    ) {
    }

    public function index(Request $request, string $tenantId, string $environmentId): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        /** @var Environment $environment */
        $environment = $request->attributes->get('environment');

        $keys = ApiKey::query()
            ->where('tenant_id', $tenant->id)
            ->where('environment_id', $environment->id)
            ->whereNull('revoked_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $keys->map(fn (ApiKey $key) => $this->toArray($key)),
        ]);
    }

    public function store(Request $request, string $tenantId, string $environmentId): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        /** @var Environment $environment */
        $environment = $request->attributes->get('environment');

        $actor = $request->user();
        abort_unless($actor instanceof User, 403, 'API keys must be managed by a signed-in user, not another API key.');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'permissions' => 'required|array|min:1',
            'permissions.*' => [Rule::in(ApiKeyService::ALLOWED_PERMISSIONS)],
            'expires_at' => 'nullable|date',
        ]);

        $result = $this->apiKeyService->create(
            tenant: $tenant,
            actor: $actor,
            name: $validated['name'],
            permissions: $validated['permissions'],
            environment: $environment,
            expiresAt: isset($validated['expires_at']) ? new \DateTimeImmutable($validated['expires_at']) : null,
        );

        return response()->json([
            'data' => [
                ...$this->toArray($result['api_key']),
                // Only ever shown once — the hash is the only thing persisted.
                'plaintext' => $result['plaintext'],
            ],
        ], 201);
    }

    public function destroy(Request $request, string $tenantId, string $environmentId, string $apiKeyId): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        /** @var Environment $environment */
        $environment = $request->attributes->get('environment');

        $apiKey = ApiKey::query()
            ->where('tenant_id', $tenant->id)
            ->where('environment_id', $environment->id)
            ->where('public_id', $apiKeyId)
            ->firstOrFail();

        $this->apiKeyService->revoke($apiKey);

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(ApiKey $key): array
    {
        return [
            'id' => $key->public_id,
            'name' => $key->name,
            'prefix' => $key->key_prefix,
            'permissions' => $key->permissions,
            'created_at' => \App\Http\Support\ApiDate::format($key->created_at),
            'last_used_at' => \App\Http\Support\ApiDate::format($key->last_used_at),
            'expires_at' => \App\Http\Support\ApiDate::format($key->expires_at),
        ];
    }
}
