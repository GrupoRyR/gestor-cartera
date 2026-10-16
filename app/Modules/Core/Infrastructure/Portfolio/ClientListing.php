<?php

namespace App\Modules\Core\Infrastructure\Portfolio;

use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The filters and the order of a ClientQuery over the clients table, with
 * the figures cached from the latest snapshot. Portable SQL only (SQLite in
 * local tests, MySQL 8 in CI and production): the company scope is applied
 * apart, by PortfolioScope.
 */
final class ClientListing
{
    /**
     * Escape character of the LIKE patterns of the search.
     */
    private const string LIKE_ESCAPE = '!';

    /**
     * Narrows $clients to the ones that match every filter of $query:
     *
     * - search: a fragment of the name, SAP code or NIT, without regard to
     *   case (% and _ match themselves);
     * - statuses, advisorCode: equal values;
     * - onlyOverdue: a cached overdue balance above zero;
     * - inactiveSince: no activity yet, or the latest one is earlier.
     *
     * @param  Builder<ClientRecord>  $clients
     * @return Builder<ClientRecord>
     */
    public static function filter(Builder $clients, ClientQuery $query): Builder
    {
        $search = trim((string) $query->search);

        if ($search !== '') {
            $pattern = '%'.self::escapeLike(mb_strtolower($search)).'%';

            $clients->where(function (Builder $matches) use ($pattern): void {
                foreach (['clients.name', 'clients.code', 'clients.tax_id'] as $column) {
                    $matches->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '".self::LIKE_ESCAPE."'", [$pattern]);
                }
            });
        }

        if ($query->statuses !== []) {
            $clients->whereIn('clients.status', array_map(fn (ClientStatus $status): string => $status->value, $query->statuses));
        }

        if ($query->advisorCode !== null) {
            $clients->where('clients.advisor_code', $query->advisorCode);
        }

        if ($query->onlyOverdue) {
            $clients->where('clients.overdue', '>', 0);
        }

        if ($query->inactiveSince !== null) {
            // Stored times are in the application time zone (America/Bogota).
            $since = $query->inactiveSince->setTimezone(date_default_timezone_get());

            $clients->where(fn (Builder $inactive) => $inactive
                ->whereNull('clients.last_activity_at')
                ->orWhere('clients.last_activity_at', '<', $since));
        }

        return $clients;
    }

    /**
     * Orders $clients by the sort and direction of $query, then by name
     * (without regard to case) and id, both ascending. days_overdue sorts
     * negative cached values as 0, as ClientSummary shows them; last_activity
     * puts clients without activity first in ascending order.
     *
     * @param  Builder<ClientRecord>  $clients
     * @return Builder<ClientRecord>
     */
    public static function sort(Builder $clients, ClientQuery $query): Builder
    {
        $direction = $query->direction === 'desc' ? 'desc' : 'asc';

        match ($query->sort) {
            'balance' => $clients->orderBy('clients.balance', $direction),
            'overdue' => $clients->orderBy('clients.overdue', $direction),
            'days_overdue' => $clients->orderByRaw("CASE WHEN clients.max_days_overdue > 0 THEN clients.max_days_overdue ELSE 0 END {$direction}"),
            'status' => self::orderByRank($clients, 'clients.status', array_map(fn (ClientStatus $status): string => $status->value, ClientStatus::cases()), $direction),
            'last_activity' => $clients
                ->orderByRaw("CASE WHEN clients.last_activity_at IS NULL THEN 0 ELSE 1 END {$direction}")
                ->orderBy('clients.last_activity_at', $direction),
            default => $clients->orderByRaw("LOWER(clients.name) {$direction}"),
        };

        if ($query->sort !== 'name') {
            $clients->orderByRaw('LOWER(clients.name) asc');
        }

        return $clients->orderBy('clients.id');
    }

    /**
     * Orders $query by the position of $column's value in $values, with a
     * portable CASE.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $values
     * @return Builder<TModel>
     */
    public static function orderByRank(Builder $query, string $column, array $values, string $direction = 'asc'): Builder
    {
        $cases = '';

        foreach (array_keys($values) as $position) {
            $cases .= " WHEN ? THEN {$position}";
        }

        return $query->orderByRaw("CASE {$column}{$cases} END ".($direction === 'desc' ? 'desc' : 'asc'), $values);
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE.self::LIKE_ESCAPE, self::LIKE_ESCAPE.'%', self::LIKE_ESCAPE.'_'],
            $value,
        );
    }
}
