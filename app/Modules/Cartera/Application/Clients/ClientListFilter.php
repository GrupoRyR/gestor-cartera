<?php

namespace App\Modules\Cartera\Application\Clients;

use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use Carbon\CarbonImmutable;

/**
 * What the user asked of the client list (and of its Excel export) through
 * the query string. Values it does not know are dropped, never an error: a
 * stale bookmark still opens the list.
 *
 * URL names: buscar, estado, asesor, vencidos, sin_gestion, sort, direction
 * (the last two are the ones <x-ui.table> writes).
 */
final readonly class ClientListFilter
{
    public const string DEFAULT_SORT = 'overdue';

    public const string DEFAULT_DIRECTION = 'desc';

    private const int SEARCH_MAX_LENGTH = 100;

    public function __construct(
        public string $search = '',
        public ?ClientStatus $status = null,
        public ?int $advisorCode = null,
        public bool $onlyOverdue = false,
        public bool $unmanaged = false,
        public string $sort = self::DEFAULT_SORT,
        public string $direction = self::DEFAULT_DIRECTION,
    ) {}

    /**
     * @param  array<string, mixed>  $input  Usually Request::query().
     */
    public static function fromInput(array $input): self
    {
        $search = is_string($input['buscar'] ?? null) ? mb_substr(trim($input['buscar']), 0, self::SEARCH_MAX_LENGTH) : '';
        $advisor = $input['asesor'] ?? null;
        $sort = $input['sort'] ?? null;
        $direction = $input['direction'] ?? null;

        return new self(
            search: $search,
            status: is_string($input['estado'] ?? null) ? ClientStatus::tryFrom($input['estado']) : null,
            advisorCode: is_string($advisor) && ctype_digit($advisor) && strlen($advisor) <= 9 ? (int) $advisor : null,
            onlyOverdue: self::flag($input['vencidos'] ?? null),
            unmanaged: self::flag($input['sin_gestion'] ?? null),
            sort: is_string($sort) && in_array($sort, ClientQuery::SORTS, true) ? $sort : self::DEFAULT_SORT,
            direction: is_string($direction) && in_array($direction, ClientQuery::DIRECTIONS, true) ? $direction : self::DEFAULT_DIRECTION,
        );
    }

    /**
     * @param  int  $unmanagedDays  Days without a logged action that make an overdue client "unmanaged".
     */
    public function toQuery(CarbonImmutable $today, int $unmanagedDays): ClientQuery
    {
        return new ClientQuery(
            search: $this->search === '' ? null : $this->search,
            statuses: $this->status === null ? [] : [$this->status],
            advisorCode: $this->advisorCode,
            onlyOverdue: $this->onlyOverdue || $this->unmanaged,
            inactiveSince: $this->unmanaged ? $today->subDays($unmanagedDays) : null,
            sort: $this->sort,
            direction: $this->direction,
        );
    }

    /**
     * Whether any filter narrows the list (the order does not count).
     */
    public function isFiltered(): bool
    {
        return $this->search !== ''
            || $this->status !== null
            || $this->advisorCode !== null
            || $this->onlyOverdue
            || $this->unmanaged;
    }

    /**
     * The query string that rebuilds this filter, without the defaults.
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        return array_filter([
            'buscar' => $this->search,
            'estado' => $this->status?->value,
            'asesor' => $this->advisorCode === null ? null : (string) $this->advisorCode,
            'vencidos' => $this->onlyOverdue ? '1' : null,
            'sin_gestion' => $this->unmanaged ? '1' : null,
            'sort' => $this->sort === self::DEFAULT_SORT ? null : $this->sort,
            'direction' => $this->direction === self::DEFAULT_DIRECTION ? null : $this->direction,
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }

    private static function flag(mixed $value): bool
    {
        return $value === '1' || $value === 1 || $value === true || $value === 'on';
    }
}
