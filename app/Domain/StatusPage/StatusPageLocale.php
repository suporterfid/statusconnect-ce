<?php

namespace App\Domain\StatusPage;

/**
 * Status pages store Laravel's locale code (`pt_BR`, matching lang/pt_BR and the
 * user preference column). Operator input may arrive as the BCP 47 tag the SPA
 * uses (`pt-BR`), so every write normalizes through here.
 */
final class StatusPageLocale
{
    public const SUPPORTED = ['en', 'pt_BR'];

    /** Values accepted on input, before normalization. */
    public const ACCEPTED = ['en', 'pt_BR', 'pt-BR'];

    public static function normalize(?string $locale): string
    {
        $locale = str_replace('-', '_', (string) $locale);

        return in_array($locale, self::SUPPORTED, true) ? $locale : 'en';
    }
}
