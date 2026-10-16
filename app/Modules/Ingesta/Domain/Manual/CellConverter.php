<?php

namespace App\Modules\Ingesta\Domain\Manual;

use App\Modules\Ingesta\Domain\Schema\ColumnType;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Turns an Excel cell into the value format of the batch contract
 * (docs/arquitectura.md §6.8): integers as int, amounts as a decimal string
 * with a dot and two places, dates as "Y-m-d", text as a trimmed string and
 * empty cells as null.
 *
 * The conversion is best effort: a value it cannot read is handed on as it
 * came (trimmed text), so BatchValidator reports it with its usual message.
 */
final class CellConverter
{
    /** Day zero of Excel serial dates (the 1900 system, with its leap-year bug). */
    private const string EXCEL_EPOCH = '1899-12-30';

    public static function convert(mixed $value, ColumnType $type): mixed
    {
        if ($value === null || (is_string($value) && self::clean($value) === '')) {
            return null;
        }

        return match ($type) {
            ColumnType::Text => self::text($value),
            ColumnType::Integer => self::integer($value),
            ColumnType::Decimal2 => self::decimal($value, 2),
            ColumnType::Decimal6 => self::decimal($value, 6),
            ColumnType::Date => self::date($value),
        };
    }

    /**
     * Integral floats lose their ".0" (a code read as 9280.0 is "9280"),
     * dates become "Y-m-d" and other scalars their trimmed string.
     */
    public static function text(mixed $value): ?string
    {
        $text = match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_float($value) && self::isIntegral($value) => number_format($value, 0, '.', ''),
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            default => '',
        };

        $text = self::clean($text);

        return $text === '' ? null : $text;
    }

    /**
     * An int, an integral float or a string of digits; anything else as it came.
     */
    public static function integer(mixed $value): mixed
    {
        return match (true) {
            is_int($value) => $value,
            is_float($value) && self::isIntegral($value) && abs($value) < PHP_INT_MAX => (int) $value,
            is_string($value) && preg_match('/^-?\d{1,18}$/', self::clean($value)) === 1 => (int) self::clean($value),
            default => self::passThrough($value),
        };
    }

    /**
     * Numbers keep $places decimals. Strings drop "$", spaces and
     * non-breaking spaces; with both "." and "," the last one is the decimal
     * separator; a lone "," followed by one or two digits is decimal and
     * otherwise groups thousands, as does a "." followed by three digits
     * («$ 1.234.567,89», «1,234,567.89», «1.234» and «12,5» all read right).
     */
    public static function decimal(mixed $value, int $places = 2): mixed
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? number_format((float) $value, $places, '.', '') : self::passThrough($value);
        }

        if (! is_string($value)) {
            return self::passThrough($value);
        }

        $text = str_replace(['$', ' ', "\u{00A0}", "\u{202F}"], '', self::clean($value));
        $negative = false;

        if (preg_match('/^\((.*)\)$/', $text, $parts) === 1) {
            [$negative, $text] = [true, $parts[1]];
        }

        if (str_starts_with($text, '-')) {
            [$negative, $text] = [! $negative, substr($text, 1)];
        }

        $normalized = self::normalizeSeparators($text);

        if ($normalized === null || preg_match('/^(\d+)(?:\.(\d+))?$/', $normalized, $parts) !== 1) {
            return self::passThrough($value);
        }

        $integer = ltrim($parts[1], '0') ?: '0';
        $fraction = $parts[2] ?? '';

        // More places than allowed stay visible, so the validator rejects them.
        $fraction = strlen($fraction) > $places ? $fraction : str_pad($fraction, $places, '0');
        $zero = trim($integer.$fraction, '0') === '';

        return ($negative && ! $zero ? '-' : '').$integer.'.'.$fraction;
    }

    /**
     * Dates of DateTime cells, Excel serial numbers or "Y-m-d" and "d/m/Y"
     * strings (a time part is dropped).
     */
    public static function date(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if ((is_int($value) || is_float($value)) && $value >= 1 && $value < 2958466) {
            $epoch = new DateTimeImmutable(self::EXCEL_EPOCH, new DateTimeZone('UTC'));

            return $epoch->modify('+'.(int) floor($value).' days')->format('Y-m-d');
        }

        if (! is_string($value)) {
            return self::passThrough($value);
        }

        $text = self::clean($value);
        $day = explode(' ', $text)[0];

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $parts) === 1) {
            return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $day : $text;
        }

        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $day, $parts) === 1 && checkdate((int) $parts[2], (int) $parts[1], (int) $parts[3])) {
            return sprintf('%04d-%02d-%02d', $parts[3], $parts[2], $parts[1]);
        }

        return $text;
    }

    /**
     * The digits with at most one "." as decimal separator, or null when
     * the separators make no sense.
     */
    private static function normalizeSeparators(string $text): ?string
    {
        $lastDot = strrpos($text, '.');
        $lastComma = strrpos($text, ',');

        if ($lastDot !== false && $lastComma !== false) {
            [$decimal, $thousands] = $lastComma > $lastDot ? [',', '.'] : ['.', ','];

            if (substr_count($text, $decimal) > 1) {
                return null;
            }

            return str_replace([$thousands, $decimal], ['', '.'], $text);
        }

        foreach ([',', '.'] as $separator) {
            if (! str_contains($text, $separator)) {
                continue;
            }

            $decimalLike = substr_count($text, $separator) === 1 && preg_match('/\\'.$separator.'\d{1,2}$/', $text) === 1;
            $decimalLike = $decimalLike || ($separator === '.' && substr_count($text, '.') === 1 && preg_match('/\.\d{3}$/', $text) !== 1);

            return $decimalLike ? str_replace($separator, '.', $text) : str_replace($separator, '', $text);
        }

        return $text;
    }

    private static function isIntegral(float $value): bool
    {
        return is_finite($value) && floor($value) === $value;
    }

    private static function clean(string $value): string
    {
        // trim() works on bytes and would cut multibyte letters; the regex trims characters.
        return (string) preg_replace('/^[\s\x{00A0}\x{202F}]+|[\s\x{00A0}\x{202F}]+$/u', '', $value);
    }

    private static function passThrough(mixed $value): mixed
    {
        return is_string($value) ? self::clean($value) : (is_scalar($value) ? $value : null);
    }
}
