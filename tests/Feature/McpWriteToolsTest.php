<?php

namespace Tests\Feature;

use App\Application\ApiKeys\ApiKeyService;
use App\Application\Tenancy\AccountProvisioningService;
use App\Application\Tenancy\ProvisionedAccount;
use App\Domain\Outbound\DnsResolverInterface;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\MaintenanceWindow;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\PlanFixture;
use Tests\TestCase;

/**
 * MCP write tools (#185): permissions, tenant isolation, and the same plan
 * limits, validation and egress policy as the REST API.
 */
class McpWriteToolsTest extends TestCase
{
    use PlanFixture;
    use RefreshDatabase;

    private const READ_TOOLS = ['list_monitors', 'get_monitor', 'list_incidents', 'get_status_page'];

    private const WRITE_TOOLS = ['pause_monitor', 'resume_monitor', 'create_monitor', 'create_maintenance_window'];

    private ProvisionedAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usePlanFixture();

        $this->app->bind(DnsResolverInterface::class, fn () => new class implements DnsResolverInterface {
            public function resolve(string $hostname): array
            {
                return match ($hostname) {
                    'public.example' => ['93.184.216.34'],
                    default => [],
                };
            }
        });

        // Free plan: 10 enabled monitors, 180 s minimum interval.
        $this->account = app(AccountProvisioningService::class)->provision([
            'email' => 'owner@acme.test', 'name' => 'Owner', 'org' => 'Acme', 'slug' => 'acme',
        ]);
        $this->account->tenant->forceFill(['plan' => 'free'])->save();
    }

    public function test_tools_list_depends_on_the_key_permissions(): void
    {
        $this->assertSame(self::READ_TOOLS, $this->toolNames($this->key(['monitors:read'])));
        $this->assertSame([...self::READ_TOOLS, ...self::WRITE_TOOLS], $this->toolNames($this->key(['monitors:write'])));
        $this->assertSame([...self::READ_TOOLS, ...self::WRITE_TOOLS], $this->toolNames($this->key(['*'])));
        $this->assertSame([...self::READ_TOOLS, 'pause_monitor', 'resume_monitor'], $this->toolNames($this->key(['monitors:operate'])));
    }

    public function test_write_tools_carry_write_annotations(): void
    {
        $tools = collect($this->rpc($this->key(['monitors:write']), 'tools/list')->json('result.tools'))->keyBy('name');

        foreach (self::WRITE_TOOLS as $name) {
            $this->assertFalse($tools[$name]['annotations']['readOnlyHint'], $name);
            $this->assertFalse($tools[$name]['annotations']['destructiveHint'], $name);
        }
        $this->assertTrue($tools['pause_monitor']['annotations']['idempotentHint']);
        $this->assertTrue($tools['resume_monitor']['annotations']['idempotentHint']);
        $this->assertFalse($tools['create_monitor']['annotations']['idempotentHint']);
        $this->assertSame(['http', 'tcp', 'heartbeat'], $tools['create_monitor']['inputSchema']['properties']['kind']['enum']);
    }

    public function test_pause_and_resume_flip_enabled(): void
    {
        $monitor = $this->monitor($this->account->environment, 'Website');

        foreach (['monitors:operate', 'monitors:write'] as $permission) {
            $key = $this->key([$permission]);

            $this->callTool($key, 'pause_monitor', ['monitor_id' => $monitor->public_id])
                ->assertJsonPath('result.isError', false)
                ->assertJsonPath('result.structuredContent.monitor.enabled', false);
            $this->assertFalse($monitor->fresh()->enabled);

            // Idempotent: pausing again succeeds and changes nothing.
            $this->callTool($key, 'pause_monitor', ['monitor_id' => $monitor->public_id])->assertJsonPath('result.isError', false);

            $this->callTool($key, 'resume_monitor', ['monitor_id' => $monitor->public_id])
                ->assertJsonPath('result.isError', false)
                ->assertJsonPath('result.structuredContent.monitor.enabled', true);
            $this->assertTrue($monitor->fresh()->enabled);
        }
    }

    public function test_pause_cannot_reach_a_monitor_of_another_environment_or_tenant(): void
    {
        $staging = Environment::query()->create([
            'tenant_id' => $this->account->tenant->id, 'name' => 'Staging', 'slug' => 'staging',
        ]);
        $other = app(AccountProvisioningService::class)->provision([
            'email' => 'other@globex.test', 'name' => 'Other', 'org' => 'Globex', 'slug' => 'globex',
        ]);
        $key = $this->key(['monitors:write']);

        foreach ([$this->monitor($staging, 'Staging API'), $this->monitor($other->environment, 'Globex API')] as $foreign) {
            $this->callTool($key, 'pause_monitor', ['monitor_id' => $foreign->public_id])
                ->assertOk()
                ->assertJsonPath('result.isError', true)
                ->assertJsonPath('result.content.0.text', 'Monitor not found: '.$foreign->public_id);
            $this->assertTrue($foreign->fresh()->enabled);
        }
    }

    public function test_resume_respects_the_plan_monitor_limit(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->monitor($this->account->environment, "Monitor {$i}");
        }
        $paused = $this->monitor($this->account->environment, 'Paused', enabled: false);

        $this->callTool($this->key(['monitors:operate']), 'resume_monitor', ['monitor_id' => $paused->public_id])
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', __('messages.plan_monitor_limit', ['limit' => 10, 'plan' => 'Free']));
        $this->assertFalse($paused->fresh()->enabled);
    }

    public function test_create_monitor_refuses_when_the_plan_is_full(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->monitor($this->account->environment, "Monitor {$i}");
        }

        $this->callTool($this->key(['monitors:write']), 'create_monitor', [
            'name' => 'One too many', 'target' => 'https://public.example/health', 'egress_profile' => 'public-crawl',
        ])
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', __('messages.plan_monitor_limit', ['limit' => 10, 'plan' => 'Free']));
        $this->assertSame(10, Monitor::query()->count());
    }

    public function test_create_monitor_applies_the_plan_interval(): void
    {
        $key = $this->key(['monitors:write']);

        $this->callTool($key, 'create_monitor', [
            'name' => 'Too fast', 'target' => 'https://public.example/health', 'egress_profile' => 'public-crawl', 'interval_seconds' => 60,
        ])
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', __('messages.plan_interval_floor', ['seconds' => 180, 'plan' => 'Free']));
        $this->assertSame(0, Monitor::query()->count());

        $created = $this->callTool($key, 'create_monitor', [
            'name' => 'Website', 'target' => 'https://public.example/health', 'egress_profile' => 'public-crawl',
        ])
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.monitor.interval_seconds', 180)
            ->assertJsonPath('result.structuredContent.monitor.kind', 'http')
            ->assertJsonPath('result.structuredContent.monitor.enabled', true);

        $monitor = Monitor::query()->where('public_id', $created->json('result.structuredContent.monitor.monitor_id'))->firstOrFail();
        $this->assertSame($this->account->environment->id, $monitor->environment_id);
        $this->assertSame('public-crawl', $monitor->egress_profile);
    }

    public function test_create_monitor_has_the_same_egress_outcome_as_the_api(): void
    {
        $key = $this->key(['monitors:write']);
        $url = '/v1/tenants/'.$this->account->tenant->public_id.'/environments/'.$this->account->environment->public_id.'/monitors';

        foreach ([
            ['target' => 'http://169.254.169.254/latest/meta-data/'],
            ['target' => 'https://public.example/health', 'egress_profile' => 'internal'],
        ] as $input) {
            $api = $this->withHeaders(['Authorization' => 'Bearer '.$key])->postJson($url, ['name' => 'Blocked', ...$input]);
            $api->assertStatus(422)->assertJsonValidationErrors('target');

            $this->callTool($key, 'create_monitor', ['name' => 'Blocked', ...$input])
                ->assertJsonPath('result.isError', true)
                ->assertJsonPath('result.content.0.text', $api->json('errors.target.0'));
        }
        $this->assertSame(0, Monitor::query()->count());
    }

    public function test_create_monitor_validates_like_the_api(): void
    {
        $key = $this->key(['monitors:write']);

        foreach ([
            ['name' => 'No target'],
            ['name' => 'Agent', 'kind' => 'agent'],
            ['target' => 'https://public.example/health'],
            ['name' => 'Bad interval', 'target' => 'https://public.example/health', 'interval_seconds' => 5],
        ] as $arguments) {
            $this->callTool($key, 'create_monitor', $arguments)->assertJsonPath('result.isError', true);
        }
        $this->assertSame(0, Monitor::query()->count());
    }

    public function test_create_heartbeat_monitor_returns_its_ping_url(): void
    {
        $result = $this->callTool($this->key(['monitors:write']), 'create_monitor', ['name' => 'Nightly backup', 'kind' => 'heartbeat'])
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.monitor.kind', 'heartbeat');

        $monitor = Monitor::query()->firstOrFail();
        $this->assertSame(url('/ping/'.$monitor->heartbeat_token), $result->json('result.structuredContent.ping_url'));
    }

    public function test_read_only_and_operate_keys_cannot_call_write_tools_by_name(): void
    {
        $monitor = $this->monitor($this->account->environment, 'Website');
        $readOnly = $this->key(['monitors:read']);
        $operate = $this->key(['monitors:operate']);

        $this->callTool($readOnly, 'pause_monitor', ['monitor_id' => $monitor->public_id])->assertJsonPath('result.isError', true);
        foreach ([$readOnly, $operate] as $key) {
            $this->callTool($key, 'create_monitor', ['name' => 'Sneaky', 'target' => 'https://public.example/health', 'egress_profile' => 'public-crawl'])
                ->assertJsonPath('result.isError', true)
                ->assertJsonMissingPath('result.structuredContent');
            $this->callTool($key, 'create_maintenance_window', $this->window())
                ->assertJsonPath('result.isError', true);
        }

        $this->assertTrue($monitor->fresh()->enabled);
        $this->assertSame(1, Monitor::query()->count());
        $this->assertSame(0, MaintenanceWindow::query()->count());
    }

    public function test_create_maintenance_window(): void
    {
        $monitor = $this->monitor($this->account->environment, 'Website');
        $foreign = $this->monitor(app(AccountProvisioningService::class)->provision([
            'email' => 'other@globex.test', 'name' => 'Other', 'org' => 'Globex', 'slug' => 'globex',
        ])->environment, 'Globex API');

        $result = $this->callTool($this->key(['monitors:write']), 'create_maintenance_window', $this->window([
            'monitor_ids' => [$monitor->public_id, $foreign->public_id],
        ]))
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.maintenance_window.title', 'Database upgrade')
            ->assertJsonPath('result.structuredContent.maintenance_window.monitor_ids', [$monitor->public_id]);

        $window = MaintenanceWindow::query()->where('public_id', $result->json('result.structuredContent.maintenance_window.maintenance_window_id'))->firstOrFail();
        $this->assertSame($this->account->environment->id, $window->environment_id);
    }

    public function test_create_maintenance_window_rejects_an_end_before_the_start(): void
    {
        $this->callTool($this->key(['monitors:write']), 'create_maintenance_window', $this->window([
            'starts_at' => now()->addHours(2)->toIso8601String(),
            'ends_at' => now()->addHour()->toIso8601String(),
        ]))
            ->assertJsonPath('result.isError', true);
        $this->callTool($this->key(['monitors:write']), 'create_maintenance_window', ['starts_at' => now()->toIso8601String()])
            ->assertJsonPath('result.isError', true);

        $this->assertSame(0, MaintenanceWindow::query()->count());
    }

    /** @return list<string> */
    private function toolNames(string $key): array
    {
        return array_column($this->rpc($key, 'tools/list')->assertOk()->json('result.tools'), 'name');
    }

    /** @return array<string, mixed> */
    private function window(array $overrides = []): array
    {
        return [
            'title' => 'Database upgrade',
            'starts_at' => now()->addHour()->toIso8601String(),
            'ends_at' => now()->addHours(2)->toIso8601String(),
            ...$overrides,
        ];
    }

    /** @param list<string> $permissions */
    private function key(array $permissions): string
    {
        return app(ApiKeyService::class)->create(
            $this->account->tenant, $this->account->user, 'MCP', $permissions, $this->account->environment,
        )['plaintext'];
    }

    private function monitor(Environment $environment, string $name, bool $enabled = true): Monitor
    {
        return Monitor::query()->create([
            'tenant_id' => $environment->tenant_id,
            'environment_id' => $environment->id,
            'name' => $name,
            'kind' => 'http',
            'target' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.example.test',
            'enabled' => $enabled,
        ]);
    }

    private function rpc(string $key, string $method, array $params = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$key])->postJson('/v1/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params === [] ? new \stdClass : $params,
        ]);
    }

    private function callTool(string $key, string $tool, array $arguments = []): TestResponse
    {
        return $this->rpc($key, 'tools/call', ['name' => $tool, 'arguments' => $arguments === [] ? new \stdClass : $arguments]);
    }
}
