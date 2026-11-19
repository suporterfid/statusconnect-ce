<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Notifications\Services\NotificationChannelService;
use App\Application\Notifications\Services\NotificationTestService;
use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Application\Secrets\ResponseSecretMask;
use App\Http\Resources\NotificationChannelResource;
use App\Http\Resources\NotificationDeliveryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationChannelController extends Controller
{
    public function __construct(
        private readonly NotificationChannelService $channelService,
        private readonly NotificationTestService $testService,
    ) {
    }

    public function index(Request $request, string $tenantId, string $environmentId): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        /** @var Environment $environment */
        $environment = $request->attributes->get('environment');

        $channels = $this->channelService->listChannels($tenant, $environment);

        return response()->json([
            'data' => NotificationChannelResource::collection($channels),
        ]);
    }

    public function store(Request $request, string $tenantId, string $environmentId): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        /** @var Environment $environment */
        $environment = $request->attributes->get('environment');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:email,webhook,taskconnect',
            'config_json' => 'required|array',
            'enabled' => 'nullable|boolean',
        ]);

        $channel = $this->channelService->createChannel($tenant, $environment, $validated);

        return response()->json([
            'data' => new NotificationChannelResource($channel),
        ], 201);
    }

    public function show(Request $request, string $tenantId, string $environmentId, string $channelId): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        /** @var Environment $environment */
        $environment = $request->attributes->get('environment');

        $channel = $this->channelService->getChannel($tenant, $environment, $channelId);

        return response()->json([
            'data' => new NotificationChannelResource($channel),
        ]);
    }

    public function update(Request $request, string $tenantId, string $environmentId, string $channelId): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        /** @var Environment $environment */
        $environment = $request->attributes->get('environment');

        $current = $this->channelService->getChannel($tenant, $environment, $channelId);
        // Telegram (#189) and WhatsApp (#215) channels come only from linking.
        $isTelegram = in_array($current->type, ['telegram', 'whatsapp'], true);

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            // A telegram channel keeps its type; no other channel can become one (#189).
            'type' => $isTelegram ? 'nullable|string|in:'.$current->type : 'nullable|string|in:email,webhook,taskconnect',
            'config_json' => 'nullable|array',
            'enabled' => 'nullable|boolean',
        ]);

        // A telegram channel's chat comes only from linking: its config is not editable.
        if ($isTelegram && array_key_exists('config_json', $validated)) {
            if ($validated['config_json'] !== null && $validated['config_json'] != $current->config_json) {
                throw \Illuminate\Validation\ValidationException::withMessages(['config_json' => [__($current->type === 'whatsapp' ? 'messages.whatsapp_config_immutable' : 'messages.telegram_config_immutable')]]);
            }
            unset($validated['config_json']);
        }

        // A client that read the channel and sends its masked config back keeps the stored credentials (#155).
        if (isset($validated['config_json']) && is_array($validated['config_json'])) {
            $validated['config_json'] = ResponseSecretMask::restore(
                $validated['config_json'],
                $current->config_json,
                ResponseSecretMask::config(...),
            );
        }

        $channel = $this->channelService->updateChannel($tenant, $environment, $channelId, $validated);

        return response()->json([
            'data' => new NotificationChannelResource($channel),
        ]);
    }

    public function destroy(Request $request, string $tenantId, string $environmentId, string $channelId): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        /** @var Environment $environment */
        $environment = $request->attributes->get('environment');

        $this->channelService->deleteChannel($tenant, $environment, $channelId);

        return response()->json(null, 204);
    }

    public function test(Request $request, string $tenantId, string $environmentId, string $channelId): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        /** @var Environment $environment */
        $environment = $request->attributes->get('environment');

        $channel = $this->channelService->getChannel($tenant, $environment, $channelId);
        $delivery = $this->testService->sendTestNotification($channel);

        return response()->json([
            'data' => new NotificationDeliveryResource($delivery),
        ]);
    }
}
