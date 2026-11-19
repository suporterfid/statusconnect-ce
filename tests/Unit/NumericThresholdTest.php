<?php

namespace Tests\Unit;

use App\Domain\Monitoring\AssertionOperator;
use App\Domain\Monitoring\NumericThreshold;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NumericThresholdTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: int|float|null}>
     */
    public static function numbers(): array
    {
        return [
            'int' => [42, 42],
            'float' => [4.5, 4.5],
            'numeric string' => ['12', 12],
            'float string' => ['0.25', 0.25],
            'negative string' => ['-3.5', -3.5],
            'string with spaces' => [' 7 ', 7],
            'exponent' => ['1e3', 1000.0],
            'non numeric string' => ['abc', null],
            'empty string' => ['', null],
            'bool' => [true, null],
            'null' => [null, null],
            'array' => [[1], null],
            'infinite float' => [INF, null],
        ];
    }

    #[DataProvider('numbers')]
    public function test_to_number(mixed $input, int|float|null $expected): void
    {
        $this->assertSame($expected, NumericThreshold::toNumber($input));
    }

    /**
     * @return array<string, array{0: ?string, 1: ?array{0: int|float, 1: int|float}}>
     */
    public static function ranges(): array
    {
        return [
            'comma' => ['10,20', [10, 20]],
            'dots' => ['10..20', [10, 20]],
            'floats' => ['0.5,1.5', [0.5, 1.5]],
            'negative bounds' => ['-10..-1', [-10, -1]],
            'equal bounds' => ['5,5', [5, 5]],
            'spaces' => [' 1 , 2 ', [1, 2]],
            'min greater than max' => ['20,10', null],
            'dash is not a separator' => ['10-20', null],
            'single value' => ['10', null],
            'three parts' => ['1,2,3', null],
            'non numeric' => ['a,b', null],
            'empty' => ['', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('ranges')]
    public function test_parse_range(?string $input, ?array $expected): void
    {
        $this->assertSame($expected, NumericThreshold::parseRange($input));
    }

    public function test_is_valid_expectation(): void
    {
        $this->assertTrue(NumericThreshold::isValidExpectation(AssertionOperator::LT, '90'));
        $this->assertFalse(NumericThreshold::isValidExpectation(AssertionOperator::LT, 'ninety'));
        $this->assertFalse(NumericThreshold::isValidExpectation(AssertionOperator::GTE, null));
        $this->assertTrue(NumericThreshold::isValidExpectation(AssertionOperator::BETWEEN, '1..2'));
        $this->assertFalse(NumericThreshold::isValidExpectation(AssertionOperator::BETWEEN, '2..1'));
        $this->assertFalse(NumericThreshold::isValidExpectation(AssertionOperator::EQUALS, '1'));
    }
}
