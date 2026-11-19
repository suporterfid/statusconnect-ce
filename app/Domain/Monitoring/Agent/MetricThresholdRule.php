<?php

namespace App\Domain\Monitoring\Agent;

use App\Domain\Monitoring\NumericThreshold;
use InvalidArgumentException;

/**
 * A threshold on one metric (agent-ingestion addendum §5.2). The rule states
 * the alert condition, like a monitoring trigger: "mem.used_percent gt 90"
 * fires when the value is above 90. The check reason then reads
 * "mem.used_percent{host=web1}=93.2 gt 90" (addendum §5.3).
 */
final readonly class MetricThresholdRule
{
    public function __construct(
        public string $metricKey,
        public RuleOperator $operator,
        public string $expected,
        public RuleSeverity $severity = RuleSeverity::DOWN,
        public MissingPolicy $missingPolicy = MissingPolicy::IGNORE,
    ) {
        if (! self::isValidExpectation($operator, $expected)) {
            throw new InvalidArgumentException(sprintf('Invalid expectation "%s" for operator %s', $expected, $operator->value));
        }
    }

    public static function isValidExpectation(RuleOperator $operator, string $expected): bool
    {
        return $operator === RuleOperator::BETWEEN
            ? NumericThreshold::parseRange($expected) !== null
            : NumericThreshold::toNumber($expected) !== null;
    }

    public function firesFor(float $value): bool
    {
        if ($this->operator === RuleOperator::BETWEEN) {
            [$min, $max] = NumericThreshold::parseRange($this->expected);

            return $value >= $min && $value <= $max;
        }

        $expected = (float) NumericThreshold::toNumber($this->expected);

        return match ($this->operator) {
            RuleOperator::LT => $value < $expected,
            RuleOperator::LTE => $value <= $expected,
            RuleOperator::GT => $value > $expected,
            RuleOperator::GTE => $value >= $expected,
            RuleOperator::EQUALS => abs($value - $expected) < 1e-9,
        };
    }

    public function describe(float $value): string
    {
        return sprintf('%s=%s %s %s', $this->metricKey, self::format($value), $this->operator->value, $this->expected);
    }

    private static function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.') ?: '0';
    }
}
