<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\ApiKeys\ApiKeyService;
use App\Application\Mcp\McpContext;
use App\Application\Mcp\McpServer;
use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * MCP endpoint (#184): Streamable HTTP transport, stateless, JSON responses only.
 *
 * Authenticates environment API keys (`Authorization: Bearer sc_…`) itself, so
 * every failure, including auth, answers with a JSON-RPC body. Sessions and
 * GrandpaSSOn tokens are not accepted here.
 */
class McpController extends Controller
{
    private const UNAUTHORIZED = -32001;

    private const FORBIDDEN = -32003;

    public function __construct(
        private readonly ApiKeyService $apiKeys,
        private readonly McpServer $server,
    ) {}

    public function handle(Request $request): JsonResponse|Response
    {
        if (! $this->originAllowed($request)) {
            return $this->failure(403, self::FORBIDDEN, 'Origin not allowed.');
        }

        $context = $this->authenticate($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $message = json_decode($request->getContent(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json(McpServer::error(null, McpServer::PARSE_ERROR, 'Parse error.'), 400);
        }
        if (! is_array($message) || array_is_list($message)) {
            // Batches were removed from MCP in 2025-06-18; scalars are not messages.
            return response()->json(McpServer::error(null, McpServer::INVALID_REQUEST, 'Send one JSON-RPC message per request; batches are not supported.'), 400);
        }

        $response = $this->server->handle($message, $context);
        if ($response === null) {
            return response('', 202);
        }

        return response()->json($response, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** No server-to-client stream and no session to delete. */
    public function methodNotAllowed(): JsonResponse
    {
        return response()->json(McpServer::error(null, McpServer::INVALID_REQUEST, 'Method not allowed: use POST.'), 405, ['Allow' => 'POST']);
    }

    private function authenticate(Request $request): McpContext|JsonResponse
    {
        $token = $request->bearerToken();
        $apiKey = is_string($token) && str_starts_with($token, 'sc_') ? $this->apiKeys->authenticate($token) : null;
        if ($apiKey === null) {
            return $this->failure(401, self::UNAUTHORIZED, 'Send a StatusConnect API key as "Authorization: Bearer sc_...".', ['WWW-Authenticate' => 'Bearer realm="statusconnect"']);
        }

        $tenant = Tenant::query()->find($apiKey->tenant_id);
        $environment = $apiKey->environment_id === null ? null : Environment::query()
            ->where('id', $apiKey->environment_id)
            ->where('tenant_id', $apiKey->tenant_id)
            ->first();
        if ($tenant === null || $environment === null) {
            return $this->failure(403, self::FORBIDDEN, 'This API key is not bound to an environment. Create the key under an environment.');
        }
        if (! $tenant->isApproved()) {
            return $this->failure(403, self::FORBIDDEN, __('messages.tenant_pending_approval'));
        }

        return new McpContext($apiKey, $tenant, $environment);
    }

    /**
     * DNS rebinding guard from the MCP transport spec: a browser request from
     * another site carries its Origin. Clients outside a browser send none.
     */
    private function originAllowed(Request $request): bool
    {
        $origin = $request->headers->get('Origin');
        if ($origin === null || $origin === '') {
            return true;
        }

        $host = parse_url($origin, PHP_URL_HOST);
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && is_string($appHost) && strcasecmp($host, $appHost) === 0;
    }

    /** @param  array<string, string>  $headers */
    private function failure(int $status, int $code, string $message, array $headers = []): JsonResponse
    {
        return response()->json(McpServer::error(null, $code, $message), $status, $headers);
    }
}
