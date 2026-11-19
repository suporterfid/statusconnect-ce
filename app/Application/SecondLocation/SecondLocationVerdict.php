<?php

namespace App\Application\SecondLocation;

use App\Domain\Monitoring\CheckState;

/** A second location's result after the monitor's own assertions were applied to it. */
final readonly class SecondLocationVerdict
{
    public function __construct(
        public CheckState $state,
        public string $provider,
        public string $location,
        public ?string $reason = null,
    ) {
    }
}
