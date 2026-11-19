<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\CheckResult;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ParallelMonitorCheckRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_executes_due_monitors_in_bounded_waves_and_persists_every_result(): void
    {
        config()->set('scheduler.check_concurrency', 2);
        config()->set('scheduler.check_wave_size', 2);
        $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $environment = Environment::query()->create([
            'tenant_id' => $tenant->id, 'name' => 'Production', 'slug' => 'production', 'is_default' => true,
        ]);
        for ($index = 1; $index <= 4; $index++) {
            Monitor::query()->create([
                'tenant_id' => $tenant->id,
                'environment_id' => $environment->id,
                'name' => "Wave {$index}",
                'kind' => 'http',
                'target' => 'http://target:8080/status/200',
                'next_check_at' => now()->subMinute(),
            ]);
        }

        $this->assertSame(0, Artisan::call('monitor:check-due'));

        $this->assertSame(4, CheckResult::query()->count());
        $this->assertSame(0, Monitor::query()->whereNotNull('claim_token')->count());
    }

    public function test_http_checks_roll_so_a_slow_target_does_not_hold_back_the_others(): void
    {
        config()->set('scheduler.check_concurrency', 2);
        config()->set('scheduler.check_wave_size', 2);
        [$tenant, $environment] = $this->organization();
        // Ordered so that waves of two would each hold a 1 s target: about 3 s in waves.
        foreach (['/delay/1000', '/status/200', '/delay/1000', '/status/200', '/delay/1000', '/status/200'] as $i => $path) {
            Monitor::query()->create([
                'tenant_id' => $tenant->id, 'environment_id' => $environment->id, 'name' => "M{$i}", 'kind' => 'http',
                'target' => 'http://target:8080'.$path, 'current_state' => 'up', 'next_check_at' => now()->subMinutes(10)->addSeconds($i),
            ]);
        }

        $stats = app(\App\Application\Scheduling\ParallelMonitorCheckRunner::class)->run();

        $this->assertSame(6, $stats['executed']);
        $this->assertSame(0, $stats['deferred']);
        $this->assertLessThan(2_600, $stats['duration_ms']);
        $this->assertSame(6, CheckResult::query()->where('state', 'up')->count());
        $this->assertSame(0, Monitor::query()->whereNotNull('claim_token')->count());
    }

    public function test_failures_and_state_changes_are_persisted_with_their_side_effects_between_streams(): void
    {
        config()->set('scheduler.check_concurrency', 3);
        [$tenant, $environment] = $this->organization();
        $failing = Monitor::query()->create([
            'tenant_id' => $tenant->id, 'environment_id' => $environment->id, 'name' => 'Down', 'kind' => 'http',
            'target' => 'http://target:8080/status/500', 'confirmation_threshold' => 1, 'current_state' => 'up',
            'next_check_at' => now()->subMinute(),
        ]);
        $steady = [];
        for ($i = 0; $i < 4; $i++) {
            $steady[] = Monitor::query()->create([
                'tenant_id' => $tenant->id, 'environment_id' => $environment->id, 'name' => "Up {$i}", 'kind' => 'http',
                'target' => 'http://target:8080/status/200', 'current_state' => 'up', 'next_check_at' => now()->subMinute(),
            ]);
        }
        $new = Monitor::query()->create([
            'tenant_id' => $tenant->id, 'environment_id' => $environment->id, 'name' => 'New', 'kind' => 'http',
            'target' => 'http://target:8080/status/200', 'next_check_at' => now()->subMinute(),
        ]);

        $stats = app(\App\Application\Scheduling\ParallelMonitorCheckRunner::class)->run();

        $this->assertSame(6, $stats['executed']);
        $this->assertSame('down', $failing->fresh()->current_state->value);
        $this->assertSame(1, \App\Infrastructure\Persistence\Eloquent\Incident::query()->where('monitor_id', $failing->id)->count());
        $this->assertSame('up', $new->fresh()->current_state->value);
        foreach ($steady as $monitor) {
            $this->assertSame('up', $monitor->fresh()->current_state->value);
        }
        $this->assertSame(0, Monitor::query()->whereNotNull('claim_token')->count());
    }

    public function test_routine_up_is_only_an_up_result_for_an_up_monitor(): void
    {
        [$tenant, $environment] = $this->organization();
        $executor = app(\App\Application\Scheduling\CheckExecutor::class);
        $up = Monitor::query()->create(['tenant_id' => $tenant->id, 'environment_id' => $environment->id, 'name' => 'A', 'kind' => 'http', 'target' => 'https://example.com', 'current_state' => 'up']);
        $pending = Monitor::query()->create(['tenant_id' => $tenant->id, 'environment_id' => $environment->id, 'name' => 'B', 'kind' => 'http', 'target' => 'https://example.com']);
        $ok = new \App\Domain\Monitoring\CheckOutcome(statusCode: 200, latencyMs: 50);

        $this->assertTrue($executor->isRoutineUp($up->fresh('assertions'), $ok));
        $this->assertFalse($executor->isRoutineUp($up->fresh('assertions'), new \App\Domain\Monitoring\CheckOutcome(statusCode: 500, latencyMs: 50)));
        $this->assertFalse($executor->isRoutineUp($up->fresh('assertions'), \App\Domain\Monitoring\CheckOutcome::transportError('connect_timeout', 10000)));
        $this->assertFalse($executor->isRoutineUp($pending->fresh('assertions'), $ok));
    }

    /** @return array{0: Tenant, 1: Environment} */
    private function organization(): array
    {
        $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $environment = Environment::query()->create([
            'tenant_id' => $tenant->id, 'name' => 'Production', 'slug' => 'production', 'is_default' => true,
        ]);

        return [$tenant, $environment];
    }

    /**
     * Regression: IncidentService::record() clears claim_token inside its own
     * DB transaction, so an exception thrown anywhere in that transaction
     * used to roll it back and leave the monitor claimed until
     * claim_expires_at. persistSafely() must catch and release the claim
     * directly instead of letting it leak.
     */
    public function test_persist_exception_releases_the_claim_instead_of_leaking_it(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $environment = Environment::query()->create([
            'tenant_id' => $tenant->id, 'name' => 'Production', 'slug' => 'production', 'is_default' => true,
        ]);
        Monitor::query()->create([
            'tenant_id' => $tenant->id,
            'environment_id' => $environment->id,
            'name' => 'Heartbeat monitor',
            'kind' => 'heartbeat',
            'target' => 'heartbeat://acme',
            'heartbeat_token' => 'hb-token-1',
            'next_check_at' => now()->subMinute(),
        ]);

        $this->mock(\App\Application\Scheduling\CheckExecutor::class, function ($mock) {
            $mock->shouldReceive('persist')->once()->andThrow(new \RuntimeException('simulated persist failure'));
        });

        $stats = app(\App\Application\Scheduling\ParallelMonitorCheckRunner::class)->run();

        $this->assertSame(1, $stats['claimed']);
        $this->assertSame(0, $stats['executed']);
        $this->assertSame(0, Monitor::query()->whereNotNull('claim_token')->count());
    }
}
