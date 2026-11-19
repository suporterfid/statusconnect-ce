<?php

namespace App\Domain\Monitoring\Agent;

/**
 * Outcome of parsing one payload. When $error is set the payload is rejected
 * and $samples is empty.
 */
final readonly class ParseResult
{
    /**
     * @param  list<MetricSample>  $samples  last value per canonical key, in first-seen order
     */
    public function __construct(
        public array $samples = [],
        public int $ignoredStringFields = 0,
        public int $droppedLongKeys = 0,
        public ?LineProtocolError $error = null,
    ) {
    }

    public static function failed(LineProtocolError $error): self
    {
        return new self(error: $error);
    }

    public function ok(): bool
    {
        return $this->error === null;
    }
}
