<?php

namespace App\Modules\Core\Application\Team;

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reads the users of the Equipo list: one tab at a time, searched by name or
 * e-mail, sorted and paginated, with the count of every tab.
 */
final class TeamDirectory
{
    public const int PER_PAGE = 25;

    public function __construct(private readonly ConnectionInterface $database) {}

    /**
     * The page of users of the filter, with their roles loaded (strict
     * models forbid lazy loading in the table). Their companies come from
     * companiesOf().
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function page(TeamFilter $filter): LengthAwarePaginator
    {
        return $this->query($filter->tab, $filter->search)
            ->with('roles')
            ->orderBy($filter->sort, $filter->direction)
            ->orderBy('id')
            ->paginate(self::PER_PAGE);
    }

    /**
     * The companies assigned to each of $users (company_user), by user id,
     * in CompanyCode order, read in one query. Users without companies are
     * missing from the result.
     *
     * @param  iterable<User>  $users
     * @return array<int, list<CompanyCode>>
     */
    public function companiesOf(iterable $users): array
    {
        $ids = [];

        foreach ($users as $user) {
            $ids[] = $user->id;
        }

        $codes = [];

        foreach ($this->database->table('company_user')->whereIn('user_id', $ids)->get(['user_id', 'company']) as $row) {
            $codes[(int) $row->user_id][] = (string) $row->company;
        }

        return array_map(
            fn (array $assigned): array => array_values(array_filter(
                CompanyCode::cases(),
                fn (CompanyCode $code): bool => in_array($code->value, $assigned, true),
            )),
            $codes,
        );
    }

    /**
     * How many users each tab holds for the search, by tab value.
     *
     * @return array<string, int>
     */
    public function counts(string $search): array
    {
        $counts = [];

        foreach (TeamTab::cases() as $tab) {
            $counts[$tab->value] = $this->query($tab, $search)->count();
        }

        return $counts;
    }

    /**
     * @return Builder<User>
     */
    private function query(TeamTab $tab, string $search): Builder
    {
        $query = match ($tab) {
            TeamTab::Active => User::query()->where('is_active', true)->whereHas('roles'),
            TeamTab::WithoutRole => User::query()->where('is_active', true)->whereDoesntHave('roles'),
            TeamTab::Inactive => User::query()->where('is_active', false),
            TeamTab::Deleted => User::onlyTrashed(),
        };

        if ($search !== '') {
            $query->where(fn (Builder $matches) => $matches
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', '%'.User::normalizeEmail($search).'%'));
        }

        return $query;
    }
}
