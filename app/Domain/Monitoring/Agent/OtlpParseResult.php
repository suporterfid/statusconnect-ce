<?php

namespace App\Domain\Monitoring\Agent;

/**
 * Outcome of parsing one OTLP/HTTP JSON payload. When $error is set the whole
 * request is refused and $samples is empty; otherwise $rejectedDataPoints
 * counts the points that were left out (reported as partialSuccess).
 */
final readonly class OtlpParseResult
{
    /**
     * @param  list<MetricSample>  $samples  latest value per canonical key
     */
    public function __construct(
        public array $samples = [],
        public int $rejectedDataPoints = 0,
        public ?string $error = null,
    ) {
    }

    public static function failed(string $error): self
    {
        return new self(error: $error);
    }

    public function ok(): bool
    {
        return $this->error === null;
    }
}
