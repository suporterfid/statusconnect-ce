<?php

namespace Tests\Feature;

use App\Application\ApiKeys\ApiKeyService;
use App\Application\Scheduling\ParallelMonitorCheckRunner;
use App\Application\Tenancy\AccountProvisioningService;
use App\Application\Tenancy\ProvisionedAccount;
use App\Domain\Monitoring\CheckOutcome;
use App\Domain\Monitoring\CheckState;
use App\Domain\Outbound\DnsResolverInterface;
use App\Domain\Outbound\IpClassifier;
use App\Domain\Outbound\TcpTargetValidator;
use App\Domain\Outbound\ValidatedEndpoint;
use App\Infrastructure\Mqtt\MqttConnectProbe;
use App\Infrastructure\Persistence\Eloquent\CheckResult;
use App\Infrastructure\Persistence\Eloquent\Incident;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** MQTT monitors (#200): API, stored credentials and the checker. */
class MqttMonitorTest extends TestCase
{
    use RefreshDatabase;

    private ProvisionedAccount $account;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        config(['second_location.enabled' => false]);
        $this->account = app(AccountProvisioningService::class)->provision([
            'email' => 'owner@acme.test', 'name' => 'Owner', 'org' => 'Acme', 'slug' => 'acme',
        ]);
        $this->key = app(ApiKeyService::class)->create($this->account->tenant, $this->account->user, 'k', ['monitors:write'], $this->account->environment)['plaintext'];
    }

    public function test_create_stores_the_password_encrypted_and_never_returns_it(): void
    {
        $response = $this->api('POST', '', [
            'name' => 'Broker', 'kind' => 'mqtt', 'target' => 'mqtts://broker.example.com:8883',
            'mqtt_client_id' => 'statusconnect-1', 'mqtt_username' => 'monitor', 'mqtt_password' => 'secret-pass',
        ])->assertCreated();

        $response->assertJsonPath('data.kind', 'mqtt')
            ->assertJsonPath('data.mqtt_username', 'monitor')
            ->assertJsonPath('data.mqtt_client_id', 'statusconnect-1')
            ->assertJsonPath('data.mqtt_password_set', true)
            ->assertJsonMissingPath('data.mqtt_password');
        $this->assertStringNotContainsString('secret-pass', $response->getContent());

        $raw = DB::table('monitors')->where('public_id', $response->json('data.public_id'))->value('mqtt_password');
        $this->assertNotSame('secret-pass', $raw);
        $this->assertSame('secret-pass', Monitor::query()->where('public_id', $response->json('data.public_id'))->sole()->mqtt_password);
        $this->assertStringNotContainsString('secret-pass', $this->api('GET', '/'.$response->json('data.public_id'))->getContent());
    }

    public function test_update_without_a_password_keeps_the_stored_one(): void
    {
        $id = $this->api('POST', '', ['name' => 'Broker', 'kind' => 'mqtt', 'target' => 'mqtt://broker.example.com', 'mqtt_username' => 'monitor', 'mqtt_password' => 'secret-pass'])->json('data.public_id');

        $this->api('PUT', "/$id", ['name' => 'Broker 2', 'mqtt_password' => ''])->assertOk()->assertJsonPath('data.mqtt_password_set', true);
        $this->assertSame('secret-pass', Monitor::query()->where('public_id', $id)->sole()->mqtt_password);

        $this->api('PUT', "/$id", ['mqtt_password' => 'new-pass'])->assertOk();
        $this->assertSame('new-pass', Monitor::query()->where('public_id', $id)->sole()->mqtt_password);
    }

    public function test_invalid_targets_and_client_ids_are_refused(): void
    {
        foreach (['broker.example.com:1883', 'https://broker.example.com', 'mqtt://broker:70000'] as $target) {
            $this->api('POST', '', ['name' => 'B', 'kind' => 'mqtt', 'target' => $target])->assertStatus(422)->assertJsonValidationErrors('target');
        }
        $this->api('POST', '', ['name' => 'B', 'kind' => 'mqtt', 'target' => 'mqtt://b.example.com', 'mqtt_client_id' => 'has spaces'])
            ->assertStatus(422)->assertJsonValidationErrors('mqtt_client_id');
        $this->assertSame(0, Monitor::query()->count());
    }

    public function test_the_checker_runs_the_connect_check_with_the_monitor_credentials(): void
    {
        $this->publicDns();
        $probe = new class extends MqttConnectProbe {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public CheckOutcome $answer;

            public function check(ValidatedEndpoint $endpoint, bool $tls, bool $verifyTls, string $clientId, ?string $username, ?string $password, int $timeoutMs): CheckOutcome
            {
                $this->calls[] = compact('tls', 'verifyTls', 'clientId', 'username', 'password', 'timeoutMs') + ['ip' => $endpoint->pinnedIp, 'port' => $endpoint->port, 'host' => $endpoint->host];

                return $this->answer;
            }
        };
        $probe->answer = new CheckOutcome(statusCode: 200, latencyMs: 42);
        $this->app->instance(MqttConnectProbe::class, $probe);
        $monitor = $this->mqttMonitor(['mqtt_username' => 'monitor', 'mqtt_password' => 'secret-pass']);

        app(ParallelMonitorCheckRunner::class)->run();

        $this->assertSame([[
            'tls' => true, 'verifyTls' => true, 'clientId' => 'sc-'.substr($monitor->public_id, -20),
            'username' => 'monitor', 'password' => 'secret-pass', 'timeoutMs' => 5000,
            'ip' => '93.184.216.34', 'port' => 8883, 'host' => 'broker.example.com',
        ]], $probe->calls);
        $result = CheckResult::query()->where('monitor_id', $monitor->id)->sole();
        $this->assertSame(CheckState::UP, $result->state);
        $this->assertSame(42, $result->latency_ms);

        // A refused CONNACK opens an incident like any other failure.
        $probe->answer = CheckOutcome::transportError('MQTT CONNACK refused: 4 (bad user name or password)', 30);
        $monitor->forceFill(['next_check_at' => now()->subSecond()])->save();
        app(ParallelMonitorCheckRunner::class)->run();

        $this->assertSame(CheckState::DOWN, $monitor->fresh()->current_state);
        $this->assertStringContainsString('bad user name or password', (string) Incident::query()->where('monitor_id', $monitor->id)->sole()->summary);
    }

    public function test_the_outbound_policy_applies_to_mqtt_targets(): void
    {
        $this->publicDns();
        $probe = new class extends MqttConnectProbe {
            public int $calls = 0;

            public function check(ValidatedEndpoint $endpoint, bool $tls, bool $verifyTls, string $clientId, ?string $username, ?string $password, int $timeoutMs): CheckOutcome
            {
                $this->calls++;

                return new CheckOutcome(statusCode: 200);
            }
        };
        $this->app->instance(MqttConnectProbe::class, $probe);
        $private = $this->mqttMonitor(['target' => 'mqtt://intranet.example.com:1883']);
        $port = $this->mqttMonitor(['target' => 'mqtt://broker.example.com:9999']);

        app(ParallelMonitorCheckRunner::class)->run();

        $this->assertSame(0, $probe->calls);
        $this->assertSame(CheckState::BLOCKED, CheckResult::query()->where('monitor_id', $private->id)->sole()->state);
        $this->assertSame(CheckState::BLOCKED, CheckResult::query()->where('monitor_id', $port->id)->sole()->state);
    }

    private function publicDns(): void
    {
        $dns = new class implements DnsResolverInterface {
            public function resolve(string $hostname): array
            {
                return match ($hostname) {
                    'broker.example.com' => ['93.184.216.34'],
                    'intranet.example.com' => ['10.0.0.5'],
                    default => [],
                };
            }
        };
        $this->app->instance(DnsResolverInterface::class, $dns);
        $this->app->instance(TcpTargetValidator::class, new TcpTargetValidator($dns, app(IpClassifier::class), [1883, 8883]));
        $this->app->forgetInstance(ParallelMonitorCheckRunner::class);
    }

    private function mqttMonitor(array $overrides = []): Monitor
    {
        return Monitor::query()->create($overrides + [
            'tenant_id' => $this->account->tenant->id,
            'environment_id' => $this->account->environment->id,
            'name' => 'Broker',
            'kind' => 'mqtt',
            'target' => 'mqtts://broker.example.com:8883',
            'interval_seconds' => 60,
            'timeout_ms' => 5000,
            'confirmation_threshold' => 1,
            'recovery_threshold' => 1,
            'enabled' => true,
            'next_check_at' => now()->subMinute(),
        ]);
    }

    private function api(string $method, string $path, array $data = []): \Illuminate\Testing\TestResponse
    {
        $url = '/v1/tenants/'.$this->account->tenant->public_id.'/environments/'.$this->account->environment->public_id.'/monitors'.$path;

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->key])->json($method, $url, $data);
    }
}
