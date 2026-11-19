<?php

namespace App\Infrastructure\HttpClient;

final readonly class MultiPinnedHttpRequest
{
    public function __construct(
        public int $monitorId,
        public PinnedHttpRequest $request,
        // Probe region key (monitors.check_region, #222); null = the default region.
        // Only ProbeBatchHttpProbe reads it.
        public ?string $region = null,
    ) {
    }
}
