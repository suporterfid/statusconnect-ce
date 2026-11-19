<?php

namespace App\Http\Controllers\Public;

use App\Application\Monitoring\Services\HeartbeatPingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PingController extends Controller
{
    public function __construct(
        private readonly HeartbeatPingService $pingService,
    ) {
    }

    public function ping(Request $request, string $token): Response|JsonResponse
    {
        $result = $this->pingService->recordPing($token, $request->ip() ?? '127.0.0.1');

        if ($result === false) {
            return response()->json([
                'message' => 'Too many requests.',
            ], 429);
        }

        if ($result === null) {
            return response()->json([
                'message' => 'Not Found',
            ], 404);
        }

        return response()->noContent();
    }
}
