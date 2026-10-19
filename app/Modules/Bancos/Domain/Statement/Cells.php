<?php

namespace App\Modules\Bancos\Domain\Statement;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Reads the raw cells of a statement: headers, texts, dates and amounts.
 *
 * A spreadsheet hands over typed values (numbers, dates) and a CSV hands over
 * text, so every reader accepts both. Amounts come back as decimal strings
 * ("1234567.89"), never floats, so a sum of statements is exact.
 */
final class Cells
{
    public const string TIMEZONE = 'America/Bogota';

    /** Statements of any other year are almost surely a misread date (e.g. "15/10/26"). */
    private const int FIRST_YEAR = 1990;

    private const int LAST_YEAR = 2100;

    /** Range of Excel day serials that can be a modern date (1954 to 2118). */
    private const int SERIAL_FROM = 20000;

    private const int SERIAL_TO = 80000;

    private const string ACCENTS = 'áàäâéèëêíìïîóòöôúùüûñ';

    private const string PLAIN = 'aaaaeeeeiiiioooouuuun';

    /**
     * A header in the form used to compare it with the aliases of a bank:
     * lowercase, without accents or punctuation, one space between words.
     */
    public static function header(mixed $cell): string
    {
        if ($cell instanceof DateTimeInterface || $cell === null) {
            return '';
        }

        $text = mb_strtolower(trim((string) $cell));
        $text = strtr($text, array_combine(mb_str_split(self::ACCENTS), mb_str_split(self::PLAIN)));
        $text = (string) preg_replace('/[^a-z0-9]+/', ' ', $text);

        return trim($text);
    }

    /**
     * The text of a cell, trimmed; null when it is empty or not text.
     */
    public static function text(mixed $cell): ?string
    {
        if ($cell === null || $cell instanceof DateTimeInterface) {
            return null;
        }

        if (is_float($cell) && floor($cell) === $cell) {
            $cell = (int) $cell;
        }

        $text = trim(str_replace("\u{00A0}", ' ', (string) $cell));

        return $text === '' ? null : $text;
    }

    /**
     * The date of a cell: a typed date, an Excel serial number, or text in one
     * of the formats (tried in order; the order of day and month is never
     * guessed). Null when the cell is empty or is not a date.
     *
     * @param  list<string>  $formats  PHP date formats, e.g. ['d/m/Y', 'Y-m-d'].
     */
    public static function date(mixed $cell, array $formats): ?CarbonImmutable
    {
        if ($cell instanceof DateTimeInterface) {
            return self::startOfDay($cell->format('Y-m-d'));
        }

        // A number is an Excel day serial; one outside that range is a date typed as digits
        // (20261015), which goes on to be read as text with the formats of the bank.
        if ((is_int($cell) || is_float($cell)) && ($serial = self::fromSerial($cell)) !== null) {
            return $serial;
        }

        $text = self::text($cell);

        if ($text === null) {
            return null;
        }

        // "15/10/2026 08:30": the time of day does not matter, only the date.
        $text = (string) preg_replace('/^(\S+)\s+\d{1,2}:\d{2}(?::\d{2})?(?:\s*[ap]\.?m\.?)?$/i', '$1', $text);

        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $text, new \DateTimeZone(self::TIMEZONE));
            $errors = DateTimeImmutable::getLastErrors();

            if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                continue;
            }

            $year = (int) $date->format('Y');

            if ($year >= self::FIRST_YEAR && $year <= self::LAST_YEAR) {
                return CarbonImmutable::instance($date);
            }
        }

        return null;
    }

    /**
     * The amount of a cell as a signed decimal string with two places; null
     * when the cell is empty.
     *
     * Debits may be printed with a leading minus, a trailing minus or between
     * parentheses. A number whose thousands groups are not groups of three
     * (e.g. "1234567.89" in a Colombian file) is refused rather than guessed:
     * reading it the wrong way would be off by a factor of 100.
     *
     * @throws UnreadableCell When the cell is not a number in the style.
     */
    public static function amount(mixed $cell, NumberStyle $style): ?string
    {
        if (is_int($cell) || is_float($cell)) {
            return self::fromNumber($cell);
        }

        $text = self::text($cell);

        if ($text === null) {
            return null;
        }

        $negative = false;

        if (preg_match('/^\((.*)\)$/', $text, $matches) === 1) {
            $negative = true;
            $text = trim($matches[1]);
        }

        if (str_ends_with($text, '-')) {
            $negative = true;
            $text = trim(substr($text, 0, -1));
        }

        if ($text !== '' && ($text[0] === '-' || $text[0] === '+')) {
            $negative = $negative || $text[0] === '-';
            $text = trim(substr($text, 1));
        }

        $text = (string) preg_replace('/^(?:cop|usd|us)?\s*\$?\s*|\s*(?:cop|usd)$/i', '', $text);

        return self::fromText($text, $style, $negative, $cell);
    }

    private static function fromText(string $text, NumberStyle $style, bool $negative, mixed $original): string
    {
        $fail = fn (): UnreadableCell => new UnreadableCell('No es un valor numérico: «'.(is_scalar($original) ? $original : '').'».');

        if (preg_match('/^[0-9.,]+$/', $text) !== 1) {
            throw $fail();
        }

        $decimal = $style->decimal();
        $thousands = $style->thousands();
        $parts = explode($decimal, $text);

        if (count($parts) > 2) {
            throw $fail();
        }

        $whole = $parts[0];
        $fraction = $parts[1] ?? '';

        $groups = '/^\d{1,3}('.preg_quote($thousands, '/').'\d{3})+$/';

        if (str_contains($whole, $thousands) && preg_match($groups, $whole) !== 1) {
            throw $fail();
        }

        $whole = str_replace($thousands, '', $whole);

        if ($whole === '' || preg_match('/^\d+$/', $whole) !== 1 || (isset($parts[1]) && preg_match('/^\d+$/', $fraction) !== 1)) {
            throw $fail();
        }

        return self::format(ltrim($whole, '0'), $fraction, $negative);
    }

    private static function fromNumber(int|float $value): string
    {
        $text = number_format(abs(round((float) $value, 2)), 2, '.', '');
        [$whole, $fraction] = explode('.', $text);

        return self::format($whole, $fraction, $value < 0);
    }

    /**
     * "1234", "5" => "1234.50": two decimals (rounded half up when there are
     * more), and no sign on zero.
     */
    private static function format(string $whole, string $fraction, bool $negative): string
    {
        $whole = $whole === '' ? '0' : $whole;

        if (strlen($fraction) > 2) {
            $rounded = number_format(round((float) ($whole.'.'.$fraction), 2), 2, '.', '');
            [$whole, $fraction] = explode('.', $rounded);
        }

        $fraction = str_pad($fraction, 2, '0');
        $value = $whole.'.'.$fraction;

        return $negative && $value !== '0.00' ? '-'.$value : $value;
    }

    private static function fromSerial(int|float $serial): ?CarbonImmutable
    {
        if ($serial < self::SERIAL_FROM || $serial > self::SERIAL_TO) {
            return null;
        }

        return self::startOfDay('1899-12-30')->addDays((int) floor($serial));
    }

    private static function startOfDay(string $ymd): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $ymd, self::TIMEZONE) ?: CarbonImmutable::now(self::TIMEZONE)->startOfDay();
    }
}
