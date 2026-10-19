<?php

namespace App\Modules\Comisiones\Domain\Shared;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Calendar days. The engine works with whole days only: every date is
 * normalized to midnight UTC, keeping the calendar day it had in its own
 * timezone, so day counts never depend on the time of day or on DST.
 */
final class Dates
{
    private const SECONDS_PER_DAY = 86_400;

    /**
     * The calendar day of a date (or of a "Y-m-d" string) at 00:00 UTC.
     *
     * @throws InvalidArgumentException when a string is not a real "Y-m-d" date.
     */
    public static function day(string|DateTimeImmutable $date): DateTimeImmutable
    {
        $text = $date instanceof DateTimeImmutable ? $date->format('Y-m-d') : $date;
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $text, new DateTimeZone('UTC'));

        if ($day === false || $day->format('Y-m-d') !== $text) {
            throw new InvalidArgumentException("\"{$text}\" is not a valid Y-m-d date.");
        }

        return $day;
    }

    /**
     * Signed number of calendar days from one date to another (§5.3: payment
     * date minus invoice date). 2026-04-28 → 2026-08-05 is 99; reversed, -99.
     */
    public static function daysBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $seconds = self::day($to)->getTimestamp() - self::day($from)->getTimestamp();

        return intdiv($seconds, self::SECONDS_PER_DAY);
    }
}
