<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Monitoring\MonitorService;
use App\Application\Monitoring\MonitorCommands;
use App\Http\Controllers\Concerns\EnsuresWriteRole;
use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Application\Secrets\ResponseSecretMask;
use App\Http\Resources\CheckResultResource;
use App\Http\Resources\MonitorResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MonitorController extends Controller
{
    use EnsuresWriteRole;

    public function __construct(
        private readonly MonitorService $monitorService,
        private readonly MonitorCommands $monitorCommands,
    ) {
    }

    public function index(Request $request, string $tenantId, string $environmentId): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        /** @var Environment $environment */
        $environment = $request->attributes->get('environment');

        $monitors = $this->monitorService->listMonitors($tenant, $environment);

        return response()->json([
            'data' => MonitorResource::collection($monitors),
        ]);
    }

    public function store(Request $request, string $tenantId, string $environmentId): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        /** @var Environment $environment */
        $environment = $request->attributes->get('environment');

        $agentKind = $request->input('kind') === 'agent';
        if ($agentKind) {
            abort_unless(config('agent.enabled'), 422, __('messages.agent_ingest_disabled'));
            // The response carries the ingestion token, so only owners and admins may create one.
            $this->ensureCanWrite($request);
        }

        $rules = MonitorCommands::rules($this->allowedKinds());
        if (! $agentKind) {
            $rules['target'] = 'required|string|max:1024';
        }
        $validated = $request->validate($rules);

        // Plan limits (#119) and the egress policy, shared with the MCP tools (#185).
        $monitor = $this->monitorCommands->create($tenant, $environment, $validated);

        if ($monitor->kind === \App\Domain\Monitoring\MonitorKind::AGENT) {
            [$monitor, $token] = $this->monitorService->rotateAgentToken($tenant, $environment, $monitor->public_id);

            return response()->json([
                'data' => new MonitorResource($monitor),
                'meta' => ['agent_token' => $token, 'ingest_url' => url('/ingest/'.$token)],
            ], 201);
        }

        return response()->json([
            'data' => new MonitorResource($monitor),
        ], 201);
    }

    public function show(Request $request, string $tenantId, string $environmentId, string $monitorId): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        /** @var Environment $environment */
        $environment = $request->attributes->get('environment');

        $monitor = $this->monitorService->getMonitor($tenant, $environment, $monitorId);

        return response()->json([
            'data' => new MonitorResource($monitor),
        ]);
    }

    public function checkResults(Request $request, string $tenantId, string $environmentId, string $monitorId): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        /** @var Environment $environment */
        $environment = $request->attributes->get('environment');

        // Confirms the monitor belongs to this tenant/environment (404s otherwise)
        // before scoping the results query to it.
        $monitor = $this->monitorService->getMonitor($tenant, $environment, $monitorId);

        $limit = (int) $request->query('limit', 50);
        $limit = max(1, min($limit, 200));

        $results = \App\Infrastructure\Persistence\Eloquent\CheckResult::query()
            ->where('monitor_id', $monitor->id)
            ->orderByDesc('checked_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => CheckResultResource::collection($results),
        ]);
    }

    public function update(Request $request, string $tenantId, string $environmentId, string $monitorId): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        /** @var Environment $environment */
        $environment = $request->attributes->get('environment');

        $current = $this->monitorService->getMonitor($tenant, $environment, $monitorId);
        $isAgent = $current->kind === \App\Domain\Monitoring\MonitorKind::AGENT;
        if ($request->has('kind') && ($request->input('kind') === 'agent') !== $isAgent) {
            throw \Illuminate\Validation\ValidationException::withMessages(['kind' => [__('messages.agent_kind_immutable')]]);
        }

        $validated = $request->validate(MonitorCommands::rules($this->allowedKinds(), forUpdate: true));

        // A client that read the monitor and sends its masked headers back keeps the stored values (#155).
        if (isset($validated['request_headers_json']) && is_array($validated['request_headers_json'])) {
            $validated['request_headers_json'] = ResponseSecretMask::restore(
                $validated['request_headers_json'],
                $current->request_headers_json,
                ResponseSecretMask::headers(...),
            );
        }

        // Plan limits (#119), checked only for the fields this request changes.
        $apiKey = $request->attributes->get('api_key');
        $user = $request->user();
        $monitor = $this->monitorCommands->update(
            $tenant, $environment, $current, $validated,
            $user instanceof \App\Infrastructure\Persistence\Eloquent\User ? $user : null,
            ['source' => 'api'] + ($apiKey !== null ? ['api_key' => $apiKey->public_id] : []),
        );

        return response()->json([
            'data' => new MonitorResource($monitor),
        ]);
    }

    public function destroy(Request $request, string $tenantId, string $environmentId, string $monitorId): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        /** @var Environment $environment */
        $environment = $request->attributes->get('environment');

        $this->monitorService->deleteMonitor($tenant, $environment, $monitorId);

        return response()->json(null, 204);
    }

    public function rotateHeartbeatToken(Request $request, string $tenantId, string $environmentId, string $monitorId): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        /** @var Environment $environment */
        $environment = $request->attributes->get('environment');

        $monitor = $this->monitorService->rotateHeartbeatToken($tenant, $environment, $monitorId);

        return response()->json([
            'data' => new MonitorResource($monitor),
        ]);
    }

    public function rotateAgentToken(Request $request, string $tenantId, string $environmentId, string $monitorId): JsonResponse
    {
        abort_unless(config('agent.enabled'), 404);
        $this->ensureCanWrite($request);

        [$monitor, $token] = $this->monitorService->rotateAgentToken(
            $request->attributes->get('tenant'),
            $request->attributes->get('environment'),
            $monitorId,
        );

        return response()->json([
            'data' => new MonitorResource($monitor),
            'meta' => [
                'agent_token' => $token,
                'ingest_url' => url('/ingest/'.$token),
            ],
        ]);
    }

    /** @return list<string> */
    private function allowedKinds(): array
    {
        return config('agent.enabled') ? ['http', 'tcp', 'heartbeat', 'mqtt', 'agent'] : ['http', 'tcp', 'heartbeat', 'mqtt'];
    }
}
