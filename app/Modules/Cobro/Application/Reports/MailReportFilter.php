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
 * SAP group, kind of mail, item status and whether the client replied.
 *
 * The advisor and the group are the client's current ones in Core, so the
 * request turns them into $clientIds: only those clients' items match.
 */
final readonly class MailReportFilter
{
    /**
     * @param  list<CompanyCode>  $companies
     * @param  list<int>|null  $clientIds  Only items of these clients (the advisor's and the group's,
     *                                     both when both are given); null = any client.
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
        public ?string $group = null,
        public ?array $clientIds = null,
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
