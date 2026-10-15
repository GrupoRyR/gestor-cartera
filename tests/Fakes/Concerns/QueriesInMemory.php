<?php

namespace Tests\Fakes\Concerns;

use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\Company;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use InvalidArgumentException;

/**
 * Helpers shared by the fakes that emulate the company scope and the
 * pagination of the real implementations.
 */
trait QueriesInMemory
{
    /**
     * Companies a query may reach, following the contracts' scope rule:
     * explicit companies are limited to the available ones, otherwise
     * CurrentCompany::scope() applies. Null means nothing restricts it (no
     * CurrentCompany and no explicit companies).
     *
     * @param  list<CompanyCode>  $requested
     * @return list<CompanyCode>|null
     */
    private function companiesInScope(?CurrentCompany $current, array $requested = []): ?array
    {
        if ($current === null) {
            return $requested === [] ? null : $requested;
        }

        if ($requested === []) {
            return $this->companyCodes($current->scope());
        }

        $available = $this->companyCodes($current->available());

        return array_values(array_filter(
            $requested,
            fn (CompanyCode $code): bool => in_array($code, $available, true),
        ));
    }

    /**
     * @param  list<Company>  $companies
     * @return list<CompanyCode>
     */
    private function companyCodes(array $companies): array
    {
        return array_map(fn (Company $company): CompanyCode => $company->code, $companies);
    }

    /**
     * One page of $items, with the current page of the request.
     *
     * @template TItem
     *
     * @param  list<TItem>  $items
     * @return LengthAwarePaginator<int, TItem>
     */
    private function paginateList(array $items, int $perPage): LengthAwarePaginator
    {
        $this->guardAtLeastOne($perPage, 'perPage');

        $page = Paginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            array_slice($items, ($page - 1) * $perPage, $perPage),
            count($items),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );
    }

    private function guardAtLeastOne(int $value, string $name): void
    {
        if ($value < 1) {
            throw new InvalidArgumentException("The {$name} must be at least 1, {$value} given.");
        }
    }
}
