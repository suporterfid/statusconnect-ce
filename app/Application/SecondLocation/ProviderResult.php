<?php

namespace App\Application\SecondLocation;

use App\Domain\Monitoring\CheckOutcome;

/**
 * What a second location saw: the raw outcome, evaluated by the checker with
 * the monitor's own assertions, plus where it ran.
 */
final readonly class ProviderResult
{
    public function __construct(
        public string $provider,
        public string $location,
        public CheckOutcome $outcome,
    ) {
    }
}
