<?php

namespace App\Modules\Core\Infrastructure\Access;

use App\Models\User;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\Company;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Infrastructure\Persistence\AdvisorRecord;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The part of the portfolio a reader may see (docs/arquitectura.md §6.2):
 * some companies and, within them, every client or only the clients of some
 * SAP advisors.
 *
 * - admin, gerente_cartera and analista_cartera: every client;
 * - asesor: the clients of the advisors linked to them (advisors.user_id);
 * - gerente_comercial: the clients of the advisors in their team
 *   (advisor_team) and of the advisors linked to them;
 * - several roles add up; tesoreria, users without a role and users without
 *   companies (guests, inactive and deleted users) see nothing.
 *
 * A client belongs to an advisor by company and current advisor code
 * (clients.advisor_code): SAP codes repeat across companies.
 */
final readonly class PortfolioScope
{
    /**
     * The roles that see every client of their companies.
     */
    private const array FULL_ACCESS = [Role::Admin, Role::PortfolioManager, Role::PortfolioAnalyst];

    /**
     * @param  list<CompanyCode>  $companies  In CompanyCode order.
     * @param  array<string, list<int>>  $advisorCodes  Codes of the visible advisors by company value; unused with $everyClient.
     */
    private function __construct(
        public array $companies,
        public bool $everyClient,
        private array $advisorCodes = [],
    ) {}

    /**
     * A scope that sees nothing.
     */
    public static function nothing(): self
    {
        return new self([], false);
    }

    /**
     * Every client of $companies, or of every company when it is empty: the
     * scope of CarteraRepository::asSystem().
     *
     * @param  list<CompanyCode>  $companies
     */
    public static function system(array $companies = []): self
    {
        return new self(
            array_values(array_filter(
                CompanyCode::cases(),
                fn (CompanyCode $code): bool => $companies === [] || in_array($code, $companies, true),
            )),
            true,
        );
    }

    /**
     * The scope of $user: the companies of $current->scope() or, when
     * $requested is not empty, those of them $current->available() allows;
     * and the clients the user's roles reach in those companies.
     *
     * @param  list<CompanyCode>  $requested
     */
    public static function forUser(?Authenticatable $user, CurrentCompany $current, array $requested = []): self
    {
        if (! $user instanceof User || $user->getKey() === null) {
            return self::nothing();
        }

        $companies = array_values(array_filter(
            self::codes($requested === [] ? $current->scope() : $current->available()),
            fn (CompanyCode $code): bool => $requested === [] || in_array($code, $requested, true),
        ));

        if ($companies === []) {
            return self::nothing();
        }

        $roles = $user->getRoleNames()->all();

        foreach (self::FULL_ACCESS as $role) {
            if (in_array($role->value, $roles, true)) {
                return new self($companies, true);
            }
        }

        $team = in_array(Role::SalesManager->value, $roles, true);

        if (! $team && ! in_array(Role::SalesAdvisor->value, $roles, true)) {
            return self::nothing();
        }

        $codes = [];

        foreach (self::advisorsOf($user, $companies, $team) as $advisor) {
            $codes[$advisor->company->value][] = $advisor->code;
        }

        return $codes === [] ? self::nothing() : new self($companies, false, $codes);
    }

    /**
     * Whether the scope sees no client at all.
     */
    public function isEmpty(): bool
    {
        return $this->companies === [] || (! $this->everyClient && $this->advisorCodes === []);
    }

    /**
     * Limits a query of clients to the scope.
     *
     * @param  Builder<ClientRecord>  $clients
     * @return Builder<ClientRecord>
     */
    public function clients(Builder $clients): Builder
    {
        return $this->constrain($clients, 'clients.company', 'clients.advisor_code');
    }

    /**
     * Limits a query of SAP advisors to the ones whose clients the scope sees.
     *
     * @param  Builder<AdvisorRecord>  $advisors
     * @return Builder<AdvisorRecord>
     */
    public function advisors(Builder $advisors): Builder
    {
        return $this->constrain($advisors, 'advisors.company', 'advisors.code');
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function constrain(Builder $query, string $companyColumn, string $codeColumn): Builder
    {
        if ($this->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        $query->whereIn($companyColumn, array_map(fn (CompanyCode $code): string => $code->value, $this->companies));

        if (! $this->everyClient) {
            $query->where(function (Builder $visible) use ($companyColumn, $codeColumn): void {
                foreach ($this->advisorCodes as $company => $codes) {
                    $visible->orWhere(fn (Builder $advisor) => $advisor->where($companyColumn, $company)->whereIn($codeColumn, $codes));
                }
            });
        }

        return $query;
    }

    /**
     * The advisors of $companies linked to $user and, with $team, those in
     * their team as a sales manager.
     *
     * @param  list<CompanyCode>  $companies
     * @return iterable<AdvisorRecord>
     */
    private static function advisorsOf(User $user, array $companies, bool $team): iterable
    {
        return AdvisorRecord::query()
            ->whereIn('company', array_map(fn (CompanyCode $code): string => $code->value, $companies))
            ->where(function (Builder $mine) use ($user, $team): void {
                $mine->where('user_id', $user->getKey());

                if ($team) {
                    $mine->orWhereIn('id', fn (QueryBuilder $members) => $members
                        ->select('advisor_id')
                        ->from('advisor_team')
                        ->where('user_id', $user->getKey()));
                }
            })
            ->get(['company', 'code']);
    }

    /**
     * @param  list<Company>  $companies
     * @return list<CompanyCode>
     */
    private static function codes(array $companies): array
    {
        return array_map(fn (Company $company): CompanyCode => $company->code, $companies);
    }
}
