<?php

namespace App\Modules\Asesores\Application\Portfolio;

/**
 * How many days of history a screen shows, chosen among the ranges the module
 * offers.
 */
final class HistoryDays
{
    /**
     * @param  array<array-key, mixed>  $query  The request query string.
     * @param  list<int>  $allowed  The ranges on offer.
     */
    public static function fromQuery(array $query, array $allowed, int $default): int
    {
        $value = $query['dias'] ?? null;

        if (! is_string($value) || preg_match('/^\d{1,4}$/D', $value) !== 1) {
            return $default;
        }

        return in_array((int) $value, $allowed, true) ? (int) $value : $default;
    }
}
