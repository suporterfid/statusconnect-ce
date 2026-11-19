<?php

namespace Tests\Feature;

use App\Application\Maintenance\Services\MaintenanceWindowService;
use App\Application\Monitoring\MonitorService;
use App\Application\Scheduling\CheckExecutor;

use App\Application\StatusPages\StatusPageCacheWriter;
use App\Application\Tenancy\TenantService;
use App\Domain\Monitoring\CheckOutcome;
use App\Domain\Monitoring\CheckState;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Incident;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\StatusPage;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceWindowSuppressionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Environment $environment;

    private Monitor $monitor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        /** @var TenantService $tenantService */
        $tenantService = app(TenantService::class);
        $this->tenant = $tenantService->createTenant('Acme Corp', 'acme-mw-sup', $this->user);
        $this->environment = $this->tenant->environments()->firstOrFail();

        /** @var MonitorService $monitorService */
        $monitorService = app(MonitorService::class);
        $this->monitor = $monitorService->createMonitor($this->tenant, $this->environment, [
            'name' => 'Web App',
            'kind' => 'http',
            'target' => 'http://target:8095/status/200',
            'confirmation_threshold' => 1,
        ]);
    }

    public function test_active_maintenance_window_suppresses_incident_and_evaluates_as_maintenance(): void
    {
        /** @var MaintenanceWindowService $mwService */
        $mwService = app(MaintenanceWindowService::class);

        $mwService->create($this->tenant, $this->environment, [
            'name' => 'Scheduled Maintenance',
            'starts_at' => now()->subMinutes(10)->toIso8601String(),
            'ends_at' => now()->addMinutes(50)->toIso8601String(),
            'suppress_notifications' => true,
            'monitor_ids' => [$this->monitor->public_id],
        ]);

        /** @var CheckExecutor $executor */
        $executor = app(CheckExecutor::class);

        // Execute check during active maintenance window
        $outcome = CheckOutcome::transportError('Connection refused');
        $result = $executor->persist($this->monitor, $outcome);

        $this->assertNotNull($result);
        $this->assertEquals(CheckState::MAINTENANCE, $result->state);

        // Verify zero incidents were created
        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_status_page_payload_includes_active_maintenance_window(): void
    {
        /** @var MaintenanceWindowService $mwService */
        $mwService = app(MaintenanceWindowService::class);

        $mwService->create($this->tenant, $this->environment, [
            'name' => 'Database Migration Window',
            'description' => 'Upgrading DB cluster',
            'starts_at' => now()->subMinutes(5)->toIso8601String(),
            'ends_at' => now()->addMinutes(55)->toIso8601String(),
            'monitor_ids' => [$this->monitor->public_id],
        ]);

        \Illuminate\Support\Facades\DB::table('status_pages')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'environment_id' => $this->environment->id,
            'title' => 'Acme Status Page',
            'slug' => 'acme-status',
            'visibility' => 'public',
            'locale' => 'en',
            'timezone' => 'UTC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var StatusPageCacheWriter $cacheWriter */
        $cacheWriter = app(StatusPageCacheWriter::class);
        $refreshed = $cacheWriter->refreshStalePages();

        $this->assertGreaterThanOrEqual(1, $refreshed);

        $response = $this->getJson('/status/acme-status.json');
        $response->assertStatus(200);

        $maintenance = $response->json('maintenance');
        $this->assertNotEmpty($maintenance);
        $this->assertEquals('Database Migration Window', $maintenance[0]['name']);
    }
}
