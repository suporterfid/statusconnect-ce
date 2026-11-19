<?php

namespace App\Domain\Monitoring;

/**
 * Pure helpers for numeric assertion operands (agent-ingestion addendum §3).
 *
 * Shared by AssertionEvaluator (runtime) and MonitorService (write-time
 * validation) so both sides agree on what counts as a number and a range.
 */
final class NumericThreshold
{
    /**
     * Accepts ints, floats and numeric strings. Booleans, null, arrays and
     * non-numeric strings return null — they are never coerced to 0.
     */
    public static function toNumber(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? $value : null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '' || ! is_numeric($trimmed)) {
                return null;
            }

            $number = $trimmed + 0;

            return is_finite((float) $number) ? $number : null;
        }

        return null;
    }

    /**
     * Parses an inclusive range written as "min,max" or "min..max".
     * A dash separator is deliberately not supported: it is ambiguous with
     * negative numbers ("-5-10").
     *
     * @return array{0: int|float, 1: int|float}|null null when malformed or min > max
     */
    public static function parseRange(?string $range): ?array
    {
        if ($range === null) {
            return null;
        }

        $separator = str_contains($range, '..') ? '..' : ',';
        $parts = explode($separator, $range);

        if (count($parts) !== 2) {
            return null;
        }

        $min = self::toNumber($parts[0]);
        $max = self::toNumber($parts[1]);

        if ($min === null || $max === null || $min > $max) {
            return null;
        }

        return [$min, $max];
    }

    public static function isValidExpectation(AssertionOperator $operator, ?string $expected): bool
    {
        return match ($operator) {
            AssertionOperator::BETWEEN => self::parseRange($expected) !== null,
            AssertionOperator::LT, AssertionOperator::LTE, AssertionOperator::GTE => self::toNumber($expected) !== null,
            default => false,
        };
    }

    public static function isNumericOperator(AssertionOperator $operator): bool
    {
        return in_array($operator, [
            AssertionOperator::LT,
            AssertionOperator::LTE,
            AssertionOperator::GTE,
            AssertionOperator::BETWEEN,
        ], true);
    }
}
