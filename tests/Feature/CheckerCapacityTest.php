<?php

namespace Tests\Feature;

use App\Application\Scheduling\CheckerLoad;
use App\Application\Scheduling\CheckerSaturation;
use App\Application\Scheduling\ParallelMonitorCheckRunner;
use App\Application\Scheduling\TickBudget;
use App\Domain\Shared\Clock;
use App\Domain\Shared\FrozenClock;
use App\Infrastructure\Persistence\Eloquent\CheckResult;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\SystemHeartbeat;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use App\Mail\AccountMail;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Checker capacity (#214): saturation alert, load against capacity, capacity test. */
class CheckerCapacityTest extends TestCase
{
    use RefreshDatabase;

    private const SATURATED = ['claimed' => 450, 'executed' => 450, 'deferred' => 30, 'budget_stopped' => true, 'duration_ms' => 34000];

    private const CLEAN = ['claimed' => 20, 'executed' => 20, 'deferred' => 0, 'budget_stopped' => false, 'duration_ms' => 2300];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->at('2026-11-16 12:00:00');
    }

    public function test_saturated_ticks_in_a_row_email_the_platform_admins_once(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        User::factory()->create(['is_platform_admin' => false]);

        $this->tick(self::SATURATED);
        $this->tick(self::SATURATED);
        Mail::assertNothingSent();
        $this->assertFalse(CheckerSaturation::status()['saturated']);

        $this->tick(self::SATURATED);
        Mail::assertSent(AccountMail::class, 1);
        Mail::assertSent(AccountMail::class, fn (AccountMail $mail) => $mail->kind === 'admin_checker_saturated'
            && $mail->hasTo($admin->email)
            && $mail->data['ticks'] === 3
            && $mail->data['deferred'] === 30
            && $mail->data['seconds'] === 34);
        $this->assertTrue(CheckerSaturation::status()['saturated']);

        // Still saturated: no new email inside the cooldown.
        $this->at('2026-11-16 13:00:00');
        $this->tick(self::SATURATED);
        Mail::assertSent(AccountMail::class, 1);

        // After the cooldown, a new email.
        $this->at('2026-11-16 18:00:00');
        $this->tick(self::SATURATED);
        Mail::assertSent(AccountMail::class, 2);
    }

    public function test_a_long_tick_counts_as_saturated_and_a_clean_tick_resets(): void
    {
        config()->set('scheduler.saturation_ticks', 1);
        User::factory()->create(['is_platform_admin' => true]);

        $this->tick(['deferred' => 0, 'duration_ms' => 29_999] + self::CLEAN);
        $this->assertFalse(CheckerSaturation::status()['saturated']);

        $this->tick(['deferred' => 0, 'duration_ms' => 30_000] + self::CLEAN);
        $this->assertTrue(CheckerSaturation::status()['saturated']);
        $this->assertSame('2026-11-16T12:00:00+00:00', CheckerSaturation::status()['since']);

        $this->tick(self::CLEAN);
        $this->assertSame(['saturated' => false, 'consecutive_ticks' => 0, 'since' => null], CheckerSaturation::status());
        Mail::assertSent(AccountMail::class, 1);
    }

    public function test_health_endpoint_answers_503_while_the_checker_is_saturated(): void
    {
        SystemHeartbeat::query()->updateOrCreate(['name' => 'checker'], ['last_seen_at' => now()->subSeconds(20), 'meta_json' => []]);
        $this->get('/v1/platform/health?checker=1')->assertOk()->assertJsonPath('checker.saturation.saturated', false);

        foreach (range(1, 3) as $i) {
            $this->tick(self::SATURATED);
        }

        $this->get('/v1/platform/health?checker=1')->assertStatus(503)
            ->assertJsonPath('status', 'checker_saturated')
            ->assertJsonPath('checker.saturation.consecutive_ticks', 3);

        $this->tick(self::CLEAN);
        $this->get('/v1/platform/health?checker=1')->assertOk()->assertJsonPath('status', 'ok');
    }

    public function test_load_counts_network_checks_per_minute_against_measured_capacity(): void
    {
        [$tenant, $environment] = $this->organization('acme');
        $this->monitor($tenant, $environment, ['kind' => 'http', 'interval_seconds' => 60]);
        $this->monitor($tenant, $environment, ['kind' => 'tcp', 'target' => 'example.com:443', 'interval_seconds' => 120]);
        $this->monitor($tenant, $environment, ['kind' => 'http', 'interval_seconds' => 180]);
        $this->monitor($tenant, $environment, ['kind' => 'http', 'interval_seconds' => 60, 'enabled' => false]);
        $this->monitor($tenant, $environment, ['kind' => 'heartbeat', 'target' => 'heartbeat://x', 'heartbeat_token' => 'hb-1', 'interval_seconds' => 60]);

        $this->assertSame(['demand_per_minute' => 1.8, 'capacity_per_minute' => null, 'utilization' => null], app(CheckerLoad::class)->summary());

        config()->set('scheduler.capacity_per_minute', '300');
        $this->assertSame(['demand_per_minute' => 1.8, 'capacity_per_minute' => 300, 'utilization' => 0.006], app(CheckerLoad::class)->summary());
    }

    public function test_platform_console_reports_the_checker_to_platform_admins_only(): void
    {
        config()->set('scheduler.capacity_per_minute', 400);
        SystemHeartbeat::query()->updateOrCreate(['name' => 'checker'], ['last_seen_at' => now(), 'meta_json' => self::CLEAN]);

        $this->actingAs(User::factory()->create())->getJson('/v1/platform/checker')->assertForbidden();

        $this->actingAs(User::factory()->create(['is_platform_admin' => true]))->getJson('/v1/platform/checker')
            ->assertOk()
            ->assertJsonPath('data.capacity_per_minute', 400)
            ->assertJsonPath('data.demand_per_minute', 0)
            ->assertJsonPath('data.last_tick.duration_ms', 2300)
            ->assertJsonPath('data.saturation.saturated', false)
            ->assertJsonPath('data.saturation.ticks_threshold', 3);
    }

    public function test_a_scoped_run_checks_only_that_organization_and_leaves_the_heartbeat_alone(): void
    {
        $this->app->instance(Clock::class, new \App\Domain\Shared\SystemClock);
        [$real, $realEnv] = $this->organization('real');
        [$test, $testEnv] = $this->organization('test');
        $realMonitor = $this->monitor($real, $realEnv, ['next_check_at' => now()->subMinute()]);
        $this->monitor($test, $testEnv, ['next_check_at' => now()->subMinute()]);
        $this->monitor($test, $testEnv, ['next_check_at' => now()->subMinute()]);

        $stats = app(ParallelMonitorCheckRunner::class)->run(new TickBudget(microtime(true), 20), $test->id);

        $this->assertSame(2, $stats['claimed']);
        $this->assertSame(0, $stats['deferred']);
        $this->assertNull($realMonitor->fresh()->claim_token);
        $this->assertSame(0, CheckResult::query()->where('monitor_id', $realMonitor->id)->count());
        $this->assertSame(0, SystemHeartbeat::query()->count());
    }

    public function test_capacity_test_command_runs_one_tick_and_rolls_everything_back(): void
    {
        $this->app->instance(Clock::class, new \App\Domain\Shared\SystemClock);
        $tenants = Tenant::query()->count();

        $this->artisan('monitor:capacity-test', [
            '--target' => 'http://target:8080/status/200', '--monitors' => 3, '--egress' => 'internal', '--no-wait' => true,
        ])
            ->expectsOutputToContain('Testing 3 monitor(s)')
            ->expectsOutputToContain('Every due monitor fit in one tick')
            ->expectsOutputToContain('Everything was rolled back.')
            ->assertSuccessful();

        $this->assertSame($tenants, Tenant::query()->count());
        $this->assertSame(0, Monitor::query()->count());
        $this->assertSame(0, CheckResult::query()->count());
        $this->assertSame(0, SystemHeartbeat::query()->count());
    }

    public function test_capacity_test_command_validates_input_and_needs_force_in_production(): void
    {
        $this->artisan('monitor:capacity-test', ['--target' => 'not-a-url'])->assertExitCode(2);
        $this->artisan('monitor:capacity-test', ['--target' => 'https://example.com', '--monitors' => 5000])->assertExitCode(2);

        $this->app['env'] = 'production';
        $this->artisan('monitor:capacity-test', ['--target' => 'https://example.com', '--no-wait' => true])
            ->expectsOutputToContain('Pass --force')
            ->assertExitCode(2);
    }

    private function tick(array $stats): void
    {
        app(CheckerSaturation::class)->afterTick($stats);
    }

    private function at(string $utc): void
    {
        $this->app->instance(Clock::class, new FrozenClock(new DateTimeImmutable($utc.' UTC')));
    }

    /** @return array{0: Tenant, 1: Environment} */
    private function organization(string $slug): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $environment = Environment::query()->create(['tenant_id' => $tenant->id, 'name' => 'Production', 'slug' => 'production', 'is_default' => true]);

        return [$tenant, $environment];
    }

    private function monitor(Tenant $tenant, Environment $environment, array $overrides = []): Monitor
    {
        return Monitor::query()->create($overrides + [
            'tenant_id' => $tenant->id,
            'environment_id' => $environment->id,
            'name' => 'Site',
            'kind' => 'http',
            'target' => 'http://target:8080/status/200',
            'interval_seconds' => 60,
            'enabled' => true,
        ]);
    }
}
