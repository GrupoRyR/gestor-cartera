<?php

namespace Tests\Modules\Comisiones\Support;

use App\Modules\Comisiones\Domain\Input\Budget;
use App\Modules\Comisiones\Domain\Input\CrossingLine;
use App\Modules\Comisiones\Domain\Input\CrossingSource;
use App\Modules\Comisiones\Domain\Input\CrossingType;
use App\Modules\Comisiones\Domain\Input\DocumentType;
use App\Modules\Comisiones\Domain\Input\PaymentLine;
use App\Modules\Comisiones\Domain\Input\SaleLine;
use App\Modules\Comisiones\Domain\Shared\Dates;
use App\Modules\Comisiones\Domain\Shared\Money;
use App\Modules\Comisiones\Domain\Shared\SellerCode;

/**
 * Small builders for the SAP lines and manual captures a settlement reads.
 * Every line gets its own document number and key unless the test gives one.
 *
 * An int seller is the code, reported by SAP as "{code} VENDEDOR"; a string
 * seller is the SAP text and the code is read from it the way the
 * infrastructure does ("SIN ASESOR" → no code).
 */
final class Lines
{
    private static int $sequence = 0;

    /** A sales line (Report 1); $total is signed (credit notes negative). */
    public static function sale(
        int|string $seller,
        int|string $total,
        string $date = '2026-07-15',
        ?string $key = null,
        DocumentType $type = DocumentType::Invoice,
        string $currency = 'COP',
        string $exchangeRate = '1',
        bool $annulled = false,
        string $client = 'C001',
    ): SaleLine {
        $number = 'F'.(++self::$sequence);

        return new SaleLine(
            key: $key ?? "{$number}-1",
            sellerCode: self::code($seller),
            sellerText: self::text($seller),
            documentNumber: $number,
            documentDate: Dates::day($date),
            documentType: $type,
            clientCode: $client,
            clientName: "Cliente {$client}",
            total: Money::of($total),
            currency: $currency,
            exchangeRate: $exchangeRate,
            annulled: $annulled,
        );
    }

    /** A payment applied to an invoice (Report 2); $paidBase is already without VAT. */
    public static function payment(
        int|string $seller,
        int|string $paidBase,
        string $paymentDate = '2026-08-05',
        string $invoiceDate = '2026-07-01',
        ?string $key = null,
        int|string|null $invoiceAmount = null,
        string $currency = 'COP',
        string $exchangeRate = '1',
        bool $annulled = false,
        string $client = 'C001',
    ): PaymentLine {
        $number = 'P'.(++self::$sequence);
        $invoice = 'FV'.self::$sequence;

        return new PaymentLine(
            key: $key ?? "{$number}-{$invoice}-1",
            sellerCode: self::code($seller),
            sellerText: self::text($seller),
            paymentNumber: $number,
            paymentDate: Dates::day($paymentDate),
            invoiceNumber: $invoice,
            invoiceDate: Dates::day($invoiceDate),
            clientCode: $client,
            clientName: "Cliente {$client}",
            invoiceAmount: Money::of($invoiceAmount ?? $paidBase),
            paidBase: Money::of($paidBase),
            currency: $currency,
            exchangeRate: $exchangeRate,
            annulled: $annulled,
        );
    }

    /** An advance or client-supplier crossing (Report 4); $crossed includes VAT. */
    public static function crossing(
        int|string $crossed,
        string $crossingDate = '2026-09-16',
        string $invoiceDate = '2026-08-19',
        ?string $key = null,
        int|string|null $invoiceAmount = null,
        int|string|null $taxBase = null,
        ?int $sellerOverride = null,
        string $currency = 'COP',
        string $exchangeRate = '1',
        bool $annulled = false,
        string $client = 'C001',
    ): CrossingLine {
        $number = 'CR'.(++self::$sequence);

        return new CrossingLine(
            key: $key ?? $number,
            type: CrossingType::Advance,
            source: CrossingSource::Sap,
            documentNumber: $number,
            crossingDate: Dates::day($crossingDate),
            invoiceNumber: 'FV'.self::$sequence,
            invoiceDate: Dates::day($invoiceDate),
            clientCode: $client,
            clientName: "Cliente {$client}",
            counterparty: 'Proveedor',
            invoiceAmount: Money::of($invoiceAmount ?? $crossed),
            crossedAmount: Money::of($crossed),
            invoiceTaxBase: $taxBase === null ? null : Money::of($taxBase),
            sellerOverride: $sellerOverride,
            currency: $currency,
            exchangeRate: $exchangeRate,
            annulled: $annulled,
        );
    }

    public static function budget(int $code, int|string $amount): Budget
    {
        return new Budget($code, Money::of($amount));
    }

    private static function code(int|string $seller): ?int
    {
        return is_int($seller) ? $seller : SellerCode::fromSapText($seller);
    }

    private static function text(int|string $seller): string
    {
        return is_int($seller) ? "{$seller} VENDEDOR" : $seller;
    }
}
