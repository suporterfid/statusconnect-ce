<?php

namespace Tests\Feature;

use App\Application\Scheduling\ParallelMonitorCheckRunner;
use App\Domain\Outbound\DnsResolverInterface;
use App\Infrastructure\Persistence\Eloquent\CheckResult;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Tcp\PinnedTcpProbe;
use App\Infrastructure\Tcp\PinnedTcpResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A wave probes every target before it persists any result (2026-11-16):
 * persisting can open incidents and send notifications, and TCP sockets left
 * waiting meanwhile used to read as timeouts of the target.
 */
class WaveProbeOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_tcp_probes_finish_before_any_result_of_the_wave_is_persisted(): void
    {
        $this->app->instance(DnsResolverInterface::class, new class implements DnsResolverInterface {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34'];
            }
        });
        $this->app->forgetInstance(\App\Domain\Outbound\TcpTargetValidator::class);
        $events = new \ArrayObject();
        $this->app->instance(PinnedTcpProbe::class, new class($events) implements PinnedTcpProbe {
            public function __construct(private \ArrayObject $events)
            {
            }

            public function probe(array $requests): array
            {
                $this->events[] = 'tcp_probed';

                return array_map(fn ($r) => new PinnedTcpResult($r->monitorId, true, 3, null), $requests);
            }
        });
        $this->app->forgetInstance(ParallelMonitorCheckRunner::class);
        CheckResult::creating(function () use ($events): void {
            $events[] = 'persisted';
        });

        $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $environment = Environment::query()->create(['tenant_id' => $tenant->id, 'name' => 'Production', 'slug' => 'production', 'is_default' => true]);
        $base = ['tenant_id' => $tenant->id, 'environment_id' => $environment->id, 'interval_seconds' => 60, 'enabled' => true, 'next_check_at' => now()->subMinute()];
        Monitor::query()->create($base + ['name' => 'Heartbeat', 'kind' => 'heartbeat', 'target' => 'heartbeat://x', 'heartbeat_token' => 'hb-1', 'public_safe' => true]);
        Monitor::query()->create($base + ['name' => 'MQTT port', 'kind' => 'tcp', 'target' => 'broker.example:443']);

        app(ParallelMonitorCheckRunner::class)->run();

        $this->assertSame('tcp_probed', $events[0] ?? null, 'the TCP probe ran before anything was persisted');
        $this->assertContains('persisted', $events->getArrayCopy());
        $tcp = Monitor::query()->where('kind', 'tcp')->sole();
        $this->assertSame('up', CheckResult::query()->where('monitor_id', $tcp->id)->sole()->state->value);
    }
}
