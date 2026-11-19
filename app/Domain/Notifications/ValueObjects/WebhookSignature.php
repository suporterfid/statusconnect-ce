<?php

namespace App\Domain\Notifications\ValueObjects;

final class WebhookSignature
{
    public const HEADER_NAME = 'X-StatusConnect-Signature';

    public static function create(string $body, string $secret, int $timestamp): string
    {
        $payload = $timestamp.'.'.$body;
        $hash = hash_hmac('sha256', $payload, $secret);

        return "t={$timestamp},v1={$hash}";
    }

    public static function verify(string $signatureHeader, string $body, string $secret, int $now, int $maxSkewSeconds = 300): bool
    {
        if ($signatureHeader === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $segment) {
            $pair = explode('=', trim($segment), 2);
            if (count($pair) === 2) {
                $parts[$pair[0]] = $pair[1];
            }
        }

        if (! isset($parts['t'], $parts['v1'])) {
            return false;
        }

        $timestamp = (int) $parts['t'];
        if (abs($now - $timestamp) > $maxSkewSeconds) {
            return false;
        }

        $expectedHash = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return hash_equals($expectedHash, $parts['v1']);
    }
}
