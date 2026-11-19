<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Application\Scheduling\ParallelMonitorCheckRunner;
use App\Application\Scheduling\TickBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MonitorCheckDueCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_executes_a_due_monitor_and_records_checker_heartbeat(): void
    {
        [$tenant, $environment] = $this->tenantContext();
        $monitor = Monitor::query()->create([
            'tenant_id' => $tenant->id,
            'environment_id' => $environment->id,
            'name' => 'Due target check',
            'kind' => 'http',
            'target' => 'http://target:8080/status/200',
            'next_check_at' => now()->subMinute(),
        ]);

        $this->assertSame(0, Artisan::call('monitor:check-due'));

        $this->assertNotNull($monitor->fresh()->last_checked_at);
        $this->assertSame(1, DB::table('check_results')->where('monitor_id', $monitor->id)->count());
        $this->assertSame(1, DB::table('system_heartbeats')->where('name', 'checker')->count());

        $meta = json_decode((string) DB::table('system_heartbeats')->where('name', 'checker')->value('meta_json'), true);
        $this->assertSame(1, $meta['executed']);
        $this->assertIsInt($meta['duration_ms']);
        $this->assertGreaterThanOrEqual(0, $meta['duration_ms']);
        $this->assertMatchesRegularExpression('/executed 1 in \d+ ms\./', Artisan::output());
    }

    public function test_due_monitors_whose_timeout_exceeds_the_remaining_budget_are_counted_as_deferred(): void
    {
        [$tenant, $environment] = $this->tenantContext();
        $base = ['tenant_id' => $tenant->id, 'environment_id' => $environment->id, 'next_check_at' => now()->subMinute()];
        $fast = Monitor::query()->create($base + ['name' => 'fits', 'kind' => 'heartbeat', 'target' => 'heartbeat://fits', 'timeout_ms' => 1000]);
        $slow = Monitor::query()->create($base + ['name' => 'too slow for the rest of the tick', 'kind' => 'http', 'target' => 'http://target:8080/status/200', 'timeout_ms' => 10000]);

        // 5 s left, 1 s reserve: only monitors with a timeout up to 4 s can be claimed.
        $stats = app(ParallelMonitorCheckRunner::class)->run(new TickBudget(microtime(true), 5.0));

        $this->assertSame(1, $stats['executed']);
        $this->assertSame(1, $stats['deferred']);
        $this->assertTrue($stats['budget_stopped']);
        $this->assertNotNull($fast->fresh()->last_checked_at);
        $this->assertNull($slow->fresh()->last_checked_at);
        $meta = json_decode((string) DB::table('system_heartbeats')->where('name', 'checker')->value('meta_json'), true);
        $this->assertSame(1, $meta['deferred']);
    }

    /**
     * @return array{Tenant, Environment}
     */
    private function tenantContext(): array
    {
        $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $environment = Environment::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Production',
            'slug' => 'production',
            'is_default' => true,
        ]);

        return [$tenant, $environment];
    }
}
