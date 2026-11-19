<?php

namespace Tests\Feature;

use App\Application\Incidents\IncidentManagementService;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Environment $environment;

    private IncidentManagementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $this->environment = Environment::query()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'Production', 'slug' => 'production', 'is_default' => true,
        ]);
        $this->service = app(IncidentManagementService::class);
    }

    public function test_creates_and_lists_a_manual_incident(): void
    {
        $incident = $this->service->createManual(
            $this->tenant, $this->environment, 'Database is on fire', 'major', new DateTimeImmutable('2026-08-14T10:00:00Z'),
        );

        $this->assertTrue($incident->manual);
        $this->assertNull($incident->monitor_id);
        $this->assertFalse($incident->resolved_flag);

        $list = $this->service->list($this->tenant, $this->environment);
        $this->assertCount(1, $list);
        $this->assertSame('Database is on fire', $list->first()->summary);
    }

    public function test_add_update_attaches_it_to_the_incident(): void
    {
        $incident = $this->service->createManual($this->tenant, $this->environment, 'Outage', 'major', new DateTimeImmutable());

        $update = $this->service->addUpdate(
            $this->tenant, $this->environment, $incident->public_id, 'Investigating', 'investigating', new DateTimeImmutable(),
        );

        $this->assertSame($incident->id, $update->incident_id);
        $this->assertSame('Investigating', $update->message);

        $fetched = $this->service->get($this->tenant, $this->environment, $incident->public_id);
        $this->assertCount(1, $fetched->updates);
    }

    public function test_resolve_sets_resolved_at_and_duration(): void
    {
        $startedAt = new DateTimeImmutable('2026-08-14T10:00:00Z');
        $incident = $this->service->createManual($this->tenant, $this->environment, 'Outage', 'major', $startedAt);

        $resolvedAt = new DateTimeImmutable('2026-08-14T10:05:00Z');
        $resolved = $this->service->resolve($this->tenant, $this->environment, $incident->public_id, $resolvedAt);

        $this->assertNotNull($resolved->resolved_at);
        $this->assertSame(300, $resolved->duration_seconds);
    }

    public function test_resolve_is_idempotent(): void
    {
        $incident = $this->service->createManual($this->tenant, $this->environment, 'Outage', 'major', new DateTimeImmutable('2026-08-14T10:00:00Z'));

        $first = $this->service->resolve($this->tenant, $this->environment, $incident->public_id, new DateTimeImmutable('2026-08-14T10:05:00Z'));
        $second = $this->service->resolve($this->tenant, $this->environment, $incident->public_id, new DateTimeImmutable('2026-08-14T11:00:00Z'));

        $this->assertEquals($first->resolved_at, $second->resolved_at);
        $this->assertSame(300, $second->duration_seconds);
    }

    public function test_get_is_scoped_to_tenant_and_environment(): void
    {
        $incident = $this->service->createManual($this->tenant, $this->environment, 'Outage', 'major', new DateTimeImmutable());

        $otherTenant = Tenant::query()->create(['name' => 'Other', 'slug' => 'other']);
        $otherEnvironment = Environment::query()->create([
            'tenant_id' => $otherTenant->id, 'name' => 'Production', 'slug' => 'production', 'is_default' => true,
        ]);

        $this->expectException(ModelNotFoundException::class);
        $this->service->get($otherTenant, $otherEnvironment, $incident->public_id);
    }
}
