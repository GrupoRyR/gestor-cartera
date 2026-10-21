<?php

namespace App\Modules\Comisiones\Http\Support;

use App\Modules\Comisiones\Domain\Shared\Period;
use Carbon\CarbonImmutable;

/**
 * The {periodo} route parameter and the ?periodo= query: a month as
 * "YYYY-MM" (odd/tasks/comisiones-interfaz.md, "Screens and routes").
 *
 * Routes constrain the parameter with PATTERN, so a controller can turn it
 * into a Period without checking it again.
 */
final class PeriodRoute
{
    /** A year of four digits and a month from 01 to 12. */
    public const string PATTERN = '\d{4}-(0[1-9]|1[0-2])';

    /** The {periodo} of a route that matched PATTERN. */
    public static function period(string $key): Period
    {
        return Period::fromString($key);
    }

    /** The month in course, in the application timezone (Bogotá). */
    public static function current(): Period
    {
        $today = CarbonImmutable::now((string) config('app.timezone'));

        return Period::of($today->year, $today->month);
    }

    /**
     * A ?periodo= value, or $default when it is missing or is not a month.
     */
    public static function fromQuery(mixed $value, Period $default): Period
    {
        return is_string($value) && preg_match('/^'.self::PATTERN.'$/', $value) === 1
            ? Period::fromString($value)
            : $default;
    }
}
