<?php

namespace Tests\Feature;

use App\Application\ApiKeys\ApiKeyService;
use App\Application\StatusPages\StatusPageService;
use App\Application\Tenancy\AccountProvisioningService;
use App\Application\Tenancy\ProvisionedAccount;
use App\Infrastructure\Persistence\Eloquent\CheckResult;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Incident;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** MCP server (#184): protocol, auth, tenant isolation and the read tools. */
class McpServerTest extends TestCase
{
    use RefreshDatabase;

    private ProvisionedAccount $account;

    private Monitor $down;

    private Monitor $up;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = app(AccountProvisioningService::class)->provision([
            'email' => 'owner@acme.test', 'name' => 'Owner', 'org' => 'Acme', 'slug' => 'acme',
        ]);
        $this->down = $this->monitor($this->account->environment, 'Checkout API', 'down');
        $this->up = $this->monitor($this->account->environment, 'Website', 'up');
    }

    public function test_initialize_describes_the_server(): void
    {
        $this->rpc($this->key(['monitors:read']), 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass, 'clientInfo' => ['name' => 'test', 'version' => '1']])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeaderMissing('Mcp-Session-Id')
            ->assertJsonPath('result.protocolVersion', '2025-06-18')
            ->assertJsonPath('result.serverInfo.name', 'statusconnect')
            ->assertJsonPath('result.capabilities.tools.listChanged', false);
    }

    public function test_initialize_falls_back_to_a_supported_protocol_version(): void
    {
        $this->rpc($this->key(['monitors:read']), 'initialize', ['protocolVersion' => '1999-01-01'])
            ->assertJsonPath('result.protocolVersion', '2025-06-18');
    }

    public function test_tools_list_returns_the_four_read_tools(): void
    {
        $tools = $this->rpc($this->key(['monitors:read']), 'tools/list')->assertOk()->json('result.tools');

        $this->assertSame(['list_monitors', 'get_monitor', 'list_incidents', 'get_status_page'], array_column($tools, 'name'));
        foreach ($tools as $tool) {
            $this->assertSame('object', $tool['inputSchema']['type']);
            $this->assertTrue($tool['annotations']['readOnlyHint']);
        }
    }

    public function test_list_monitors_returns_only_the_key_environment(): void
    {
        $otherEnvironment = Environment::query()->create([
            'tenant_id' => $this->account->tenant->id, 'name' => 'Staging', 'slug' => 'staging',
        ]);
        $this->monitor($otherEnvironment, 'Staging API', 'down');
        $otherTenant = app(AccountProvisioningService::class)->provision([
            'email' => 'other@globex.test', 'name' => 'Other', 'org' => 'Globex', 'slug' => 'globex',
        ]);
        $this->monitor($otherTenant->environment, 'Globex API', 'down');

        $result = $this->callTool($this->key(['monitors:read']), 'list_monitors');

        $result->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.total', 2);
        $this->assertEqualsCanonicalizing(['Checkout API', 'Website'], array_column($result->json('result.structuredContent.monitors'), 'name'));
        $this->assertSame($result->json('result.structuredContent'), json_decode($result->json('result.content.0.text'), true));
    }

    public function test_list_monitors_filters_by_state_and_hides_secrets(): void
    {
        $this->down->forceFill(['heartbeat_token' => 'hb_secret_token', 'request_headers_json' => ['Authorization' => 'Bearer x']])->save();

        $result = $this->callTool($this->key(['monitors:read']), 'list_monitors', ['state' => 'down']);

        $result->assertJsonPath('result.structuredContent.total', 1)
            ->assertJsonPath('result.structuredContent.monitors.0.monitor_id', $this->down->public_id)
            ->assertJsonPath('result.structuredContent.monitors.0.state', 'down');
        $this->assertStringNotContainsString('hb_secret_token', $result->getContent());
        $this->assertStringNotContainsString('Bearer x', $result->getContent());
    }

    public function test_list_monitors_rejects_an_unknown_state(): void
    {
        $this->callTool($this->key(['monitors:read']), 'list_monitors', ['state' => 'sideways'])
            ->assertOk()
            ->assertJsonPath('result.isError', true);
    }

    public function test_get_monitor_returns_recent_checks(): void
    {
        foreach (range(1, 25) as $i) {
            CheckResult::query()->create([
                'tenant_id' => $this->down->tenant_id,
                'environment_id' => $this->down->environment_id,
                'monitor_id' => $this->down->id,
                'state' => 'down',
                'latency_ms' => 100 + $i,
                'status_code' => 503,
                'failure_reason' => 'http_status',
                'checked_at' => now()->subMinutes(30 - $i),
            ]);
        }

        $result = $this->callTool($this->key(['monitors:read']), 'get_monitor', ['monitor_id' => $this->down->public_id]);

        $result->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.monitor.name', 'Checkout API')
            ->assertJsonCount(20, 'result.structuredContent.recent_checks')
            ->assertJsonPath('result.structuredContent.recent_checks.0.latency_ms', 125)
            ->assertJsonPath('result.structuredContent.recent_checks.0.status_code', 503);
    }

    public function test_get_monitor_from_another_environment_reads_like_an_unknown_id(): void
    {
        $otherEnvironment = Environment::query()->create([
            'tenant_id' => $this->account->tenant->id, 'name' => 'Staging', 'slug' => 'staging',
        ]);
        $foreign = $this->monitor($otherEnvironment, 'Staging API', 'down');
        $key = $this->key(['monitors:read']);

        $foreignResult = $this->callTool($key, 'get_monitor', ['monitor_id' => $foreign->public_id]);
        $unknownResult = $this->callTool($key, 'get_monitor', ['monitor_id' => 'mon_does_not_exist']);

        $foreignResult->assertJsonPath('result.isError', true);
        $unknownResult->assertJsonPath('result.isError', true);
        $this->assertSame(
            str_replace($foreign->public_id, 'ID', $foreignResult->json('result.content.0.text')),
            str_replace('mon_does_not_exist', 'ID', $unknownResult->json('result.content.0.text')),
        );
        $this->assertStringNotContainsString('Staging API', $foreignResult->getContent());
    }

    public function test_list_incidents_defaults_to_open_ones(): void
    {
        $this->incident($this->down, null);
        $this->incident($this->up, now()->subHour());

        $this->callTool($this->key(['monitors:read']), 'list_incidents')
            ->assertJsonCount(1, 'result.structuredContent.incidents')
            ->assertJsonPath('result.structuredContent.incidents.0.status', 'open')
            ->assertJsonPath('result.structuredContent.incidents.0.monitor.name', 'Checkout API');

        $this->callTool($this->key(['monitors:read']), 'list_incidents', ['status' => 'all'])
            ->assertJsonCount(2, 'result.structuredContent.incidents');
    }

    public function test_get_status_page_reads_the_public_snapshot(): void
    {
        $component = app(StatusPageService::class)->createComponent($this->account->environment, [
            'name' => 'Payments', 'monitor_ids' => [$this->down->public_id],
        ]);
        Artisan::call('monitor:rollup');

        $result = $this->callTool($this->key(['monitors:read']), 'get_status_page');

        $result->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.title', 'Acme')
            ->assertJsonPath('result.structuredContent.components.0.name', $component['name']);
        $this->assertNotNull($result->json('result.structuredContent.overall_state'));
        $this->assertStringEndsWith('/status/acme', $result->json('result.structuredContent.public_url'));
    }

    public function test_write_and_operate_keys_can_read(): void
    {
        foreach (['monitors:write', 'monitors:operate', '*'] as $permission) {
            $this->callTool($this->key([$permission]), 'list_monitors')->assertJsonPath('result.isError', false);
        }
    }

    public function test_a_key_without_read_permission_gets_tool_errors(): void
    {
        $key = $this->key(['api_keys:manage']);

        foreach (['list_monitors', 'list_incidents', 'get_status_page'] as $tool) {
            $this->callTool($key, $tool)
                ->assertOk()
                ->assertJsonPath('result.isError', true)
                ->assertJsonMissingPath('result.structuredContent');
        }
        $this->callTool($key, 'get_monitor', ['monitor_id' => $this->down->public_id])
            ->assertJsonPath('result.isError', true);
    }

    public function test_unknown_tool_is_a_protocol_error(): void
    {
        $this->callTool($this->key(['monitors:read']), 'delete_everything')
            ->assertJsonPath('error.code', -32602);
    }

    public function test_requests_without_a_valid_key_get_401(): void
    {
        $service = app(ApiKeyService::class);
        $revoked = $service->create($this->account->tenant, $this->account->user, 'Revoked', ['monitors:read'], $this->account->environment);
        $service->revoke($revoked['api_key']);
        $expired = $service->create($this->account->tenant, $this->account->user, 'Expired', ['monitors:read'], $this->account->environment, now()->subMinute());

        $this->postJson('/v1/mcp', $this->message('tools/list'))
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate')
            ->assertJsonPath('error.code', -32001);
        foreach ([$revoked['plaintext'], $expired['plaintext'], 'sc_not_a_real_key_0000000000000000000000000000'] as $token) {
            $this->rpc($token, 'tools/list')->assertStatus(401);
        }
    }

    public function test_a_signed_in_session_alone_is_not_accepted(): void
    {
        $this->actingAs($this->account->user)
            ->postJson('/v1/mcp', $this->message('tools/list'))
            ->assertStatus(401);
    }

    public function test_a_key_without_an_environment_is_refused(): void
    {
        $key = app(ApiKeyService::class)->create($this->account->tenant, $this->account->user, 'Tenant-wide', ['*']);

        $this->rpc($key['plaintext'], 'tools/list')->assertStatus(403)->assertJsonPath('error.code', -32003);
    }

    public function test_a_tenant_awaiting_approval_is_refused(): void
    {
        $key = $this->key(['monitors:read']);
        $this->account->tenant->forceFill(['approved_at' => null])->save();

        $this->rpc($key, 'tools/list')->assertStatus(403);
    }

    public function test_protocol_edge_cases(): void
    {
        $key = $this->key(['monitors:read']);
        $headers = ['Authorization' => 'Bearer '.$key];

        $this->call('POST', '/v1/mcp', [], [], [], $this->server($headers), json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']))
            ->assertStatus(202)
            ->assertContent('');
        $this->rpc($key, 'resources/list')->assertJsonPath('error.code', -32601);
        $this->rpc($key, 'ping')->assertOk()->assertJsonPath('id', 1);
        $this->call('POST', '/v1/mcp', [], [], [], $this->server($headers), '{not json')
            ->assertStatus(400)
            ->assertJsonPath('error.code', -32700);
        $this->call('POST', '/v1/mcp', [], [], [], $this->server($headers), json_encode([$this->message('ping')]))
            ->assertStatus(400)
            ->assertJsonPath('error.code', -32600);
        $this->withHeaders($headers)->getJson('/v1/mcp')->assertStatus(405);
        $this->withHeaders($headers)->deleteJson('/v1/mcp')->assertStatus(405);
    }

    public function test_a_foreign_origin_is_refused(): void
    {
        $key = $this->key(['monitors:read']);

        $this->withHeaders(['Authorization' => 'Bearer '.$key, 'Origin' => 'https://evil.example'])
            ->postJson('/v1/mcp', $this->message('tools/list'))
            ->assertStatus(403);
        $this->withHeaders(['Authorization' => 'Bearer '.$key, 'Origin' => rtrim((string) config('app.url'), '/')])
            ->postJson('/v1/mcp', $this->message('tools/list'))
            ->assertOk();
    }

    public function test_mcp_traffic_does_not_count_against_sign_in(): void
    {
        $key = $this->key(['monitors:read']);
        foreach (range(1, 12) as $i) {
            $this->rpc($key, 'ping')->assertOk();
        }

        // Sign-in allows 10 attempts per minute per IP; MCP calls must not use them up.
        $this->postJson('/v1/auth/login', ['email' => 'owner@acme.test', 'password' => 'wrong'])
            ->assertStatus(422);
    }

    /** @param list<string> $permissions */
    private function key(array $permissions): string
    {
        return app(ApiKeyService::class)->create(
            $this->account->tenant, $this->account->user, 'MCP', $permissions, $this->account->environment,
        )['plaintext'];
    }

    private function monitor(Environment $environment, string $name, string $state): Monitor
    {
        return Monitor::query()->create([
            'tenant_id' => $environment->tenant_id,
            'environment_id' => $environment->id,
            'name' => $name,
            'kind' => 'http',
            'target' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.example.test',
            'current_state' => $state,
        ]);
    }

    private function incident(Monitor $monitor, ?\DateTimeInterface $resolvedAt): Incident
    {
        return Incident::query()->create([
            'tenant_id' => $monitor->tenant_id,
            'environment_id' => $monitor->environment_id,
            'monitor_id' => $monitor->id,
            'manual' => false,
            'resolved_flag' => $resolvedAt !== null,
            'started_at' => now()->subHours(2),
            'confirmed_at' => now()->subHours(2),
            'resolved_at' => $resolvedAt,
            'severity' => 'major',
            'summary' => $monitor->name.' failed',
        ]);
    }

    /** @return array<string, mixed> */
    private function message(string $method, array $params = []): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params === [] ? new \stdClass : $params];
    }

    private function rpc(string $key, string $method, array $params = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$key])->postJson('/v1/mcp', $this->message($method, $params));
    }

    private function callTool(string $key, string $tool, array $arguments = []): TestResponse
    {
        return $this->rpc($key, 'tools/call', ['name' => $tool, 'arguments' => $arguments === [] ? new \stdClass : $arguments]);
    }

    /** @param array<string, string> $headers */
    private function server(array $headers): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }
}
