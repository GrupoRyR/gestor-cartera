<?php

namespace App\Modules\Cobro\Http\Controllers;

use App\Modules\Cobro\Http\Requests\MailReportRequest;
use App\Modules\Cobro\Infrastructure\Reports\MailReport;
use App\Modules\Core\Contracts\CurrentCompany;
use Illuminate\Contracts\View\View;

/**
 * GET /cobro/informe (cobro.informe.index): the collection report, item by
 * item, of the weekly collection and circularization mails of the
 * companies in CurrentCompany::scope(), with its figures over every
 * filtered item (MailReport). The clients' advisors and groups come from
 * one read of Core per request (MailReportRequest::clients()).
 */
final class MailReportController
{
    public const int PER_PAGE = 25;

    public function __construct(
        private readonly CurrentCompany $currentCompany,
        private readonly MailReport $report,
    ) {}

    public function index(MailReportRequest $request): View
    {
        $filter = $request->filter();
        $items = $this->report->items($filter, self::PER_PAGE);
        $commitments = $this->report->commitmentWindow($filter);
        $query = array_diff_key($request->query(), ['page' => true]);
        $groups = $request->clients()->groups();
        $companies = [];

        foreach ($this->currentCompany->scope() as $company) {
            $companies[$company->code->value] = $company->name;
        }

        return view('cobro::informe.index', [
            'filter' => $filter,
            'items' => $items,
            'metrics' => $this->report->metrics($filter, $commitments),
            'clients' => $request->clients(),
            'commitments' => $commitments,
            'advisorOptions' => $request->advisorOptions(),
            'groupOptions' => $groups === [] ? null : array_combine($groups, $groups),
            'companyOptions' => count($companies) > 1 ? $companies : null,
            'query' => $query,
            'narrowed' => $query !== [],
        ]);
    }
}
