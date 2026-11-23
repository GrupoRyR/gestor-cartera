<?php

namespace Tests\Modules\Soportes\Support;

use App\Modules\Core\Contracts\Data\ClientSummary;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Tests\Fakes\FakeCarteraRepository;
use Tests\Fakes\Make;

/**
 * An invented client with overdue invoices, added to the portfolio fake, for
 * the upload tests. Its open invoices:
 *
 * - FV-1001: 45 days overdue, balance 1.200.000;
 * - FV-1002: 10 days overdue, balance 800.000;
 * - FV-1003: not due yet (due in 5 days), balance 500.000;
 * - NC-1001: a credit note, 20 days "overdue" with a negative balance.
 *
 * Build the invoices after travelling in time: their due dates count back
 * from today.
 */
final class OverdueClient
{
    public static function add(
        FakeCarteraRepository $cartera,
        int $advisorCode = 10,
        CompanyCode $company = CompanyCode::SGX,
        string $name = 'Ferretería El Tornillo SAS',
    ): ClientSummary {
        $summary = Make::client(
            company: $company,
            name: $name,
            balance: 2_400_000.0,
            overdue: 2_000_000.0,
            maxDaysOverdue: 45,
            advisor: Make::advisor(company: $company, code: $advisorCode),
        );

        $invoice = fn (string $number, int $days, float $balance, string $type = 'Factura') => Make::invoice(
            company: $company,
            clientId: $summary->id,
            documentNumber: $number,
            documentType: $type,
            amount: abs($balance),
            balance: $balance,
            daysOverdue: $days,
            advisorCode: $advisorCode,
        );

        $cartera->addClient(
            Make::clientDetail($summary),
            $invoice('FV-1001', 45, 1_200_000.0),
            $invoice('FV-1002', 10, 800_000.0),
            $invoice('FV-1003', -5, 500_000.0),
            $invoice('NC-1001', 20, -100_000.0, 'Nota crédito'),
        );

        return $summary;
    }
}
