<?php

namespace App\Modules\Asesores\Application\Portfolio;

use App\Modules\Asesores\Domain\Portfolio\AdvisorKey;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Enums\ClientStatus;

/**
 * What the user asked for in the query string of the client screens: an
 * advisor, a search, a status, only overdue clients and the order.
 *
 * Whatever does not read as a valid value is dropped, so a tampered URL shows
 * the default view instead of an error. Nothing here widens access: the
 * portfolio scope of Core applies on top of any filter, and an advisor key of
 * somebody else's portfolio just matches no client.
 */
final readonly class PortfolioFilter
{
    public const string DEFAULT_SORT = 'overdue';

    public const string DEFAULT_DIRECTION = 'desc';

    private const int MAX_SEARCH = 100;

    public function __construct(
        public ?AdvisorKey $advisor = null,
        public string $search = '',
        public ?ClientStatus $status = null,
        public bool $onlyOverdue = false,
        public string $sort = self::DEFAULT_SORT,
        public string $direction = self::DEFAULT_DIRECTION,
    ) {}

    /**
     * @param  array<array-key, mixed>  $query  The request query string.
     */
    public static function fromQuery(array $query): self
    {
        $sort = self::text($query, 'sort');
        $direction = self::text($query, 'direction');

        return new self(
            advisor: AdvisorKey::parse(self::text($query, 'asesor')),
            search: mb_substr(trim(self::text($query, 'buscar') ?? ''), 0, self::MAX_SEARCH),
            status: ClientStatus::tryFrom(self::text($query, 'estado') ?? ''),
            onlyOverdue: self::text($query, 'vencidos') === '1',
            sort: in_array($sort, ClientQuery::SORTS, true) ? $sort : self::DEFAULT_SORT,
            direction: in_array($direction, ClientQuery::DIRECTIONS, true) ? $direction : self::DEFAULT_DIRECTION,
        );
    }

    /**
     * The client list: every filter and the order.
     */
    public function clientQuery(): ClientQuery
    {
        return new ClientQuery(
            search: $this->search === '' ? null : $this->search,
            statuses: $this->status === null ? [] : [$this->status],
            advisorCode: $this->advisor?->code,
            companies: $this->advisor === null ? [] : [$this->advisor->company],
            onlyOverdue: $this->onlyOverdue,
            sort: $this->sort,
            direction: $this->direction,
        );
    }

    /**
     * The portfolio of the chosen advisor, for the figures, the age chart and
     * the history, which ignore the other filters.
     */
    public function advisorQuery(): ClientQuery
    {
        return new ClientQuery(
            advisorCode: $this->advisor?->code,
            companies: $this->advisor === null ? [] : [$this->advisor->company],
        );
    }

    /**
     * Query-string parameters of a link that keeps this filter. A null in
     * $changes removes the parameter; the defaults are never written.
     *
     * @param  array<string, string|null>  $changes
     * @return array<string, string>
     */
    public function params(array $changes = []): array
    {
        $params = array_filter([
            'asesor' => $this->advisor?->value(),
            'buscar' => $this->search === '' ? null : $this->search,
            'estado' => $this->status?->value,
            'vencidos' => $this->onlyOverdue ? '1' : null,
            'sort' => $this->sort === self::DEFAULT_SORT ? null : $this->sort,
            'direction' => $this->direction === self::DEFAULT_DIRECTION ? null : $this->direction,
        ], fn (?string $value): bool => $value !== null);

        foreach ($changes as $name => $value) {
            if ($value === null) {
                unset($params[$name]);
            } else {
                $params[$name] = $value;
            }
        }

        return $params;
    }

    /**
     * @param  array<array-key, mixed>  $query
     */
    private static function text(array $query, string $key): ?string
    {
        $value = $query[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
