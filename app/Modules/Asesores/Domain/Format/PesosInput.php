<?php

namespace App\Modules\Asesores\Domain\Format;

/**
 * Reads an amount typed in a form: "5.000.000", "$ 5.000.000,50", "5000000".
 * Dots group thousands and the comma marks decimals, as people write pesos
 * in Colombia; a lone dot followed by one or two digits is a decimal point.
 */
final class PesosInput
{
    /**
     * The amount in pesos, or null when the text is not a plain, non-negative
     * amount with at most two decimals.
     */
    public static function parse(string $text): ?float
    {
        $clean = str_replace(['$', 'COP', 'cop'], '', $text);
        $clean = (string) preg_replace('/[\s\x{00A0}]+/u', '', $clean);

        if ($clean === '') {
            return null;
        }

        $number = match (true) {
            str_contains($clean, ',') => self::withDecimalComma($clean),
            self::groupsThousands($clean) => str_replace('.', '', $clean),
            default => $clean,
        };

        if ($number === null || preg_match('/^\d+(?:\.\d{1,2})?$/D', $number) !== 1) {
            return null;
        }

        return (float) $number;
    }

    /**
     * "5.000.000,50" -> "5000000.50"; null when the thousands are not dots.
     */
    private static function withDecimalComma(string $text): ?string
    {
        if (substr_count($text, ',') !== 1) {
            return null;
        }

        [$whole, $decimals] = explode(',', $text);

        if (str_contains($whole, ',') || ! self::isWhole($whole)) {
            return null;
        }

        return str_replace('.', '', $whole).'.'.$decimals;
    }

    /**
     * Whether the text is digits, alone or grouped in threes with dots.
     */
    private static function isWhole(string $text): bool
    {
        return preg_match('/^\d+$/D', $text) === 1 || self::groupsThousands($text);
    }

    /**
     * "5.000" and "5.000.000": a group of up to three digits and groups of
     * exactly three after each dot.
     */
    private static function groupsThousands(string $text): bool
    {
        return preg_match('/^\d{1,3}(?:\.\d{3})+$/D', $text) === 1;
    }
}
