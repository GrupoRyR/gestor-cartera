<?php

namespace App\Modules\Cobro\Application\Reports;

use App\Modules\Cobro\Domain\Runs\ItemStatus;
use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;

/**
 * The filters of the collection report (GET /cobro/informe), already
 * validated by MailReportRequest: the Bogotá calendar days of the runs
 * (both included), the companies to read (the one asked for, or every
 * company in the user's scope), and the optional client text, advisor,
 * kind of mail, item status and whether the client replied.
 */
final readonly class MailReportFilter
{
    /**
     * @param  list<CompanyCode>  $companies
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public array $companies,
        public ?string $client = null,
        public ?CompanyCode $advisorCompany = null,
        public ?int $advisorCode = null,
        public ?RunKind $kind = null,
        public ?ItemStatus $status = null,
        public ?bool $replied = null,
    ) {}

    /**
     * The advisor as the filter writes it, e.g. "SGX:12"; advisor codes
     * are per company.
     */
    public static function advisorKey(CompanyCode $company, int $code): string
    {
        return "{$company->value}:{$code}";
    }
}
