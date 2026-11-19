<?php

namespace App\Modules\Core\Infrastructure\Portfolio;

use App\Modules\Core\Domain\Status\ClientStatusRules;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Persistence\InvoiceRecord;
use App\Modules\Core\Infrastructure\Persistence\PortfolioLoadRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * The clients table as it would be if a given snapshot were the latest one:
 * every column current, except balance, overdue and max_days_overdue, taken
 * from that snapshot as RecomputeClientStatuses caches them (ClientPosition).
 * A client without items in the snapshot owes nothing there.
 *
 * The result is aliased "clients", so ClientListing, PortfolioScope and the
 * soft-delete scope work on it unchanged. Portable SQL only.
 */
final class SnapshotClients
{
    /**
     * Columns replaced by the snapshot's figures.
     */
    private const array FIGURES = ['balance', 'overdue', 'max_days_overdue'];

    /**
     * @return Builder<ClientRecord>
     */
    public static function of(PortfolioLoadRecord $snapshot): Builder
    {
        $cents = 'ROUND(invoices.balance * 100)';
        $tolerance = number_format(ClientStatusRules::BALANCE_TOLERANCE, 2, '.', '');

        $figures = InvoiceRecord::query()->toBase()
            ->select('invoices.client_id')
            ->selectRaw("SUM({$cents}) AS balance_cents")
            ->selectRaw("SUM(CASE WHEN invoices.days_overdue > 0 AND invoices.balance > 0 THEN {$cents} ELSE 0 END) AS overdue_cents")
            ->selectRaw("MAX(CASE WHEN invoices.balance > {$tolerance} THEN invoices.days_overdue END) AS max_days")
            ->where('invoices.portfolio_load_id', $snapshot->id)
            ->groupBy('invoices.client_id');

        $current = array_map(
            fn (string $column): string => "clients.{$column}",
            array_values(array_diff(Schema::getColumnListing('clients'), self::FIGURES)),
        );

        $clients = ClientRecord::withTrashed()->toBase()
            ->select($current)
            ->selectRaw('COALESCE(snapshot.balance_cents, 0) / 100.0 AS balance')
            ->selectRaw('COALESCE(snapshot.overdue_cents, 0) / 100.0 AS overdue')
            ->selectRaw('COALESCE(snapshot.max_days, 0) AS max_days_overdue')
            ->leftJoinSub($figures, 'snapshot', 'snapshot.client_id', '=', 'clients.id');

        return ClientRecord::query()->fromSub($clients, 'clients');
    }
}
