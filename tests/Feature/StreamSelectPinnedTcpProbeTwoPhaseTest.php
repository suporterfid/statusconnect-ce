<?php

namespace Tests\Feature;

use App\Domain\Outbound\ValidatedEndpoint;
use App\Infrastructure\Tcp\PinnedTcpRequest;
use App\Infrastructure\Tcp\StreamSelectPinnedTcpProbe;
use Tests\TestCase;

/**
 * ParallelTcpProbeTest already covers probe() (start()+finish() combined,
 * used by the single-wave path). ParallelMonitorCheckRunner actually calls
 * start() and finish() as two separate phases so HTTP and TCP checks in the
 * same wave run concurrently — that split was previously untested.
 */
class StreamSelectPinnedTcpProbeTwoPhaseTest extends TestCase
{
    public function test_start_then_finish_as_separate_calls_matches_probe(): void
    {
        $ip = gethostbyname('target');
        $endpoint = new ValidatedEndpoint(
            url: 'tcp://target:8080',
            scheme: 'tcp',
            host: 'target',
            port: 8080,
            pinnedIp: $ip,
            resolvedIps: [$ip],
            hostAllowlisted: true,
        );

        $probe = new StreamSelectPinnedTcpProbe();
        $batch = $probe->start([new PinnedTcpRequest(1, $endpoint, 1_000)]);
        $results = $probe->finish($batch);

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->connected);
        $this->assertSame(1, $results[0]->monitorId);
    }

    public function test_finish_accepts_an_external_deadline_alongside_per_request_timeout(): void
    {
        $ip = gethostbyname('target');
        $endpoint = new ValidatedEndpoint(
            url: 'tcp://target:8080',
            scheme: 'tcp',
            host: 'target',
            port: 8080,
            pinnedIp: $ip,
            resolvedIps: [$ip],
            hostAllowlisted: true,
        );

        $probe = new StreamSelectPinnedTcpProbe();
        $batch = $probe->start([new PinnedTcpRequest(1, $endpoint, 5_000)]);
        // A generous future deadline should not interfere with a fast local connect.
        $results = $probe->finish($batch, hrtime(true) + 5_000_000_000);

        $this->assertTrue($results[0]->connected);
    }

    /**
     * Regression (2026-11-16): the caller was busy for longer than the timeout
     * between start() and finish(). A connect that completed meanwhile is up,
     * not a tcp_timeout of the target.
     */
    public function test_a_connect_completed_before_a_late_finish_is_up_not_a_timeout(): void
    {
        $probe = new StreamSelectPinnedTcpProbe();
        $batch = $probe->start([new PinnedTcpRequest(1, $this->endpoint(), 300)]);
        usleep(600_000);

        $results = $probe->finish($batch);

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->connected);
        $this->assertNull($results[0]->error);
    }

    public function test_latency_is_the_connect_time_when_finish_runs_right_away(): void
    {
        $probe = new StreamSelectPinnedTcpProbe();
        $results = $probe->finish($probe->start([new PinnedTcpRequest(1, $this->endpoint(), 5_000)]));

        $this->assertTrue($results[0]->connected);
        $this->assertLessThan(500, $results[0]->latencyMs);
    }

    public function test_a_refused_connect_seen_late_is_still_a_connect_error(): void
    {
        $ip = gethostbyname('target');
        $refused = new ValidatedEndpoint(url: 'tcp://target:1', scheme: 'tcp', host: 'target', port: 1, pinnedIp: $ip, resolvedIps: [$ip], hostAllowlisted: true);
        $probe = new StreamSelectPinnedTcpProbe();
        $batch = $probe->start([new PinnedTcpRequest(1, $refused, 300)]);
        usleep(600_000);

        $results = $probe->finish($batch);

        $this->assertFalse($results[0]->connected);
        $this->assertNotNull($results[0]->error);
    }

    private function endpoint(): ValidatedEndpoint
    {
        $ip = gethostbyname('target');

        return new ValidatedEndpoint(url: 'tcp://target:8080', scheme: 'tcp', host: 'target', port: 8080, pinnedIp: $ip, resolvedIps: [$ip], hostAllowlisted: true);
    }
}
