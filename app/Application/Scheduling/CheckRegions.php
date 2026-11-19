<?php

namespace App\Application\Scheduling;

/**
 * Where a monitor's HTTP checks may run (monitors.check_region, #222, #250).
 *
 * The core checks everything from this server (LocalCheckRegions): one
 * region, `default`, and no other key is accepted.
 */
interface CheckRegions
{
    public const DEFAULT_KEY = 'default';

    public function defaultKey(): string;

    public function exists(?string $key): bool;
}
