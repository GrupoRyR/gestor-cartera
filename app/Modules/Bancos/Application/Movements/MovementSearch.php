<?php

namespace App\Modules\Bancos\Application\Movements;

use App\Modules\Bancos\Domain\Movement\MovementStatus;
use App\Modules\Bancos\Infrastructure\Persistence\BankMovement;
use App\Modules\Core\Contracts\Enums\Bank;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Illuminate\Database\Eloquent\Builder;

/**
 * The queries behind the movements screens. Every one of them is limited to
 * the companies the caller passes, so a screen cannot show another company's
 * money by forgetting a where.
 */
final class MovementSearch
{
    /**
     * The movements of a bank that match the filter, in its order.
     *
     * @param  list<CompanyCode>  $companies
     * @return Builder<BankMovement>
     */
    public function list(Bank $bank, array $companies, MovementFilter $filter): Builder
    {
        $column = MovementFilter::SORTS[$filter->sort] ?? 'movement_date';
        $direction = $filter->direction === 'asc' ? 'asc' : 'desc';

        return $this->matching($bank, $companies, $filter)
            ->orderBy($column, $direction)
            ->orderBy('id', $direction);
    }

    /**
     * The same movements without order, to add up.
     *
     * @param  list<CompanyCode>  $companies
     * @return Builder<BankMovement>
     */
    public function matching(Bank $bank, array $companies, MovementFilter $filter): Builder
    {
        return BankMovement::query()
            ->where('bank', $bank->value)
            ->whereIn('company', array_map(fn (CompanyCode $company): string => $company->value, $companies))
            ->when($filter->from !== null, fn (Builder $query) => $query->whereDate('movement_date', '>=', $filter->from?->toDateString()))
            ->when($filter->to !== null, fn (Builder $query) => $query->whereDate('movement_date', '<=', $filter->to?->toDateString()))
            ->when($filter->minAmount !== null, fn (Builder $query) => $query->where('amount', '>=', $filter->minAmount))
            ->when($filter->maxAmount !== null, fn (Builder $query) => $query->where('amount', '<=', $filter->maxAmount))
            ->when($filter->status !== null, fn (Builder $query) => $query->where('status', $filter->status?->value))
            ->when($filter->text !== null, function (Builder $query) use ($filter) {
                // % and _ are wildcards of LIKE; a bank reference never needs them.
                $text = '%'.str_replace(['%', '_'], ' ', (string) $filter->text).'%';

                $query->where(fn (Builder $inner) => $inner->where('description', 'like', $text)->orWhere('reference', 'like', $text));
            });
    }

    /**
     * Pending credits per bank (bank value => count), for the tabs.
     *
     * @param  list<CompanyCode>  $companies
     * @return array<string, int>
     */
    public function pendingByBank(array $companies): array
    {
        $rows = BankMovement::query()
            ->toBase()
            ->selectRaw('bank, COUNT(*) as pending')
            ->where('status', MovementStatus::Pending->value)
            ->whereIn('company', array_map(fn (CompanyCode $company): string => $company->value, $companies))
            ->groupBy('bank')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->bank] = (int) $row->pending;
        }

        return $counts;
    }

    /**
     * Pending credits of all banks, for the badge of the menu.
     *
     * @param  list<CompanyCode>  $companies
     */
    public function pendingCount(array $companies): int
    {
        return array_sum($this->pendingByBank($companies));
    }

    /**
     * What the filtered list adds up to, per currency: value, count and how
     * many are still pending.
     *
     * @param  Builder<BankMovement>  $matching  From matching().
     * @return list<array{currency: string, total: string, count: int, pending: int}>
     */
    public function totals(Builder $matching): array
    {
        $rows = $matching->toBase()
            ->selectRaw('currency, SUM(amount) as total, COUNT(*) as movements, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending', [MovementStatus::Pending->value])
            ->groupBy('currency')
            ->orderBy('currency')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[] = [
                'currency' => (string) $row->currency,
                'total' => number_format((float) $row->total, 2, '.', ''),
                'count' => (int) $row->movements,
                'pending' => (int) $row->pending,
            ];
        }

        return $totals;
    }
}
