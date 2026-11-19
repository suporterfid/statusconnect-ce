<?php

namespace App\Domain\Monitoring\Agent;

/**
 * Ingestion tokens for `agent` monitors (agent-ingestion addendum §4.2):
 * 192 random bits, distinct prefix from heartbeat tokens, stored only as a
 * SHA-256 hash.
 */
final class AgentToken
{
    public static function generate(): string
    {
        return 'sca_'.bin2hex(random_bytes(24));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function looksValid(string $token): bool
    {
        return preg_match('/^sca_[0-9a-f]{48}$/', $token) === 1;
    }
}
