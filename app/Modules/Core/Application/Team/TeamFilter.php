<?php

namespace App\Modules\Core\Application\Team;

/**
 * What the Equipo list shows: the tab, the search and the order. Built from
 * the query string; unknown values fall back to the defaults instead of
 * failing, because they come from links people can edit.
 */
final readonly class TeamFilter
{
    /** The columns the list can be sorted by (the "sort" query parameter). */
    public const array SORTS = ['name', 'email'];

    /**
     * @param  'name'|'email'  $sort
     * @param  'asc'|'desc'  $direction
     */
    public function __construct(
        public TeamTab $tab = TeamTab::Active,
        public string $search = '',
        public string $sort = 'name',
        public string $direction = 'asc',
    ) {}

    /**
     * Reads "estado", "buscar", "sort" and "direction".
     *
     * @param  array<array-key, mixed>  $query
     */
    public static function fromQuery(array $query): self
    {
        $text = fn (string $key): string => is_string($query[$key] ?? null) ? trim($query[$key]) : '';

        $sort = $text('sort');
        $direction = $text('direction');

        return new self(
            tab: TeamTab::tryFrom($text('estado')) ?? TeamTab::Active,
            search: $text('buscar'),
            sort: $sort === 'email' ? 'email' : 'name',
            direction: $direction === 'desc' ? 'desc' : 'asc',
        );
    }

    /**
     * The query string of this filter on $tab (by default, its own tab),
     * without the default values, for links and hidden form fields.
     *
     * @return array<string, string>
     */
    public function query(?TeamTab $tab = null): array
    {
        $tab ??= $this->tab;

        return array_filter([
            'estado' => $tab === TeamTab::Active ? '' : $tab->value,
            'buscar' => $this->search,
            'sort' => $this->sort === 'name' && $this->direction === 'asc' ? '' : $this->sort,
            'direction' => $this->sort === 'name' && $this->direction === 'asc' ? '' : $this->direction,
        ], fn (string $value): bool => $value !== '');
    }
}
