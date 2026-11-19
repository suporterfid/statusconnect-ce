<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Legal\TermsAcceptanceService;
use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * POST /v1/me/terms-acceptance: the signed-in user accepts the current texts.
 */
final class TermsAcceptanceController extends Controller
{
    public function __invoke(Request $request, TermsAcceptanceService $terms): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $validated = $request->validate([
            'accept' => ['accepted'],
            'version' => ['required', 'string'],
        ]);
        // The page the user read must be the current one; texts may change while it is open.
        if ($validated['version'] !== $terms->present($user)['version']) {
            throw ValidationException::withMessages(['version' => [__('messages.terms_version_changed')]]);
        }

        $terms->accept($user, 'prompt', $request);

        return response()->json(['data' => $terms->present($user)]);
    }
}
