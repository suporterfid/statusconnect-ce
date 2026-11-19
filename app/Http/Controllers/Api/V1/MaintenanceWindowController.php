<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Maintenance\Services\MaintenanceWindowService;
use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Http\Resources\MaintenanceWindowResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaintenanceWindowController extends Controller
{
    public function __construct(
        private readonly MaintenanceWindowService $windowService,
    ) {
    }

    public function index(Request $request, string $tenantId, string $environmentId): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        /** @var Environment $environment */
        $environment = $request->attributes->get('environment');

        $windows = $this->windowService->list($tenant, $environment);

        return response()->json([
            'data' => MaintenanceWindowResource::collection($windows),
        ]);
    }

    public function store(Request $request, string $tenantId, string $environmentId): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        /** @var Environment $environment */
        $environment = $request->attributes->get('environment');

        $validated = $request->validate(MaintenanceWindowService::rules());

        $window = $this->windowService->create($tenant, $environment, $validated);

        return response()->json([
            'data' => new MaintenanceWindowResource($window),
        ], 201);
    }

    public function show(Request $request, string $tenantId, string $environmentId, string $windowId): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        /** @var Environment $environment */
        $environment = $request->attributes->get('environment');

        $window = $this->windowService->get($tenant, $environment, $windowId);

        return response()->json([
            'data' => new MaintenanceWindowResource($window),
        ]);
    }

    public function update(Request $request, string $tenantId, string $environmentId, string $windowId): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        /** @var Environment $environment */
        $environment = $request->attributes->get('environment');

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'starts_at' => 'sometimes|date',
            'ends_at' => 'sometimes|date',
            'suppress_notifications' => 'boolean',
            'recurring_weekly' => 'boolean',
            'monitor_ids' => 'nullable|array',
            'monitor_ids.*' => 'string',
        ]);

        // #156: compare with the stored start or end when only one of them is sent.
        if (isset($validated['starts_at']) || isset($validated['ends_at'])) {
            $current = $this->windowService->get($tenant, $environment, $windowId);
            $startsAt = \Illuminate\Support\Carbon::parse($validated['starts_at'] ?? $current->starts_at);
            $endsAt = \Illuminate\Support\Carbon::parse($validated['ends_at'] ?? $current->ends_at);
            if (! $endsAt->greaterThan($startsAt)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    isset($validated['ends_at']) ? 'ends_at' : 'starts_at' => [__('validation.after', ['attribute' => 'ends_at', 'date' => 'starts_at'])],
                ]);
            }
        }

        $window = $this->windowService->update($tenant, $environment, $windowId, $validated);

        return response()->json([
            'data' => new MaintenanceWindowResource($window),
        ]);
    }

    public function destroy(Request $request, string $tenantId, string $environmentId, string $windowId): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        /** @var Environment $environment */
        $environment = $request->attributes->get('environment');

        $this->windowService->delete($tenant, $environment, $windowId);

        return response()->json(null, 204);
    }
}
