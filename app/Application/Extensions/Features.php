<?php

namespace App\Application\Extensions;

use Closure;

/**
 * Named feature flags the operator SPA reads from GET /v1/me (`meta.features`, #250).
 *
 * The core defines its own flags; an edition that adds features (the
 * enterprise service provider, when present) defines more. A flag is a
 * closure, evaluated on each read, so it follows the configuration.
 */
final class Features
{
    /** @var array<string, Closure(): bool> */
    private array $flags = [];

    /** @param  Closure(): bool  $enabled */
    public function define(string $name, Closure $enabled): void
    {
        $this->flags[$name] = $enabled;
    }

    public function enabled(string $name): bool
    {
        return isset($this->flags[$name]) && (bool) ($this->flags[$name])();
    }

    /** @return array<string, bool> */
    public function all(): array
    {
        return array_map(fn (Closure $flag): bool => (bool) $flag(), $this->flags);
    }
}
