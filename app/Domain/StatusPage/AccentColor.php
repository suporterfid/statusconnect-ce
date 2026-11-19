<?php

namespace App\Domain\StatusPage;

/**
 * A tenant's accent colour for the public status page header, plus the text
 * colour that keeps it readable (whichever of near-black or white has the
 * higher WCAG contrast ratio against it).
 */
final class AccentColor
{
    public const LIGHT_TEXT = '#ffffff';

    public const DARK_TEXT = '#111827';

    public static function isValid(?string $value): bool
    {
        return $value !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }

    public static function normalize(?string $value): ?string
    {
        return self::isValid($value) ? strtolower($value) : null;
    }

    public static function textColorFor(string $hex): string
    {
        $background = self::luminance($hex);

        return self::contrast($background, self::luminance(self::LIGHT_TEXT)) >= self::contrast($background, self::luminance(self::DARK_TEXT))
            ? self::LIGHT_TEXT
            : self::DARK_TEXT;
    }

    public static function contrastRatio(string $a, string $b): float
    {
        return self::contrast(self::luminance($a), self::luminance($b));
    }

    private static function contrast(float $a, float $b): float
    {
        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(static function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
