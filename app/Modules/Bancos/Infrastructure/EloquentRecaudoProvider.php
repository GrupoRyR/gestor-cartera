<?php

namespace App\Modules\Bancos\Infrastructure;

use App\Modules\Bancos\Domain\Collections\CollectionBuilder;
use App\Modules\Bancos\Domain\Collections\DayBankTotal;
use App\Modules\Bancos\Domain\Statement\Cells;
use App\Modules\Bancos\Infrastructure\Persistence\BankMovement;
use App\Modules\Core\Contracts\Enums\Bank;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\RecaudoProvider;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The collections (recaudo) from the stored bank credits: the sum of every
 * credit by movement date, whatever its status (an ignored credit still
 * reached the bank account, and Treasury's cash flow wants the cash).
 *
 * Only credits in the collection currency (bancos.collection_currency, COP)
 * add up: the figures are pesos and there is no exchange rate to convert a
 * dollar account. Dates are calendar days in America/Bogota, both ends
 * inclusive.
 */
final class EloquentRecaudoProvider implements RecaudoProvider
{
    public function daily(CarbonImmutable $from, CarbonImmutable $to, ?CompanyCode $company = null): array
    {
        return CollectionBuilder::daily($this->totals($from, $to, $company));
    }

    public function weekly(CarbonImmutable $from, CarbonImmutable $to, ?CompanyCode $company = null): array
    {
        return CollectionBuilder::weekly($this->daily($from, $to, $company));
    }

    /**
     * Credits in another currency that fall in the range, which the figures
     * leave out: the report warns about them.
     */
    public function excludedMovements(CarbonImmutable $from, CarbonImmutable $to, ?CompanyCode $company = null): int
    {
        $this->guardRange($from, $to);

        return BankMovement::query()
            ->whereDate('movement_date', '>=', $from->toDateString())
            ->whereDate('movement_date', '<=', $to->toDateString())
            ->where('currency', '!=', $this->currency())
            ->when($company !== null, fn ($query) => $query->where('company', $company?->value))
            ->count();
    }

    /**
     * @return list<DayBankTotal>
     */
    private function totals(CarbonImmutable $from, CarbonImmutable $to, ?CompanyCode $company): array
    {
        $this->guardRange($from, $to);

        $rows = BankMovement::query()
            ->toBase()
            ->selectRaw('DATE(movement_date) as day, company, bank, SUM(amount) as total, COUNT(*) as movements')
            ->whereDate('movement_date', '>=', $from->toDateString())
            ->whereDate('movement_date', '<=', $to->toDateString())
            ->where('currency', $this->currency())
            ->when($company !== null, fn ($query) => $query->where('company', $company?->value))
            ->groupByRaw('DATE(movement_date), company, bank')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', (string) $row->day, Cells::TIMEZONE);
            $companyCode = CompanyCode::tryFrom((string) $row->company);
            $bank = Bank::tryFrom((string) $row->bank);

            if ($date === null || $companyCode === null || $bank === null) {
                continue;
            }

            $totals[] = new DayBankTotal($date, $companyCode, $bank, (int) round(((float) $row->total) * 100), (int) $row->movements);
        }

        return $totals;
    }

    private function currency(): string
    {
        return strtoupper((string) config('bancos.collection_currency', 'COP'));
    }

    private function guardRange(CarbonImmutable $from, CarbonImmutable $to): void
    {
        if ($from->toDateString() > $to->toDateString()) {
            throw new InvalidArgumentException("The range ends ({$to->toDateString()}) before it starts ({$from->toDateString()}).");
        }
    }
}
