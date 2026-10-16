<?php

namespace App\Modules\Cartera\Application\Commitments;

use App\Modules\Core\Contracts\Data\CommitmentQuery;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * What the user asked of the commitments inbox through the query string.
 * Values it does not know are dropped, never an error.
 *
 * URL names: estado (the tab), asesor, desde and hasta (due dates, Y-m-d),
 * sort and direction (the ones <x-ui.table> writes).
 */
final readonly class CommitmentInboxFilter
{
    public const string DEFAULT_SORT = 'due_date';

    public const string DEFAULT_DIRECTION = 'asc';

    public function __construct(
        public CommitmentTab $tab = CommitmentTab::Pending,
        public ?int $advisorCode = null,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
        public string $sort = self::DEFAULT_SORT,
        public string $direction = self::DEFAULT_DIRECTION,
    ) {}

    /**
     * @param  array<string, mixed>  $input  Usually Request::query().
     */
    public static function fromInput(array $input): self
    {
        $advisor = $input['asesor'] ?? null;
        $tab = $input['estado'] ?? null;
        $sort = $input['sort'] ?? null;
        $direction = $input['direction'] ?? null;

        return new self(
            tab: is_string($tab) ? CommitmentTab::tryFrom($tab) ?? CommitmentTab::Pending : CommitmentTab::Pending,
            advisorCode: is_string($advisor) && ctype_digit($advisor) && strlen($advisor) <= 9 ? (int) $advisor : null,
            from: self::date($input['desde'] ?? null),
            to: self::date($input['hasta'] ?? null),
            sort: is_string($sort) && in_array($sort, CommitmentQuery::SORTS, true) ? $sort : self::DEFAULT_SORT,
            direction: is_string($direction) && in_array($direction, CommitmentQuery::DIRECTIONS, true) ? $direction : self::DEFAULT_DIRECTION,
        );
    }

    public function toQuery(): CommitmentQuery
    {
        return new CommitmentQuery(
            advisorCode: $this->advisorCode,
            statuses: $this->tab->statuses(),
            dueFrom: $this->from,
            dueTo: $this->to,
            sort: $this->sort,
            direction: $this->direction,
        );
    }

    /**
     * Whether the advisor or the due dates narrow the list (the tab and the
     * order do not count).
     */
    public function isFiltered(): bool
    {
        return $this->advisorCode !== null || $this->from !== null || $this->to !== null;
    }

    /**
     * The query string that rebuilds this filter, without the defaults.
     *
     * @param  CommitmentTab|null  $tab  Another tab, to link to it keeping the rest; null keeps this one.
     * @return array<string, string>
     */
    public function query(?CommitmentTab $tab = null): array
    {
        $tab ??= $this->tab;

        return array_filter([
            'estado' => $tab === CommitmentTab::Pending ? null : $tab->value,
            'asesor' => $this->advisorCode === null ? null : (string) $this->advisorCode,
            'desde' => $this->from?->toDateString(),
            'hasta' => $this->to?->toDateString(),
            'sort' => $this->sort === self::DEFAULT_SORT ? null : $this->sort,
            'direction' => $this->direction === self::DEFAULT_DIRECTION ? null : $this->direction,
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (InvalidArgumentException) {
            return null;
        }

        // A date that does not exist, such as 2026-02-31, rolls over to another one.
        return $date instanceof CarbonImmutable && $date->toDateString() === $value ? $date : null;
    }
}
