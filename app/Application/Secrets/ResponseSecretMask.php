<?php

namespace App\Application\Secrets;

/**
 * Masks credentials in API responses and restores them on write (#155).
 *
 * Monitor request headers and notification channel configs hold credentials
 * (Authorization headers, webhook signing secrets, TaskConnect keys, Slack-style
 * webhook URLs whose path is the secret). Responses show a mask instead; a write
 * that sends the mask back unchanged keeps the stored value, so a client can
 * GET, edit other fields and PUT the object without wiping its credentials.
 */
final class ResponseSecretMask
{
    public const MASK = '********';

    /** Header or config key names that hold a credential. */
    private const SENSITIVE = '/(authori[sz]ation|cookie|token|secret|password|passwd|api[-_]?key|apikey|signature|session|credential|bearer|private)/i';

    /**
     * @param  array<mixed>|null  $headers  {"Name": "value"} or [{"name": …, "value": …}]
     * @return array<mixed>|null
     */
    public static function headers(?array $headers): ?array
    {
        if ($headers === null) {
            return null;
        }
        $out = [];
        foreach ($headers as $key => $value) {
            if (is_array($value) && isset($value['name'])) {
                if (self::isSensitive((string) $value['name']) && isset($value['value']) && $value['value'] !== '') {
                    $value['value'] = self::MASK;
                }
                $out[$key] = $value;
            } else {
                $out[$key] = is_string($key) && self::isSensitive($key) && $value !== null && $value !== '' ? self::MASK : $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $config
     * @return array<string, mixed>|null
     */
    public static function config(?array $config): ?array
    {
        if ($config === null) {
            return null;
        }
        $out = [];
        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $out[$key] = self::config($value);
            } elseif (is_string($value) && $value !== '' && is_string($key) && self::isSensitive($key)) {
                $out[$key] = self::MASK;
            } elseif (is_string($value) && is_string($key) && strtolower($key) === 'url') {
                $out[$key] = self::url($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Puts stored credentials back where an incoming value equals its mask.
     *
     * @param  array<mixed>  $incoming
     * @param  array<mixed>|null  $stored
     * @param  callable(array<mixed>|null): (array<mixed>|null)  $mask  self::headers(...) or self::config(...)
     * @return array<mixed>
     */
    public static function restore(array $incoming, ?array $stored, callable $mask): array
    {
        if ($stored === null || $stored === []) {
            return $incoming;
        }
        $masked = $mask($stored) ?? [];

        return self::restoreLevel($incoming, $stored, $masked);
    }

    /**
     * @param  array<mixed>  $incoming
     * @param  array<mixed>  $stored
     * @param  array<mixed>  $masked
     * @return array<mixed>
     */
    private static function restoreLevel(array $incoming, array $stored, array $masked): array
    {
        foreach ($incoming as $key => $value) {
            if (! array_key_exists($key, $stored) || ! array_key_exists($key, $masked)) {
                continue;
            }
            if (is_array($value) && is_array($stored[$key]) && is_array($masked[$key])) {
                $incoming[$key] = self::restoreLevel($value, $stored[$key], $masked[$key]);
            } elseif ($value === $masked[$key] && $masked[$key] !== $stored[$key]) {
                $incoming[$key] = $stored[$key];
            }
        }

        return $incoming;
    }

    public static function isSensitive(string $name): bool
    {
        return preg_match(self::SENSITIVE, $name) === 1;
    }

    /** Webhook URLs often carry the secret in the path or query: keep only scheme and host. */
    private static function url(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $url === '' ? '' : self::MASK;
        }
        $hasSecretPart = (isset($parts['path']) && trim($parts['path'], '/') !== '') || isset($parts['query']) || isset($parts['user']);
        $base = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return $hasSecretPart ? $base.'/'.self::MASK : $base;
    }
}
