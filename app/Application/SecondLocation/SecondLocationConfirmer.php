<?php

namespace App\Application\SecondLocation;

use App\Application\Scheduling\TickBudget;

/**
 * Asks a second location to repeat a failing check before an incident opens
 * (#128). Providers are tried in configured order; the first answer wins.
 *
 * Calls run inside the minute cron tick, so they are bounded: at most
 * max_per_tick confirmations per tick, and each one only when the tick still
 * has more than reserve_seconds left. When nothing answers, confirm()
 * returns null and the caller opens the incident as it always did.
 */
class SecondLocationConfirmer
{
    private ?TickBudget $budget = null;

    private int $usedThisTick = 0;

    /** @var array<string, SecondLocationProvider> */
    private array $providers;

    /** @param  iterable<SecondLocationProvider>  $providers */
    public function __construct(iterable $providers)
    {
        $this->providers = [];
        foreach ($providers as $provider) {
            $this->providers[$provider->name()] = $provider;
        }
    }

    /** True when the feature is on and at least one listed provider is set up. */
    public function enabled(): bool
    {
        return (bool) config('second_location.enabled', false) && $this->activeProviders() !== [];
    }

    /**
     * Names of the providers in use (enabled and set up), in order.
     *
     * @return list<string>
     */
    public function activeProviderNames(): array
    {
        return (bool) config('second_location.enabled', false)
            ? array_map(static fn (SecondLocationProvider $p) => $p->name(), $this->activeProviders())
            : [];
    }

    /** Called by the checker at the start of each tick. */
    public function startTick(?TickBudget $budget): void
    {
        $this->budget = $budget;
        $this->usedThisTick = 0;
    }

    public function confirm(ConfirmationRequest $request): ?ProviderResult
    {
        if (! $this->enabled() || $this->usedThisTick >= max(0, (int) config('second_location.max_per_tick', 10))) {
            return null;
        }
        $this->usedThisTick++;

        foreach ($this->activeProviders() as $provider) {
            $timeout = $this->timeoutFor($provider);
            if ($timeout < 1.0) {
                return null;
            }
            $result = $provider->check($request, $timeout);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /** @return list<SecondLocationProvider> */
    private function activeProviders(): array
    {
        $active = [];
        foreach ((array) config('second_location.providers', []) as $name) {
            $provider = $this->providers[(string) $name] ?? null;
            if ($provider !== null && $provider->configured()) {
                $active[] = $provider;
            }
        }

        return $active;
    }

    private function timeoutFor(SecondLocationProvider $provider): float
    {
        $timeout = (float) max(1, (int) config('second_location.'.$provider->name().'.timeout_seconds', 10));
        if ($this->budget !== null) {
            $timeout = min($timeout, $this->budget->remainingSeconds() - max(0, (int) config('second_location.reserve_seconds', 3)));
        }

        return $timeout;
    }
}
