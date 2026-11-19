<?php

namespace Tests\Unit\Domain\Monitoring;

use App\Domain\Monitoring\CheckState;
use App\Domain\Monitoring\Services\HeartbeatMonitorEvaluator;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use DateTimeImmutable;
use Tests\TestCase;

class HeartbeatMonitorEvaluatorTest extends TestCase
{
    public function test_evaluates_up_when_last_ping_is_within_interval_plus_grace(): void
    {
        $evaluator = new HeartbeatMonitorEvaluator();
        $now = new DateTimeImmutable('2026-08-10 12:00:00');

        $monitor = new Monitor([
            'kind' => 'heartbeat',
            'interval_seconds' => 60,
            'heartbeat_grace_seconds' => 30,
            'last_ping_at' => new DateTimeImmutable('2026-08-10 11:59:00'), // 60s ago <= 90s cutoff
        ]);

        $result = $evaluator->evaluate($monitor, $now);

        $this->assertEquals(CheckState::UP, $result->state);
        $this->assertNull($result->reason);
    }

    public function test_evaluates_down_when_last_ping_exceeds_cutoff(): void
    {
        $evaluator = new HeartbeatMonitorEvaluator();
        $now = new DateTimeImmutable('2026-08-10 12:00:00');

        $monitor = new Monitor([
            'kind' => 'heartbeat',
            'interval_seconds' => 60,
            'heartbeat_grace_seconds' => 30,
            'last_ping_at' => new DateTimeImmutable('2026-08-10 11:58:00'), // 120s ago > 90s cutoff
        ]);

        $result = $evaluator->evaluate($monitor, $now);

        $this->assertEquals(CheckState::DOWN, $result->state);
        $this->assertStringContainsString('Heartbeat missed', $result->reason);
    }

    public function test_evaluates_down_when_never_pinged(): void
    {
        $evaluator = new HeartbeatMonitorEvaluator();
        $now = new DateTimeImmutable('2026-08-10 12:00:00');

        $monitor = new Monitor([
            'kind' => 'heartbeat',
            'interval_seconds' => 60,
            'heartbeat_grace_seconds' => 30,
            'last_ping_at' => null,
        ]);

        $result = $evaluator->evaluate($monitor, $now);

        $this->assertEquals(CheckState::DOWN, $result->state);
        $this->assertEquals('Heartbeat never received', $result->reason);
    }
}
