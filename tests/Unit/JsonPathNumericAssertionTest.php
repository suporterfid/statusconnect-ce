<?php

namespace Tests\Unit;

use App\Domain\Monitoring\AssertionDefinition;
use App\Domain\Monitoring\AssertionEvaluator;
use App\Domain\Monitoring\AssertionOperator;
use App\Domain\Monitoring\AssertionType;
use App\Domain\Monitoring\CheckOutcome;
use App\Domain\Monitoring\CheckState;
use App\Domain\Monitoring\EvaluationResult;
use App\Domain\Shared\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Numeric json_path thresholds (#70, agent-ingestion addendum §3). Pure, no I/O.
 */
class JsonPathNumericAssertionTest extends TestCase
{
    private AssertionEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new AssertionEvaluator(new FrozenClock(new DateTimeImmutable('2026-11-06 12:00:00 UTC')));
    }

    /**
     * @return array<string, array{0: string, 1: AssertionOperator, 2: string, 3: CheckState}>
     */
    public static function thresholdCases(): array
    {
        return [
            'lt passes below' => ['89.9', AssertionOperator::LT, '90', CheckState::UP],
            'lt fails at the boundary' => ['90', AssertionOperator::LT, '90', CheckState::DOWN],
            'lte passes at the boundary' => ['90', AssertionOperator::LTE, '90', CheckState::UP],
            'lte fails above' => ['90.01', AssertionOperator::LTE, '90', CheckState::DOWN],
            'gte passes at the boundary' => ['10', AssertionOperator::GTE, '10', CheckState::UP],
            'gte fails below' => ['9.99', AssertionOperator::GTE, '10', CheckState::DOWN],
            'gte with float threshold' => ['0.5', AssertionOperator::GTE, '0.25', CheckState::UP],
            'lt with negative numbers' => ['-12', AssertionOperator::LT, '-5', CheckState::UP],
            'between comma, lower bound inclusive' => ['10', AssertionOperator::BETWEEN, '10,20', CheckState::UP],
            'between comma, upper bound inclusive' => ['20', AssertionOperator::BETWEEN, '20,20', CheckState::UP],
            'between dots form' => ['15.5', AssertionOperator::BETWEEN, '10..20', CheckState::UP],
            'between with negative range' => ['-3', AssertionOperator::BETWEEN, '-10..-1', CheckState::UP],
            'between fails below' => ['9.9', AssertionOperator::BETWEEN, '10,20', CheckState::DOWN],
            'between fails above' => ['20.1', AssertionOperator::BETWEEN, '10,20', CheckState::DOWN],
        ];
    }

    #[DataProvider('thresholdCases')]
    public function test_numeric_thresholds(string $jsonNumber, AssertionOperator $operator, string $expected, CheckState $state): void
    {
        $result = $this->evaluate('{"mem":{"percent":'.$jsonNumber.'}}', 'mem.percent', $operator, $expected);

        $this->assertSame($state, $result->state);
    }

    public function test_integer_json_value_is_compared_numerically(): void
    {
        $result = $this->evaluate('{"load":{"cpucore":8}}', 'load.cpucore', AssertionOperator::GTE, '4');

        $this->assertSame(CheckState::UP, $result->state);
    }

    public function test_numeric_string_json_value_is_accepted(): void
    {
        $result = $this->evaluate('{"disk":{"free":"1024"}}', 'disk.free', AssertionOperator::GTE, '512');

        $this->assertSame(CheckState::UP, $result->state);
    }

    public function test_numbers_are_not_compared_as_strings(): void
    {
        // As strings "9" > "10"; numerically 9 < 10.
        $result = $this->evaluate('{"v":9}', 'v', AssertionOperator::LT, '10');

        $this->assertSame(CheckState::UP, $result->state);
    }

    public function test_threshold_failure_is_down_not_degraded(): void
    {
        $result = $this->evaluate('{"mem":{"percent":93.2}}', 'mem.percent', AssertionOperator::LT, '90');

        $this->assertSame(CheckState::DOWN, $result->state);
        $this->assertFalse($result->failedAssertion?->isDegraded ?? true);
        $this->assertSame('JSON path "mem.percent" value 93.2 does not satisfy lt 90', $result->reason);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonNumericValues(): array
    {
        return [
            'plain string' => ['"high"'],
            'boolean true' => ['true'],
            'boolean false' => ['false'],
            'object' => ['{"a":1}'],
            'array' => ['[1,2]'],
            'empty string' => ['""'],
        ];
    }

    #[DataProvider('nonNumericValues')]
    public function test_non_numeric_value_fails_explicitly_and_is_never_coerced_to_zero(string $jsonValue): void
    {
        // gte -1 would pass if the value were silently coerced to 0.
        $result = $this->evaluate('{"v":'.$jsonValue.'}', 'v', AssertionOperator::GTE, '-1');

        $this->assertSame(CheckState::DOWN, $result->state);
        $this->assertStringContainsString('is not numeric', (string) $result->reason);
    }

    public function test_missing_path_fails(): void
    {
        $result = $this->evaluate('{"mem":{}}', 'mem.percent', AssertionOperator::LT, '90');

        $this->assertSame(CheckState::DOWN, $result->state);
        $this->assertSame('JSON path "mem.percent" was missing', $result->reason);
    }

    public function test_invalid_expectation_fails_instead_of_passing(): void
    {
        // Rejected at write time by MonitorService; the evaluator must still fail closed.
        $result = $this->evaluate('{"v":5}', 'v', AssertionOperator::BETWEEN, '20,10');

        $this->assertSame(CheckState::DOWN, $result->state);
        $this->assertStringContainsString('Invalid numeric expectation', (string) $result->reason);
    }

    public function test_existing_string_operators_are_unchanged(): void
    {
        $result = $this->evaluate('{"status":"ok"}', 'status', AssertionOperator::EQUALS, 'ok');

        $this->assertSame(CheckState::UP, $result->state);
    }

    private function evaluate(string $body, string $path, AssertionOperator $operator, string $expected): EvaluationResult
    {
        return $this->evaluator->evaluate(
            new CheckOutcome(statusCode: 200, body: $body),
            [new AssertionDefinition(
                type: AssertionType::JSON_PATH,
                operator: $operator,
                targetProperty: $path,
                expectedValue: $expected,
            )],
        );
    }
}
