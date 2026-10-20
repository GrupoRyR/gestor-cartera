<?php

namespace App\Modules\Comisiones\Infrastructure\Captures;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Money typed by a person or read from a template: a plain decimal
 * ("77241857.5") or the Colombian format, with or without the peso sign
 * ("77.241.857", "$ 77.241.857,50"). Stored as a decimal string with two
 * decimals ("77241857.50").
 *
 * A single separator followed by exactly three digits is a thousands
 * separator ("77.241" is 77,241 pesos); with both, the last one is the
 * decimal separator.
 */
final class Amount
{
    /**
     * The amount as "123.45", or null when the text is not an amount. A
     * number (from a spreadsheet cell) is taken as is, to cents.
     */
    public static function parse(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value)
                ? BigDecimal::of(sprintf('%.2F', $value))->toScale(2, RoundingMode::HalfUp)->toString()
                : null;
        }

        if (! is_string($value)) {
            return null;
        }

        // Peso sign, "COP", spaces (also the non-breaking ones the screens print).
        $text = preg_replace('/\s+|\x{00A0}|\x{202F}|\$|COP/iu', '', $value) ?? '';
        $negative = str_starts_with($text, '-');
        $text = ltrim($text, '-');

        if ($text === '' || preg_match('/^[\d.,]+$/', $text) !== 1) {
            return null;
        }

        $normalized = self::normalize($text);

        if ($normalized === null || preg_match('/^\d+(\.\d{1,2})?$/', $normalized) !== 1) {
            return null;
        }

        return BigDecimal::of(($negative ? '-' : '').$normalized)->toScale(2)->toString();
    }

    /**
     * The stored amount as a person types it back: Colombian format without
     * the sign, decimals only when there are cents ("77.241.857", "1.234,50").
     */
    public static function forInput(?string $amount): string
    {
        if ($amount === null || $amount === '') {
            return '';
        }

        $text = BigDecimal::of($amount)->toScale(2, RoundingMode::HalfUp)->toString();
        $negative = str_starts_with($text, '-');
        [$whole, $cents] = explode('.', ltrim($text, '-'));
        $grouped = strrev(implode('.', str_split(strrev($whole), 3)));

        return ($negative ? '-' : '').$grouped.($cents === '00' ? '' : ','.$cents);
    }

    /**
     * "77.241.857" → "77241857"; "1.234,5" → "1234.5"; "1234.5" → "1234.5".
     */
    private static function normalize(string $text): ?string
    {
        $dot = strrpos($text, '.');
        $comma = strrpos($text, ',');

        if ($dot !== false && $comma !== false) {
            $decimal = $dot > $comma ? '.' : ',';
            $thousands = $decimal === '.' ? ',' : '.';

            if (substr_count($text, $decimal) > 1) {
                return null;
            }

            [$whole, $fraction] = explode($decimal, $text);

            return self::grouped($whole, $thousands) ? str_replace($thousands, '', $whole).'.'.$fraction : null;
        }

        $separator = $dot !== false ? '.' : ($comma !== false ? ',' : null);

        if ($separator === null) {
            return $text;
        }

        $parts = explode($separator, $text);

        // "77.241.857" or "77.241": thousands. "1234,5" or "1234.50": decimals.
        if (count($parts) > 2 || strlen($parts[1]) === 3) {
            return self::grouped($text, $separator) ? str_replace($separator, '', $text) : null;
        }

        return $parts[0].'.'.$parts[1];
    }

    /** Groups of three digits after the first one, of one to three. */
    private static function grouped(string $whole, string $separator): bool
    {
        if (! str_contains($whole, $separator)) {
            return $whole !== '';
        }

        $quoted = preg_quote($separator, '/');

        return preg_match('/^\d{1,3}('.$quoted.'\d{3})+$/', $whole) === 1;
    }
}
