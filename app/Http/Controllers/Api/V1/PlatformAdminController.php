<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Tenancy\PlatformAdministrationService;
use App\Http\Controllers\Controller;
use App\Http\Presenters\PlatformPresenter;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform admin console: every user and organization. Platform admins only.
 * One user or organization is managed in PlatformUserController and PlatformTenantController (#248).
 */
class PlatformAdminController extends Controller
{
    public function __construct(private readonly PlatformAdministrationService $platform)
    {
    }

    public function users(Request $request): JsonResponse
    {
        $this->actor($request);

        return response()->json([
            'data' => $this->platform->users($request->query('q'))->map(fn (User $u) => PlatformPresenter::user($u))->values(),
        ]);
    }

    public function resetPassword(Request $request, string $userId): JsonResponse
    {
        $actor = $this->actor($request);

        return response()->json([
            'data' => ['temporary_password' => $this->platform->resetPassword($actor, $this->findUser($userId))],
        ]);
    }

    public function tenants(Request $request): JsonResponse
    {
        $this->actor($request);

        return response()->json([
            'data' => $this->platform->tenants(),
            // Plans a platform admin can assign (#250): the edition's plan table.
            'meta' => ['plans' => app(\App\Application\Plans\PlanCatalog::class)->all()],
        ]);
    }

    public function storeTenant(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'owner_email' => ['required', 'email', 'max:255'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'locale' => ['sometimes', 'in:en,pt_BR,pt-BR'],
            'timezone' => ['sometimes', 'timezone'],
        ]);

        $result = $this->platform->createTenant(
            $actor,
            $validated['name'],
            $validated['slug'],
            $validated['owner_email'],
            $validated['owner_name'] ?? null,
            $validated['locale'] ?? 'pt_BR',
            $validated['timezone'] ?? 'America/Sao_Paulo',
        );

        return response()->json([
            'data' => ['id' => $result['tenant']->public_id, 'name' => $result['tenant']->name, 'slug' => $result['tenant']->slug],
            'temporary_password' => $result['temporary_password'],
        ], 201);
    }

    /** Checker load against measured capacity, last tick and saturation (#214). */
    public function checker(Request $request, \App\Application\Scheduling\CheckerLoad $load): JsonResponse
    {
        $this->actor($request);
        $heartbeat = \App\Infrastructure\Persistence\Eloquent\SystemHeartbeat::query()->where('name', 'checker')->first();
        $meta = (array) ($heartbeat?->meta_json ?? []);

        return response()->json(['data' => $load->summary() + [
            'concurrency' => \App\Application\Scheduling\SchedulerLimits::fromConfig()->concurrency,
            'last_tick' => $heartbeat === null ? null : [
                'at' => $heartbeat->last_seen_at?->toIso8601String(),
                'claimed' => (int) ($meta['claimed'] ?? 0),
                'executed' => (int) ($meta['executed'] ?? 0),
                'deferred' => (int) ($meta['deferred'] ?? 0),
                'duration_ms' => (int) ($meta['duration_ms'] ?? 0),
            ],
            'saturation' => \App\Application\Scheduling\CheckerSaturation::status() + [
                'duration_threshold_seconds' => \App\Application\Scheduling\CheckerSaturation::durationThresholdSeconds(),
                'ticks_threshold' => \App\Application\Scheduling\CheckerSaturation::ticksThreshold(),
            ],
        ]]);
    }

    private function findUser(string $publicId): User
    {
        return User::query()->where('public_id', $publicId)->firstOrFail();
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isPlatformAdmin(), 403);

        return $user;
    }
}
