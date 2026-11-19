<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Extensions\Features;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request, Features $features): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $user->loadMissing('preferences');

        return response()->json([
            'data' => new UserResource($user),
            'meta' => [
                // Enabled features (#250): the SPA hides what is off or not part of this edition.
                'features' => $features->all(),
            ],
        ]);
    }
}
