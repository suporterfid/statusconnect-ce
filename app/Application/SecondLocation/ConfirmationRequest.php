<?php

namespace App\Application\SecondLocation;

/**
 * The check a second location should repeat (#128): one HTTP request or one
 * TCP connect, built from the monitor's settings.
 */
final readonly class ConfirmationRequest
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $kind,
        public string $url = '',
        public string $method = 'GET',
        public array $headers = [],
        public ?string $body = null,
        public string $host = '',
        public int $port = 0,
        public int $timeoutMs = 10000,
        public bool $verifyTls = true,
        public bool $followRedirects = true,
    ) {
    }

    /** True when the request carries anything beyond a plain GET or HEAD (it may hold credentials). */
    public function hasCustomRequest(): bool
    {
        return $this->headers !== []
            || ($this->body !== null && $this->body !== '')
            || ! in_array(strtoupper($this->method), ['GET', 'HEAD'], true);
    }
}
