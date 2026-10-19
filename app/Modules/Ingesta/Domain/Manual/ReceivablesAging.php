<?php

namespace App\Modules\Ingesta\Domain\Manual;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The days and aging columns of a cartera row, computed like
 * etl/queries/cartera.sql so a manual load reads like a robot one: "dias"
 * goes from the due date to the cutoff (positive = overdue); the whole
 * "saldo" falls in one bucket; "saldo_vencido" is the "saldo" itself, as in
 * the query.
 */
final class ReceivablesAging
{
    /** Bucket columns, with the last day each one covers (null = no limit). */
    private const array BUCKETS = [
        'd_0_30' => 30,
        'd_31_60' => 60,
        'd_61_90' => 90,
        'd_91_120' => 120,
        'd_121_150' => 150,
        'd_151_180' => 180,
        'd_mas_180' => null,
    ];

    /** "Rango" of each bucket column, as the query names it. */
    private const array RANGES = [
        'd_0_30' => '0-30',
        'd_31_60' => '31-60',
        'd_61_90' => '61-90',
        'd_91_120' => '91-120',
        'd_121_150' => '121-150',
        'd_151_180' => '151-180',
        'd_mas_180' => '> 180',
    ];

    private const string ZERO = '0.00';

    /**
     * Days from $dueDate to $cutoffDate, both "Y-m-d"; null when the due
     * date is not a real date.
     */
    public static function days(mixed $dueDate, string $cutoffDate): ?int
    {
        if (! is_string($dueDate) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dueDate, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        $utc = new DateTimeZone('UTC');
        $seconds = (new DateTimeImmutable($cutoffDate, $utc))->getTimestamp() - (new DateTimeImmutable($dueDate, $utc))->getTimestamp();

        return intdiv($seconds, 86400);
    }

    /**
     * The derived columns of a row with its "saldo" (a decimal string) and
     * "dias". With an unreadable saldo or no days, every derived amount is
     * null: the validator already rejects the row for its saldo or due date.
     *
     * @return array{dias: ?int, saldo_vencido: ?string, sin_vencer: ?string, d_0_30: ?string, d_31_60: ?string, d_61_90: ?string, d_91_120: ?string, d_121_150: ?string, d_151_180: ?string, d_mas_180: ?string, rango: ?string}
     */
    public static function columns(mixed $saldo, ?int $days): array
    {
        $columns = ['dias' => $days, 'saldo_vencido' => null, 'sin_vencer' => null, 'rango' => null];

        foreach (array_keys(self::BUCKETS) as $bucket) {
            $columns[$bucket] = null;
        }

        if ($days === null || ! is_string($saldo) || preg_match('/^-?\d+\.\d{2}$/', $saldo) !== 1) {
            return $columns;
        }

        $bucket = $days < 0 ? 'sin_vencer' : self::bucket($days);

        $columns['saldo_vencido'] = $saldo;
        $columns['sin_vencer'] = $bucket === 'sin_vencer' ? $saldo : self::ZERO;

        foreach (array_keys(self::BUCKETS) as $name) {
            $columns[$name] = $bucket === $name ? $saldo : self::ZERO;
        }

        $columns['rango'] = $bucket === 'sin_vencer' ? 'Sin vencer' : self::RANGES[$bucket];

        return $columns;
    }

    private static function bucket(int $days): string
    {
        foreach (self::BUCKETS as $name => $lastDay) {
            if ($lastDay === null || $days <= $lastDay) {
                return $name;
            }
        }

        return 'd_mas_180';
    }
}
