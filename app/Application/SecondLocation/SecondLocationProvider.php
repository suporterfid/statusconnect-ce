<?php

namespace App\Application\SecondLocation;

interface SecondLocationProvider
{
    public function name(): string;

    /** False when the provider is not set up (no URL, no secret, ...). */
    public function configured(): bool;

    /**
     * Run the check from the provider's location within $timeoutSeconds.
     * Returns null when the provider cannot answer (unsupported request, its
     * own error, rate limit, timeout): the next provider is tried.
     */
    public function check(ConfirmationRequest $request, float $timeoutSeconds): ?ProviderResult;
}
