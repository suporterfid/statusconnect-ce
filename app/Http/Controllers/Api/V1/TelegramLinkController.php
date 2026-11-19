<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Notifications\Telegram\TelegramLinkService;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationChannelResource;
use App\Http\Support\ApiDate;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Links that connect a Telegram chat to the environment (#190).
 *
 * Creating one is a channel write: owners and admins, or an API key with
 * secrets:manage (EnforceTenantPermission). The code in the URLs is shown
 * only in this response.
 */
class TelegramLinkController extends Controller
{
    public function __construct(private readonly TelegramLinkService $links)
    {
    }

    public function store(Request $request, string $tenantId, string $environmentId): JsonResponse
    {
        abort_unless($this->links->available(), 422, __('messages.telegram_not_configured'));

        $user = $request->user();
        ['link' => $link, 'code' => $code] = $this->links->createLink(
            $request->attributes->get('tenant'),
            $request->attributes->get('environment'),
            $user instanceof User ? $user : null,
        );

        return response()->json([
            'data' => [
                'link_id' => $link->public_id,
                ...$this->links->urls($code),
                'expires_at' => ApiDate::format($link->expires_at),
            ],
        ], 201);
    }

    public function show(Request $request, string $tenantId, string $environmentId, string $linkId): JsonResponse
    {
        try {
            $link = $this->links->find($request->attributes->get('tenant'), $request->attributes->get('environment'), $linkId);
        } catch (ModelNotFoundException) {
            abort(404);
        }

        return response()->json([
            'data' => [
                'link_id' => $link->public_id,
                'status' => $link->status(),
                'expires_at' => ApiDate::format($link->expires_at),
                'channel' => $link->channel !== null ? new NotificationChannelResource($link->channel) : null,
            ],
        ]);
    }
}
