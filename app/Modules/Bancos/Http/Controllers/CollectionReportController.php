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

/**
 * GET /bancos/recaudo: the daily collections report (what came in on a day,
 * by bank) and the weekly accumulated of that day's week. It replaces the
 * sheet the portfolio analyst filled in by hand.
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

        return view('bancos::recaudo.index', [
            'date' => $date,
            'today' => $today,
            'day' => $this->reports->day($date, $companies),
            'week' => $this->reports->week($date, $companies),
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
        return BankMovement::query()
            ->whereDate('movement_date', $date->toDateString())
            ->where('status', MovementStatus::Pending->value)
            ->where('currency', strtoupper((string) config('bancos.collection_currency', 'COP')))
            ->whereIn('company', array_map(fn ($company): string => $company->value, $companies))
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
        return BankMovement::query()
            ->whereDate('movement_date', $date->toDateString())
            ->where('currency', '!=', strtoupper((string) config('bancos.collection_currency', 'COP')))
            ->whereIn('company', array_map(fn ($company): string => $company->value, $companies))
            ->count();
    }
}
