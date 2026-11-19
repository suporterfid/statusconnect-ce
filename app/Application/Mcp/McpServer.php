<?php

namespace App\Application\Mcp;

use App\Application\Mcp\Tools\CreateMaintenanceWindowTool;
use App\Application\Mcp\Tools\CreateMonitorTool;
use App\Application\Mcp\Tools\GetMonitorTool;
use App\Application\Mcp\Tools\GetStatusPageTool;
use App\Application\Mcp\Tools\ListIncidentsTool;
use App\Application\Mcp\Tools\ListMonitorsTool;
use App\Application\Mcp\Tools\McpTool;
use App\Application\Mcp\Tools\PauseMonitorTool;
use App\Application\Mcp\Tools\ResumeMonitorTool;
use App\Http\Middleware\EnforceTenantPermission;
use App\Infrastructure\Persistence\Eloquent\ApiKey;
use Illuminate\Validation\ValidationException;

/**
 * Model Context Protocol server (#184), stateless.
 *
 * Handles one JSON-RPC message per HTTP request and keeps no session: it runs
 * on shared hosting with no long-lived process. Supports `initialize`, `ping`,
 * `tools/list`, `tools/call` and ignores notifications.
 */
final class McpServer
{
    public const VERSION = '1.0.0';

    /** Newest first; the first one is the fallback. */
    public const PROTOCOL_VERSIONS = ['2025-06-18', '2025-11-25', '2025-03-26'];

    public const PARSE_ERROR = -32700;

    public const INVALID_REQUEST = -32600;

    public const METHOD_NOT_FOUND = -32601;

    public const INVALID_PARAMS = -32602;

    /** @var array<string, McpTool> */
    private array $tools = [];

    public function __construct(
        ListMonitorsTool $listMonitors,
        GetMonitorTool $getMonitor,
        ListIncidentsTool $listIncidents,
        GetStatusPageTool $getStatusPage,
        PauseMonitorTool $pauseMonitor,
        ResumeMonitorTool $resumeMonitor,
        CreateMonitorTool $createMonitor,
        CreateMaintenanceWindowTool $createMaintenanceWindow,
    ) {
        foreach ([$listMonitors, $getMonitor, $listIncidents, $getStatusPage, $pauseMonitor, $resumeMonitor, $createMonitor, $createMaintenanceWindow] as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    /**
     * @param  array<string, mixed>  $message  One decoded JSON-RPC message.
     * @return array<string, mixed>|null The response, or null for a notification.
     */
    public function handle(array $message, McpContext $context): ?array
    {
        $method = $message['method'] ?? null;
        $hasId = array_key_exists('id', $message);
        $id = $message['id'] ?? null;

        if (($message['jsonrpc'] ?? null) !== '2.0' || ! is_string($method) || ($hasId && ! is_string($id) && ! is_int($id))) {
            return self::error($hasId && (is_string($id) || is_int($id)) ? $id : null, self::INVALID_REQUEST, 'Invalid JSON-RPC request.');
        }

        // Notifications (no id) get no response; this server keeps no state to update.
        if (! $hasId) {
            return null;
        }

        $params = $message['params'] ?? [];
        if (! is_array($params)) {
            return self::error($id, self::INVALID_PARAMS, 'params must be an object.');
        }

        return match ($method) {
            'initialize' => self::result($id, $this->initialize($params)),
            'ping' => self::result($id, new \stdClass),
            'tools/list' => self::result($id, ['tools' => $this->listTools($context->apiKey)]),
            'tools/call' => $this->callTool($id, $params, $context),
            default => self::error($id, self::METHOD_NOT_FOUND, 'Method not found: '.$method),
        };
    }

    /** @return array<string, mixed> */
    public static function error(string|int|null $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /** @return array<string, mixed> */
    private static function result(string|int $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @param  array<string, mixed>  $params */
    private function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;

        return [
            'protocolVersion' => in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'statusconnect', 'title' => 'StatusConnect', 'version' => self::VERSION],
            'instructions' => 'StatusConnect uptime monitoring for one environment. Start with list_monitors (state="down" shows what is failing), '
                .'then get_monitor for recent checks, list_incidents for open incidents, and get_status_page for what visitors see. '
                .'Keys with write permissions also get pause_monitor, resume_monitor, create_monitor and create_maintenance_window.',
        ];
    }

    /** @return list<array<string, mixed>> The tools this key may call. */
    private function listTools(ApiKey $apiKey): array
    {
        $tools = array_filter($this->tools, fn (McpTool $tool): bool => self::allows($apiKey, $tool));

        return array_values(array_map(static fn (McpTool $tool): array => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'inputSchema' => $tool->inputSchema(),
            'annotations' => $tool->annotations(),
        ], $tools));
    }

    private static function allows(ApiKey $apiKey, McpTool $tool): bool
    {
        foreach ($tool->permissions() as $permission) {
            if (EnforceTenantPermission::keyAllows($apiKey, $permission)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $params */
    private function callTool(string|int $id, array $params, McpContext $context): array
    {
        $name = $params['name'] ?? null;
        $tool = is_string($name) ? ($this->tools[$name] ?? null) : null;
        if ($tool === null) {
            return self::error($id, self::INVALID_PARAMS, 'Unknown tool: '.(is_string($name) ? $name : '(missing)'));
        }

        $arguments = $params['arguments'] ?? [];
        if (! is_array($arguments)) {
            return self::error($id, self::INVALID_PARAMS, 'arguments must be an object.');
        }

        // Checked on every call: a tool hidden from tools/list can still be called by name.
        if (! self::allows($context->apiKey, $tool)) {
            return self::result($id, self::toolError('This API key lacks the permission for '.$tool->name().': '.implode(' or ', $tool->permissions()).'.'));
        }

        try {
            $data = $tool->call($arguments, $context);
        } catch (McpToolException $e) {
            return self::result($id, self::toolError($e->getMessage()));
        } catch (ValidationException $e) {
            // Plan limits, the egress policy and the API's validation rules.
            return self::result($id, self::toolError(implode(' ', $e->validator->errors()->all())));
        }

        return self::result($id, [
            'content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]],
            'structuredContent' => $data,
            'isError' => false,
        ]);
    }

    /** @return array<string, mixed> */
    private static function toolError(string $message): array
    {
        return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true];
    }
}
