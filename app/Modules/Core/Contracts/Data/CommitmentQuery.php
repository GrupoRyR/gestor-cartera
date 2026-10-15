<?php

namespace App\Modules\Core\Contracts\Data;

use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Filters and order for CompromisoService::paginate().
 *
 * Every filter narrows the result (they combine with AND); empty or null
 * values do not filter. The user scope of CompromisoService always applies
 * on top of these filters.
 */
final readonly class CommitmentQuery
{
    /**
     * Allowed values of $sort: due_date, amount and created_at. Ties are
     * broken by id, ascending.
     *
     * @var list<string>
     */
    public const array SORTS = ['due_date', 'amount', 'created_at'];

    /**
     * @var list<string>
     */
    public const array DIRECTIONS = ['asc', 'desc'];

    /**
     * @param  int|null  $clientId  Only commitments of this client.
     * @param  int|null  $advisorCode  Only commitments whose Commitment::$advisorCode is this one.
     * @param  list<CommitmentStatus>  $statuses  Only these statuses; empty = any status.
     * @param  CarbonImmutable|null  $dueFrom  Only due on or after this date (inclusive).
     * @param  CarbonImmutable|null  $dueTo  Only due on or before this date (inclusive).
     * @param  list<CompanyCode>  $companies  Only these companies, limited to the ones the user may
     *                                        see; empty = CurrentCompany::scope().
     * @param  string  $sort  One of self::SORTS.
     * @param  string  $direction  "asc" or "desc".
     *
     * @throws InvalidArgumentException When $sort or $direction is not allowed.
     */
    public function __construct(
        public ?int $clientId = null,
        public ?int $advisorCode = null,
        public array $statuses = [],
        public ?CarbonImmutable $dueFrom = null,
        public ?CarbonImmutable $dueTo = null,
        public array $companies = [],
        public string $sort = 'due_date',
        public string $direction = 'asc',
    ) {
        if (! in_array($sort, self::SORTS, true)) {
            throw new InvalidArgumentException("Unknown commitment sort [{$sort}]; use one of: ".implode(', ', self::SORTS).'.');
        }

        if (! in_array($direction, self::DIRECTIONS, true)) {
            throw new InvalidArgumentException("Unknown sort direction [{$direction}]; use asc or desc.");
        }
    }
}
