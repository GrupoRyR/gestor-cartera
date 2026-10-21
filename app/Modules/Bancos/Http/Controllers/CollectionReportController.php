<?php

namespace App\Modules\Bancos\Http\Controllers;

use App\Modules\Bancos\Application\Access\VisibleCompanies;
use App\Modules\Bancos\Application\Reports\CollectionReports;
use App\Modules\Bancos\Domain\Movement\MovementStatus;
use App\Modules\Bancos\Http\Requests\CollectionReportRequest;
use App\Modules\Bancos\Infrastructure\Persistence\BankMovement;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

/**
 * GET /bancos/recaudo: the daily collections report (what came in on a day,
 * by bank) and the month of that day as the grid the portfolio analyst keeps
 * in her workbook (a row per week, a column per weekday, one table per
 * company). It replaces the sheet she filled in by hand.
 */
final class CollectionReportController
{
    public function __construct(
        private readonly VisibleCompanies $companies,
        private readonly CollectionReports $reports,
    ) {}

    public function index(CollectionReportRequest $request): View
    {
        $date = $request->day();
        $companies = $this->companies->inView();
        $today = CollectionReports::defaultDay()->addDay();

        // The month links land on the last day of the month before and the
        // first of the next one, inside what the request accepts.
        $previousMonth = $date->startOfMonth()->subDay();
        $nextMonth = $date->startOfMonth()->addMonth();

        return view('bancos::recaudo.index', [
            'date' => $date,
            'today' => $today,
            'day' => $this->reports->day($date, $companies),
            'month' => $this->reports->month($date, $companies),
            'previousMonth' => $previousMonth->toDateString() >= CollectionReportRequest::FIRST_DAY ? $previousMonth : null,
            'nextMonth' => $nextMonth->lessThanOrEqualTo($today) ? $nextMonth : null,
            'pending' => $this->pendingOn($date, $companies),
            'otherCurrency' => $this->otherCurrency($date, $companies),
        ]);
    }

    /**
     * How many credits of the day still wait to be applied in SAP.
     *
     * @param  list<CompanyCode>  $companies
     */
    private function pendingOn(CarbonImmutable $date, array $companies): int
    {
        return $this->creditsOn($date, $companies)
            ->where('status', MovementStatus::Pending->value)
            ->where('currency', $this->currency())
            ->count();
    }

    /**
     * Credits of the day in a currency other than pesos: they do not add up
     * and the report says so.
     *
     * @param  list<CompanyCode>  $companies
     */
    private function otherCurrency(CarbonImmutable $date, array $companies): int
    {
        return $this->creditsOn($date, $companies)->where('currency', '!=', $this->currency())->count();
    }

    /**
     * The credits of a day for the companies in view.
     *
     * @param  list<CompanyCode>  $companies
     * @return Builder<BankMovement>
     */
    private function creditsOn(CarbonImmutable $date, array $companies): Builder
    {
        return BankMovement::query()
            ->whereDate('movement_date', $date->toDateString())
            ->whereIn('company', array_map(fn (CompanyCode $company): string => $company->value, $companies));
    }

    private function currency(): string
    {
        return strtoupper((string) config('bancos.collection_currency', 'COP'));
    }
}
