<?php

namespace App\Modules\Core\Application\Team;

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Infrastructure\Persistence\AdvisorRecord;
use App\Modules\Core\Infrastructure\Persistence\LikePattern;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reads the users of the Equipo list: one tab at a time, searched by name or
 * e-mail, sorted and paginated, with the count of every tab. Also reads the
 * SAP advisors the list and the forms show.
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
     * The SAP advisors linked to each of $users, by user id, in CompanyCode
     * order and then by name, read in one query. Users without advisors are
     * missing from the result.
     *
     * @param  iterable<User>  $users
     * @return array<int, list<AdvisorRecord>>
     */
    public function advisorsOf(iterable $users): array
    {
        $ids = [];

        foreach ($users as $user) {
            $ids[] = $user->id;
        }

        $linked = [];

        foreach ($this->byCompany(AdvisorRecord::query()->whereIn('user_id', $ids)) as $advisors) {
            foreach ($advisors as $advisor) {
                $linked[(int) $advisor->user_id][] = $advisor;
            }
        }

        return $linked;
    }

    /**
     * What the advisor fields of the Equipo forms show: every SAP advisor by
     * company code (CompanyCode order, then name), the name of the user each
     * linked advisor belongs to by user id (deleted users included), and the
     * ids of the advisors linked to $member and in their team (none on the
     * form for a new user).
     *
     * @return array{advisors: array<string, list<AdvisorRecord>>, owners: array<int, string>, linked: list<int>, team: list<int>}
     */
    public function advisorFields(?User $member): array
    {
        $advisors = $this->byCompany(AdvisorRecord::query());
        $ownerIds = [];
        $linked = [];

        foreach ($advisors as $inCompany) {
            foreach ($inCompany as $advisor) {
                if ($advisor->user_id !== null) {
                    $ownerIds[] = $advisor->user_id;
                }

                if ($member !== null && $advisor->user_id === $member->id) {
                    $linked[] = $advisor->id;
                }
            }
        }

        $owners = [];

        foreach ($this->database->table('users')->whereIn('id', array_unique($ownerIds))->get(['id', 'name']) as $row) {
            $owners[(int) $row->id] = (string) $row->name;
        }

        $team = $member === null ? [] : AdvisorRecord::teamOf($member)->pluck('advisors.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        return ['advisors' => $advisors, 'owners' => $owners, 'linked' => $linked, 'team' => $team];
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
            // % and _ match themselves (audit S-35); the e-mail is normalized
            // before it is escaped.
            $query->where(fn (Builder $matches) => $matches
                ->whereRaw(LikePattern::sql('name'), [LikePattern::contains($search)])
                ->orWhereRaw(LikePattern::sql('email'), [LikePattern::contains(User::normalizeEmail($search))]));
        }

        return $query;
    }

    /**
     * The advisors of $query by company code, in CompanyCode order and by
     * name within each company. Companies without advisors are missing.
     *
     * @param  Builder<AdvisorRecord>  $query
     * @return array<string, list<AdvisorRecord>>
     */
    private function byCompany(Builder $query): array
    {
        $advisors = $query->orderBy('name')->orderBy('code')->get();
        $grouped = [];

        foreach (CompanyCode::cases() as $company) {
            foreach ($advisors as $advisor) {
                if ($advisor->company === $company) {
                    $grouped[$company->value][] = $advisor;
                }
            }
        }

        return $grouped;
    }
}
