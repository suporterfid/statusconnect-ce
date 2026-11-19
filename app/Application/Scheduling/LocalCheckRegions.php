<?php

namespace App\Application\Scheduling;

/** Local-only execution (#250): every check runs on this server, in the default region. */
final class LocalCheckRegions implements CheckRegions
{
    public function defaultKey(): string
    {
        return self::DEFAULT_KEY;
    }

    public function exists(?string $key): bool
    {
        return $key === self::DEFAULT_KEY;
    }
}
