<?php

namespace App\Application\Agents;

final readonly class IngestOutcome
{
    public const STORED = 'stored';

    public const NOT_FOUND = 'not_found';

    public const RATE_LIMITED = 'rate_limited';

    public const INVALID = 'invalid';

    public function __construct(
        public string $status,
        public ?string $message = null,
        public int $stored = 0,
        public int $dropped = 0,
        public ?int $retryAfter = null,
    ) {
    }
}
