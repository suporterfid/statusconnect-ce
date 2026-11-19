<?php

namespace App\Domain\StatusPage;

/**
 * Hostname rules for a status page's custom domain (e.g. status.example.com).
 */
final class CustomDomain
{
    private const PATTERN = '/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $host = rtrim(strtolower(trim($value)), '.');

        return $host === '' ? null : $host;
    }

    public static function isValid(string $host): bool
    {
        return preg_match(self::PATTERN, $host) === 1;
    }

    /**
     * True when $host is the application's own host or one of its subdomains,
     * which must never be claimed by a tenant.
     */
    public static function isReserved(string $host, string $appHost): bool
    {
        $appHost = strtolower($appHost);

        return $appHost !== '' && ($host === $appHost || str_ends_with($host, '.'.$appHost));
    }
}
