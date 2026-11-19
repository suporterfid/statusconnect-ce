<?php

namespace App\Http\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * One date format for every API response (#156): ISO 8601 in UTC with
 * microseconds and a `Z` suffix, the same format Laravel uses when it
 * serializes Eloquent dates (for example `2026-11-09T14:30:00.000000Z`).
 * Raw database strings are read as UTC.
 */
final class ApiDate
{
    public static function format(DateTimeInterface|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse($value, 'UTC');

        return $date->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}
