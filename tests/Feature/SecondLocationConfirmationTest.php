<?php

namespace Tests\Feature;

use App\Application\Scheduling\CheckExecutor;
use App\Application\SecondLocation\GlobalpingProvider;
use App\Application\SecondLocation\SecondLocationConfirmer;
use App\Domain\Monitoring\CheckOutcome;
use App\Domain\Monitoring\CheckState;
use App\Infrastructure\Persistence\Eloquent\CheckResult;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Incident;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Second-location confirmation before an incident opens (#128).
 */
class SecondLocationConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret-0123456789abcdef0123456789abcdef';

    private Tenant $tenant;

    private Environment $environment;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('second_location', [
            'enabled' => true,
            'providers' => ['probe', 'globalping'],
            'max_per_tick' => 10,
            'cooldown_seconds' => 300,
            'reserve_seconds' => 3,
            'probe' => ['url' => 'https://probe.test/', 'secret' => self::SECRET, 'timeout_seconds' => 5],
            'globalping' => ['api_url' => 'https://api.globalping.test/v1', 'token' => '', 'location' => 'BR', 'timeout_seconds' => 5],
        ]);
        // No real waiting between Globalping polls.
        $this->app->instance(GlobalpingProvider::class, new GlobalpingProvider(static fn (int $us) => null));
        $this->app->forgetInstance(SecondLocationConfirmer::class);

        $this->tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $this->environment = Environment::query()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'production', 'slug' => 'production', 'is_default' => true,
        ]);
    }

    public function test_disabled_feature_opens_incident_without_asking_anyone(): void
    {
        config()->set('second_location.enabled', false);
        Http::fake();
        $monitor = $this->monitor();

        $result = $this->failCheck($monitor);

        $this->assertSame(CheckState::DOWN, $result->state);
        $this->assertNull($result->confirmation_state);
        $this->assertSame(1, Incident::query()->where('monitor_id', $monitor->id)->count());
        Http::assertNothingSent();
    }

    public function test_failure_confirmed_by_the_probe_opens_the_incident(): void
    {
        Http::fake(['probe.test/*' => Http::response($this->probeAnswer(503))]);
        $monitor = $this->monitor();

        $result = $this->failCheck($monitor);

        $this->assertSame(CheckState::DOWN, $result->state);
        $this->assertSame('confirmed', $result->confirmation_state);
        $this->assertSame('probe', $result->confirmation_provider);
        $this->assertSame('São Paulo, BR', $result->confirmation_location);
        $incident = Incident::query()->where('monitor_id', $monitor->id)->sole();
        $this->assertStringContainsString('(confirmed from São Paulo, BR)', $incident->summary);
        $this->assertSame(CheckState::DOWN, $monitor->fresh()->current_state);

        Http::assertSent(function (Request $request) {
            $timestamp = $request->header('X-StatusConnect-Timestamp')[0];
            $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), self::SECRET);

            return $request->url() === 'https://probe.test/'
                && hash_equals($expected, $request->header('X-StatusConnect-Signature')[0])
                && $request['kind'] === 'http'
                && $request['url'] === 'https://example.com/health';
        });
    }

    public function test_target_up_from_second_location_opens_no_incident_and_cools_down(): void
    {
        Http::fake(['probe.test/*' => Http::sequence()
            ->push($this->probeAnswer(200))
            ->push($this->probeAnswer(500))]);
        $monitor = $this->monitor();

        $result = $this->failCheck($monitor);

        $this->assertSame(CheckState::UP, $result->state, 'stored as up so uptime is not charged for a local network problem');
        $this->assertSame('not_confirmed', $result->confirmation_state);
        $this->assertStringContainsString('Failed from this server (', $result->failure_reason);
        $this->assertStringContainsString('up from São Paulo, BR', $result->failure_reason);
        $this->assertSame(0, Incident::query()->count());
        $fresh = $monitor->fresh();
        $this->assertNotSame(CheckState::DOWN, $fresh->current_state);
        $this->assertSame(0, (int) $fresh->consecutive_failures);
        $this->assertNotNull($fresh->second_location_up_until);
        Http::assertSentCount(1);

        // Within the cooldown: no new remote call, still no incident.
        $second = $this->failCheck($fresh);
        $this->assertSame('not_confirmed', $second->confirmation_state);
        $this->assertNull($second->confirmation_provider);
        $this->assertSame(0, Incident::query()->count());
        Http::assertSentCount(1);

        // After the cooldown the probe is asked again; now it sees the outage.
        // (The checker clock is the system clock, so expire the cooldown in the row.)
        Monitor::query()->whereKey($monitor->id)->update(['second_location_up_until' => now()->subMinute()]);
        $third = $this->failCheck($monitor->fresh());
        $this->assertSame('confirmed', $third->confirmation_state);
        $this->assertSame(1, Incident::query()->count());
        $this->assertNull($monitor->fresh()->second_location_up_until);
    }

    public function test_globalping_answers_when_the_probe_fails(): void
    {
        Http::fake([
            'probe.test/*' => Http::response(['error' => 'unauthorized'], 401),
            'api.globalping.test/v1/measurements/m1' => Http::sequence()
                ->push(['id' => 'm1', 'status' => 'in-progress', 'results' => []])
                ->push($this->globalpingHttp(200)),
            'api.globalping.test/v1/measurements' => Http::response(['id' => 'm1', 'probesCount' => 1], 202),
        ]);
        $monitor = $this->monitor();

        $result = $this->failCheck($monitor);

        $this->assertSame('not_confirmed', $result->confirmation_state);
        $this->assertSame('globalping', $result->confirmation_provider);
        $this->assertSame('São Paulo, BR', $result->confirmation_location);
        $this->assertSame(0, Incident::query()->count());

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.globalping.test/v1/measurements'
            && $r['type'] === 'http'
            && $r['target'] === 'example.com'
            && $r['locations'] === [['magic' => 'BR']]
            && $r['measurementOptions']['protocol'] === 'HTTPS'
            && $r['measurementOptions']['request']['path'] === '/health'
            && $r['measurementOptions']['request']['method'] === 'GET'
            && $r->header('User-Agent')[0] === 'StatusConnect-SecondLocation/1 ('.'+'.rtrim((string) config('app.url'), '/').'/)');
    }

    public function test_no_provider_answering_opens_the_incident_as_before(): void
    {
        Http::fake([
            'probe.test/*' => fn () => throw new ConnectionException('timed out'),
            'api.globalping.test/*' => Http::response(['error' => ['type' => 'rate_limit_exceeded']], 429),
        ]);
        $monitor = $this->monitor();

        $result = $this->failCheck($monitor);

        $this->assertSame(CheckState::DOWN, $result->state);
        $this->assertSame('unavailable', $result->confirmation_state);
        $this->assertSame(1, Incident::query()->count());
    }

    public function test_requests_with_custom_headers_are_never_sent_to_globalping(): void
    {
        config()->set('second_location.providers', ['globalping']);
        Http::fake();
        $monitor = $this->monitor(['request_headers_json' => ['Authorization' => 'Bearer secret']]);

        $result = $this->failCheck($monitor);

        $this->assertSame('unavailable', $result->confirmation_state);
        $this->assertSame(1, Incident::query()->count());
        Http::assertNothingSent();
    }

    public function test_tcp_failure_confirmed_by_globalping_tcp_ping(): void
    {
        config()->set('second_location.providers', ['globalping']);
        Http::fake([
            'api.globalping.test/v1/measurements/m2' => Http::response([
                'id' => 'm2', 'status' => 'finished',
                'results' => [[
                    'probe' => ['city' => 'Rio de Janeiro', 'country' => 'BR'],
                    'result' => ['status' => 'finished', 'stats' => ['loss' => 100, 'avg' => null]],
                ]],
            ]),
            'api.globalping.test/v1/measurements' => Http::response(['id' => 'm2', 'probesCount' => 1], 202),
        ]);
        $monitor = $this->monitor(['kind' => 'tcp', 'target' => 'db.example.com:5432']);

        $result = $this->failCheck($monitor, CheckOutcome::transportError('tcp_connect_error'));

        $this->assertSame('confirmed', $result->confirmation_state);
        $this->assertSame('Rio de Janeiro, BR', $result->confirmation_location);
        $this->assertSame(1, Incident::query()->count());
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.globalping.test/v1/measurements'
            && $r['type'] === 'ping' && $r['target'] === 'db.example.com'
            && $r['measurementOptions'] === ['protocol' => 'TCP', 'port' => 5432, 'packets' => 3]);
    }

    public function test_remote_answer_is_judged_by_the_monitors_own_assertions(): void
    {
        Http::fake(['probe.test/*' => Http::response($this->probeAnswer(200, 'maintenance page'))]);
        $monitor = $this->monitor();
        \App\Infrastructure\Persistence\Eloquent\MonitorAssertion::query()->create([
            'monitor_id' => $monitor->id,
            'type' => \App\Domain\Monitoring\AssertionType::BODY_CONTAINS,
            'operator' => \App\Domain\Monitoring\AssertionOperator::CONTAINS,
            'expected_value' => 'all good',
            'order' => 0,
        ]);

        $result = $this->failCheck($monitor->fresh('assertions'), new CheckOutcome(statusCode: 200, body: 'maintenance page'));

        $this->assertSame('confirmed', $result->confirmation_state);
        $this->assertSame(1, Incident::query()->count());
    }

    public function test_confirmations_per_tick_are_capped(): void
    {
        config()->set('second_location.max_per_tick', 1);
        Http::fake(['probe.test/*' => Http::response($this->probeAnswer(503))]);
        app(SecondLocationConfirmer::class)->startTick(null);

        $first = $this->failCheck($this->monitor());
        $second = $this->failCheck($this->monitor(['name' => 'Second']));

        $this->assertSame('confirmed', $first->confirmation_state);
        $this->assertSame('unavailable', $second->confirmation_state);
        $this->assertSame(2, Incident::query()->count());
        Http::assertSentCount(1);
    }

    /** @param  array<string, mixed>  $overrides */
    private function monitor(array $overrides = []): Monitor
    {
        return Monitor::query()->create($overrides + [
            'tenant_id' => $this->tenant->id,
            'environment_id' => $this->environment->id,
            'name' => 'Site',
            'kind' => 'http',
            'target' => 'https://example.com/health',
            'interval_seconds' => 60,
            'timeout_ms' => 5000,
            'confirmation_threshold' => 1,
            'recovery_threshold' => 1,
            'enabled' => true,
        ])->fresh('assertions');
    }

    private function failCheck(Monitor $monitor, ?CheckOutcome $outcome = null): CheckResult
    {
        $result = app(CheckExecutor::class)->persist($monitor, $outcome ?? CheckOutcome::transportError('connect_timeout', 5000));
        $this->assertNotNull($result);

        return $result->fresh();
    }

    /** @return array<string, mixed> */
    private function probeAnswer(int $status, string $body = 'ok'): array
    {
        return [
            'v' => 1, 'location' => 'São Paulo, BR', 'kind' => 'http', 'ok' => true, 'status_code' => $status,
            'latency_ms' => 120, 'headers' => ['content-type' => 'text/plain'], 'body_base64' => base64_encode($body),
            'tls_expires_at' => null, 'error' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function globalpingHttp(int $status): array
    {
        return [
            'id' => 'm1', 'status' => 'finished',
            'results' => [[
                'probe' => ['city' => 'São Paulo', 'country' => 'BR', 'asn' => 1, 'network' => 'Example'],
                'result' => [
                    'status' => 'finished', 'statusCode' => $status, 'headers' => ['Content-Type' => 'text/html'],
                    'rawBody' => 'ok', 'timings' => ['total' => 210], 'tls' => ['expiresAt' => '2027-01-01T00:00:00.000Z'],
                ],
            ]],
        ];
    }
}
