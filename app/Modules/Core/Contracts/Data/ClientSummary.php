<?php

namespace App\Modules\Core\Contracts\Data;

use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;

/**
 * One client in lists, the Kanban and exports, as of the latest receivables
 * snapshot of its company.
 *
 * Amounts are Colombian pesos, for display and aggregation only.
 */
final readonly class ClientSummary
{
    /**
     * @param  int  $id  Platform id of the client (unique across companies).
     * @param  string  $code  SAP business partner code (OCRD.CardCode).
     * @param  string  $name  Business name.
     * @param  string  $taxId  NIT as loaded from SAP (digits, without the check digit when SAP has none).
     * @param  float  $balance  Sum of open invoice balances.
     * @param  float  $overdue  Sum of balances of invoices with Invoice::$daysOverdue > 0.
     * @param  int  $maxDaysOverdue  Highest Invoice::$daysOverdue among open invoices; 0 when none is overdue.
     * @param  Advisor|null  $advisor  Sales advisor in charge of the client in SAP.
     * @param  UserRef|null  $manager  Collector in charge (gestor responsable) inside the platform.
     * @param  CarbonImmutable|null  $lastActivityAt  Latest non-system activity log entry; null when there is none.
     */
    public function __construct(
        public int $id,
        public CompanyCode $company,
        public string $code,
        public string $name,
        public string $taxId,
        public ClientStatus $status,
        public float $balance,
        public float $overdue,
        public int $maxDaysOverdue,
        public ?Advisor $advisor = null,
        public ?UserRef $manager = null,
        public ?CarbonImmutable $lastActivityAt = null,
    ) {}
}
