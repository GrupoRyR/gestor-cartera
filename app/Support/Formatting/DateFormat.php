<?php

namespace App\Support\Formatting;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Dates as the user interface shows them (<x-ui.date>, exports): DD/MM/AAAA,
 * with a 24-hour time when asked. Values are converted to the application
 * timezone (America/Bogota) first, so a UTC timestamp shows the Colombian
 * calendar day.
 */
final class DateFormat
{
    /**
     * 15/10/2026.
     *
     * @param  DateTimeInterface|string|null  $value  A date object or a string Carbon can parse.
     * @param  string  $fallback  Returned for null and blank strings.
     *
     * @throws InvalidArgumentException When the string is not a date.
     */
    public static function date(DateTimeInterface|string|null $value, string $fallback = '—'): string
    {
        return self::isEmpty($value) ? $fallback : self::toLocal($value)->format('d/m/Y');
    }

    /**
     * 15/10/2026 14:05.
     *
     * @param  DateTimeInterface|string|null  $value  A date object or a string Carbon can parse.
     * @param  string  $fallback  Returned for null and blank strings.
     *
     * @throws InvalidArgumentException When the string is not a date.
     */
    public static function dateTime(DateTimeInterface|string|null $value, string $fallback = '—'): string
    {
        return self::isEmpty($value) ? $fallback : self::toLocal($value)->format('d/m/Y H:i');
    }

    /**
     * Machine-readable value for the datetime attribute of <time>:
     * 2026-10-15, or 2026-10-15T14:05:00-05:00 with the time.
     *
     * @throws InvalidArgumentException When the value is blank or is not a date.
     */
    public static function iso(DateTimeInterface|string $value, bool $withTime = false): string
    {
        if (self::isEmpty($value)) {
            throw new InvalidArgumentException('A blank string is not a date.');
        }

        $local = self::toLocal($value);

        return $withTime ? $local->format(DateTimeInterface::ATOM) : $local->format('Y-m-d');
    }

    /**
     * @phpstan-assert-if-false DateTimeInterface|non-empty-string $value
     */
    private static function isEmpty(DateTimeInterface|string|null $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /**
     * Strings without an offset are read as local time; values with one are
     * converted.
     */
    private static function toLocal(DateTimeInterface|string $value): CarbonImmutable
    {
        $timezone = config()->string('app.timezone');

        $date = $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse(trim($value), $timezone);

        return $date->setTimezone($timezone);
    }
}
