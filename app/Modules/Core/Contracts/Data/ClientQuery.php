<?php

namespace App\Modules\Core\Contracts\Data;

use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Filters and order for CarteraRepository client queries.
 *
 * Every filter narrows the result (they combine with AND); empty or null
 * values do not filter. The user scope of CarteraRepository always applies
 * on top of these filters.
 */
final readonly class ClientQuery
{
    /**
     * Allowed values of $sort:
     * - name: client name
     * - balance: ClientSummary::$balance
     * - overdue: ClientSummary::$overdue
     * - days_overdue: ClientSummary::$maxDaysOverdue
     * - status: ClientStatus case order (Kanban order)
     * - last_activity: ClientSummary::$lastActivityAt, clients without activity first in ascending order
     *
     * Ties are broken by client name and then by id, both ascending.
     *
     * @var list<string>
     */
    public const array SORTS = ['name', 'balance', 'overdue', 'days_overdue', 'status', 'last_activity'];

    /**
     * @var list<string>
     */
    public const array DIRECTIONS = ['asc', 'desc'];

    /**
     * @param  string|null  $search  Case-insensitive fragment of the client name or NIT; null or blank = no filter.
     * @param  list<ClientStatus>  $statuses  Only clients in one of these statuses; empty = any status.
     * @param  int|null  $advisorCode  Only clients of this advisor (OSLP.SlpCode).
     * @param  list<CompanyCode>  $companies  Only these companies, limited to the ones the user may
     *                                        see; empty = CurrentCompany::scope().
     * @param  bool  $onlyOverdue  Only clients with ClientSummary::$overdue > 0.
     * @param  CarbonImmutable|null  $inactiveSince  Only clients with no activity since this moment
     *                                               (no activity at all, or the latest one is earlier).
     * @param  string  $sort  One of self::SORTS.
     * @param  string  $direction  "asc" or "desc".
     * @param  int|null  $managerUserId  Only clients whose portfolio manager (ClientSummary::$manager) is this user.
     * @param  bool  $unassigned  Only clients without a portfolio manager.
     *
     * @throws InvalidArgumentException When $sort or $direction is not allowed, or when
     *                                  $managerUserId and $unassigned are both given.
     */
    public function __construct(
        public ?string $search = null,
        public array $statuses = [],
        public ?int $advisorCode = null,
        public array $companies = [],
        public bool $onlyOverdue = false,
        public ?CarbonImmutable $inactiveSince = null,
        public string $sort = 'name',
        public string $direction = 'asc',
        public ?int $managerUserId = null,
        public bool $unassigned = false,
    ) {
        if (! in_array($sort, self::SORTS, true)) {
            throw new InvalidArgumentException("Unknown client sort [{$sort}]; use one of: ".implode(', ', self::SORTS).'.');
        }

        if (! in_array($direction, self::DIRECTIONS, true)) {
            throw new InvalidArgumentException("Unknown sort direction [{$direction}]; use asc or desc.");
        }

        if ($managerUserId !== null && $unassigned) {
            throw new InvalidArgumentException('A client query cannot ask for a manager and for clients without one at once.');
        }
    }
}
