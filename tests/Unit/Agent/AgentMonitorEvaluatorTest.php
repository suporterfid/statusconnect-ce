<?php

namespace Tests\Unit\Agent;

use App\Domain\Monitoring\Agent\AgentMonitorEvaluator;
use App\Domain\Monitoring\Agent\MetricThresholdRule;
use App\Domain\Monitoring\Agent\MissingPolicy;
use App\Domain\Monitoring\Agent\RuleOperator;
use App\Domain\Monitoring\Agent\RuleSeverity;
use App\Domain\Monitoring\CheckState;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AgentMonitorEvaluatorTest extends TestCase
{
    private DateTimeImmutable $now;

    private DateTimeImmutable $ingested;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-11-07 12:00:00');
        $this->ingested = new DateTimeImmutable('2026-11-07 11:59:30');
    }

    public function test_maintenance_skips_and_missing_or_stale_data_is_down(): void
    {
        $evaluator = new AgentMonitorEvaluator();

        $this->assertSame(CheckState::SKIPPED, $evaluator->evaluate([], [], null, $this->now, 60, 180, inMaintenance: true)->state);

        $never = $evaluator->evaluate([], [], null, $this->now, 60, 180);
        $this->assertSame(CheckState::DOWN, $never->state);
        $this->assertStringStartsWith('no_data', $never->reason);

        $stale = $evaluator->evaluate([], [], new DateTimeImmutable('2026-11-07 11:55:59'), $this->now, 60, 180);
        $this->assertSame(CheckState::DOWN, $stale->state);
        $this->assertSame('no_data: last metrics 241s ago (limit 240s)', $stale->reason);

        $this->assertSame(CheckState::UP, $evaluator->evaluate([], [], new DateTimeImmutable('2026-11-07 11:56:00'), $this->now, 60, 180)->state);
    }

    public function test_down_rules_win_over_degraded_rules(): void
    {
        $latest = [
            'mem.used_percent{host=web1}' => $this->sample(93.2),
            'disk.used_percent{host=web1,path=/}' => $this->sample(85.0),
            'system.load5{host=web1}' => $this->sample(0.9),
        ];
        $rules = [
            new MetricThresholdRule('mem.used_percent{host=web1}', RuleOperator::GT, '90', RuleSeverity::DOWN),
            new MetricThresholdRule('disk.used_percent{host=web1,path=/}', RuleOperator::GTE, '80', RuleSeverity::DEGRADED),
            new MetricThresholdRule('system.load5{host=web1}', RuleOperator::GT, '8', RuleSeverity::DOWN),
        ];

        $result = (new AgentMonitorEvaluator())->evaluate($latest, $rules, $this->ingested, $this->now, 60, 180);
        $this->assertSame(CheckState::DOWN, $result->state);
        $this->assertSame('mem.used_percent{host=web1}=93.2 gt 90', $result->reason);

        $latest['mem.used_percent{host=web1}'] = $this->sample(40.0);
        $result = (new AgentMonitorEvaluator())->evaluate($latest, $rules, $this->ingested, $this->now, 60, 180);
        $this->assertSame(CheckState::DEGRADED, $result->state);
        $this->assertSame('disk.used_percent{host=web1,path=/}=85 gte 80', $result->reason);

        $latest['disk.used_percent{host=web1,path=/}'] = $this->sample(10.0);
        $this->assertSame(CheckState::UP, (new AgentMonitorEvaluator())->evaluate($latest, $rules, $this->ingested, $this->now, 60, 180)->state);
    }

    public function test_missing_metrics_follow_the_rule_policy(): void
    {
        $old = new DateTimeImmutable('2026-11-07 11:50:00');
        $latest = ['snmp.up{host=edge}' => ['value' => 1.0, 'received_at' => $old]];
        $ignore = new MetricThresholdRule('snmp.up{host=edge}', RuleOperator::EQUALS, '0');
        $fail = new MetricThresholdRule('snmp.up{host=edge}', RuleOperator::EQUALS, '0', RuleSeverity::DEGRADED, MissingPolicy::FAIL);
        $neverSeen = new MetricThresholdRule('ping.result_code{url=x}', RuleOperator::GT, '0', RuleSeverity::DOWN, MissingPolicy::FAIL);

        $evaluator = new AgentMonitorEvaluator();
        $this->assertSame(CheckState::UP, $evaluator->evaluate($latest, [$ignore], $this->ingested, $this->now, 60, 180)->state);

        $degraded = $evaluator->evaluate($latest, [$fail], $this->ingested, $this->now, 60, 180);
        $this->assertSame(CheckState::DEGRADED, $degraded->state);
        $this->assertSame('snmp.up{host=edge} missing', $degraded->reason);

        $this->assertSame(CheckState::DOWN, $evaluator->evaluate($latest, [$fail, $neverSeen], $this->ingested, $this->now, 60, 180)->state);
    }

    public function test_operators_and_validation(): void
    {
        $this->assertTrue((new MetricThresholdRule('k', RuleOperator::LT, '5'))->firesFor(4.9));
        $this->assertFalse((new MetricThresholdRule('k', RuleOperator::LT, '5'))->firesFor(5));
        $this->assertTrue((new MetricThresholdRule('k', RuleOperator::LTE, '5'))->firesFor(5));
        $this->assertTrue((new MetricThresholdRule('k', RuleOperator::GT, '-1'))->firesFor(0));
        $this->assertTrue((new MetricThresholdRule('k', RuleOperator::GTE, '1e2'))->firesFor(100));
        $this->assertTrue((new MetricThresholdRule('k', RuleOperator::EQUALS, '0'))->firesFor(0.0));
        $this->assertTrue((new MetricThresholdRule('k', RuleOperator::BETWEEN, '10..20'))->firesFor(20));
        $this->assertFalse((new MetricThresholdRule('k', RuleOperator::BETWEEN, '10,20'))->firesFor(20.5));

        $this->expectException(InvalidArgumentException::class);
        new MetricThresholdRule('k', RuleOperator::BETWEEN, '20..10');
    }

    public function test_long_reason_lists_are_truncated(): void
    {
        $latest = [];
        $rules = [];
        for ($i = 0; $i < 7; $i++) {
            $latest["m.f{i=$i}"] = $this->sample(100.0);
            $rules[] = new MetricThresholdRule("m.f{i=$i}", RuleOperator::GT, '1');
        }

        $result = (new AgentMonitorEvaluator())->evaluate($latest, $rules, $this->ingested, $this->now, 60, 180);
        $this->assertStringEndsWith('(+2 more)', $result->reason);
        $this->assertSame(5, substr_count($result->reason, ' gt 1'));
    }

    private function sample(float $value): array
    {
        return ['value' => $value, 'received_at' => $this->ingested];
    }

    public function test_latency_comes_from_a_fresh_positive_metric_rounded_and_clamped(): void
    {
        $evaluator = new AgentMonitorEvaluator();
        $sample = fn (float $value, ?DateTimeImmutable $at = null): array => ['p95' => ['value' => $value, 'received_at' => $at ?? $this->ingested]];

        $this->assertSame(451, $evaluator->latencyMs($sample(450.6), 'p95', $this->ingested, $this->now, 60, 180));
        $this->assertSame(1, $evaluator->latencyMs($sample(0.2), 'p95', $this->ingested, $this->now, 60, 180));
        $this->assertSame(AgentMonitorEvaluator::MAX_LATENCY_MS, $evaluator->latencyMs($sample(1e12), 'p95', $this->ingested, $this->now, 60, 180));

        $this->assertNull($evaluator->latencyMs($sample(450), null, $this->ingested, $this->now, 60, 180), 'no metric key');
        $this->assertNull($evaluator->latencyMs($sample(450), 'other', $this->ingested, $this->now, 60, 180), 'metric missing');
        $this->assertNull($evaluator->latencyMs($sample(0), 'p95', $this->ingested, $this->now, 60, 180), 'zero');
        $this->assertNull($evaluator->latencyMs($sample(-5), 'p95', $this->ingested, $this->now, 60, 180), 'negative');
        $this->assertNull($evaluator->latencyMs($sample(NAN), 'p95', $this->ingested, $this->now, 60, 180), 'not a number');
        $this->assertNull($evaluator->latencyMs($sample(INF), 'p95', $this->ingested, $this->now, 60, 180), 'infinite');
        $this->assertNull($evaluator->latencyMs($sample(450, new DateTimeImmutable('2026-11-07 11:58:00')), 'p95', $this->ingested, $this->now, 60, 180), 'not in the latest ingestion');
        $this->assertNull($evaluator->latencyMs($sample(450), 'p95', null, $this->now, 60, 180), 'never ingested');
        $old = new DateTimeImmutable('2026-11-07 11:55:59');
        $this->assertNull($evaluator->latencyMs($sample(450, $old), 'p95', $old, $this->now, 60, 180), 'ingestion stale');
    }
}
