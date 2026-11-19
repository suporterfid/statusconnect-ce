<?php

namespace App\Infrastructure\HttpClient;

final readonly class MultiPinnedHttpResult
{
    public function __construct(
        public int $monitorId,
        public PinnedHttpResponse $response,
        public int $latencyMs,
        // Remote probe location when the request ran there (#222); null = this server.
        public ?string $location = null,
    ) {
    }
}
