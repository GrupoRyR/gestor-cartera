<?php

namespace App\Modules\Core\Infrastructure\Portfolio;

use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Persistence\InvoiceRecord;
use Illuminate\Database\Eloquent\Builder;

/**
 * Adds up the invoices of receivables snapshots for a set of clients, in one
 * grouped query of portable SQL: first one row per snapshot and client, then
 * one row per snapshot. Balances are summed as rounded cents, which is exact
 * on MySQL (decimal) and on SQLite (whole numbers well below 2^53).
 *
 * Aging follows Invoice::$daysOverdue only (grace days do not count):
 * notDue <= 0, then 1–30, 31–60, 61–90 and over 90. Overdue is the balance
 * with daysOverdue > 0, credit notes included; a client counts as overdue
 * when that sum is positive.
 */
final class SnapshotTotals
{
    private const string CENTS = 'ROUND(invoices.balance * 100)';

    /**
     * Per-client sums: alias => condition on invoices.days_overdue.
     */
    private const array BUCKETS = [
        'overdue' => 'invoices.days_overdue > 0',
        'not_due' => 'invoices.days_overdue <= 0',
        'days_1_30' => 'invoices.days_overdue BETWEEN 1 AND 30',
        'days_31_60' => 'invoices.days_overdue BETWEEN 31 AND 60',
        'days_61_90' => 'invoices.days_overdue BETWEEN 61 AND 90',
        'over_90' => 'invoices.days_overdue > 90',
    ];

    /**
     * The sums of each snapshot, by snapshot id, for the invoices of
     * $clients. A snapshot without invoices of those clients is missing.
     *
     * @param  list<int>  $snapshotIds
     * @param  Builder<ClientRecord>  $clients  Unsorted; only their ids are read.
     * @return array<int, SnapshotSums>
     */
    public function bySnapshot(array $snapshotIds, Builder $clients): array
    {
        if ($snapshotIds === []) {
            return [];
        }

        $perClient = InvoiceRecord::query()->toBase()
            ->select(['invoices.portfolio_load_id', 'invoices.client_id'])
            ->selectRaw('SUM('.self::CENTS.') AS total');

        foreach (self::BUCKETS as $alias => $condition) {
            $perClient->selectRaw('SUM(CASE WHEN '.$condition.' THEN '.self::CENTS." ELSE 0 END) AS {$alias}");
        }

        $perClient
            ->whereIn('invoices.portfolio_load_id', $snapshotIds)
            ->whereIn('invoices.client_id', (clone $clients)->select('clients.id'))
            ->groupBy(['invoices.portfolio_load_id', 'invoices.client_id']);

        $perSnapshot = $perClient->newQuery()
            ->fromSub($perClient, 'per_client')
            ->select('portfolio_load_id')
            ->selectRaw('COUNT(*) AS client_count')
            ->selectRaw('SUM(CASE WHEN overdue > 0 THEN 1 ELSE 0 END) AS overdue_client_count')
            ->selectRaw('SUM(total) AS total');

        foreach (array_keys(self::BUCKETS) as $alias) {
            $perSnapshot->selectRaw("SUM({$alias}) AS {$alias}");
        }

        $sums = [];

        foreach ($perSnapshot->groupBy('portfolio_load_id')->get() as $row) {
            $sums[(int) $row->portfolio_load_id] = new SnapshotSums(
                total: self::whole($row->total),
                overdue: self::whole($row->overdue),
                notDue: self::whole($row->not_due),
                days1To30: self::whole($row->days_1_30),
                days31To60: self::whole($row->days_31_60),
                days61To90: self::whole($row->days_61_90),
                over90: self::whole($row->over_90),
                clientCount: self::whole($row->client_count),
                overdueClientCount: self::whole($row->overdue_client_count),
            );
        }

        return $sums;
    }

    /**
     * A whole number as the driver returns it: an integer or float (SQLite)
     * or a decimal string (MySQL).
     */
    private static function whole(mixed $value): int
    {
        return is_numeric($value) ? (int) round((float) $value) : 0;
    }
}
