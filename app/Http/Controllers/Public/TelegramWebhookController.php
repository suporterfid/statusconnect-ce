<?php

namespace App\Http\Controllers\Public;

use App\Application\Notifications\Telegram\TelegramLinkService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives updates from Telegram (#190). Authenticated by the webhook secret,
 * which must match both the path and the X-Telegram-Bot-Api-Secret-Token
 * header that Telegram sends after `setWebhook(secret_token: …)`.
 */
class TelegramWebhookController extends Controller
{
    public function __construct(private readonly TelegramLinkService $links)
    {
    }

    public function handle(Request $request, string $secret): JsonResponse
    {
        $expected = (string) config('telegram.webhook_secret', '');
        $header = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        abort_if($expected === '' || ! hash_equals($expected, $secret) || ! hash_equals($expected, $header), 404);

        $update = $request->json()->all();
        if (is_array($update)) {
            $this->links->handleUpdate($update);
        }

        // Always 200 for an authenticated update, or Telegram sends it again.
        return response()->json(['ok' => true]);
    }
}
