<?php

namespace App\Modules\Comisiones\Infrastructure\Settlement;

use App\Modules\Comisiones\Application\Ports\SettlementInputs;
use App\Modules\Comisiones\Domain\Input\AdjustmentKind;
use App\Modules\Comisiones\Domain\Input\Budget;
use App\Modules\Comisiones\Domain\Input\ClientAssignment;
use App\Modules\Comisiones\Domain\Input\CrossingLine;
use App\Modules\Comisiones\Domain\Input\CrossingSource;
use App\Modules\Comisiones\Domain\Input\CrossingType;
use App\Modules\Comisiones\Domain\Input\DocumentType;
use App\Modules\Comisiones\Domain\Input\ManualAdjustment;
use App\Modules\Comisiones\Domain\Input\ObrSale;
use App\Modules\Comisiones\Domain\Input\PaymentLine;
use App\Modules\Comisiones\Domain\Input\SaleLine;
use App\Modules\Comisiones\Domain\Input\SettlementInput;
use App\Modules\Comisiones\Domain\Shared\Money;
use App\Modules\Comisiones\Domain\Shared\Percentage;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Infrastructure\Persistence\AdjustmentRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\BudgetRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ClientAssignmentRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\CrossingRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ObrRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\PaymentRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ReassignmentRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\SaleRecord;
use Illuminate\Database\Eloquent\Builder;

/**
 * The period's input from the staging tables and the approved captures
 * (odd/tasks/comisiones-interfaz.md, "Mapping rules decided here"):
 *
 * - sales, payments and crossings dated in the period (the engine would
 *   ignore the others anyway); crossings only when approved (SAP crossings
 *   always are);
 * - a cancelled sale counts, signed, like a credit note: SAP marks the
 *   original "anulado" and sends its cancellation ("anulacion") with the
 *   opposite total. In one month they net to zero; when the cancellation
 *   falls in a later month it reduces that month's sales, so a sale already
 *   paid in a closed month is netted instead of lost (§14.2 "netear");
 * - a payment or crossing without an invoice date takes the date of the
 *   staged invoice (tipo_objeto 13, same doc_entry), else its own date
 *   (0 days);
 * - a crossing's seller override is the latest approved reassignment, else
 *   the seller captured on a manual crossing;
 * - every line is in pesos: the robot sends SAP's local-currency amounts
 *   (etl/queries/ventas.sql, recaudos.sql, cruces.sql: «Montos en moneda
 *   local»), and its moneda/trm columns are the document's own currency and
 *   rate, kept in staging for reference only. Passing them on would make
 *   the engine warn about, or with the «convertir» option multiply by the
 *   TRM, an amount already converted (§14.7);
 * - the whole assignment history, the budgets of the period, and its
 *   approved OBR sales and adjustments.
 */
final class EloquentSettlementInputs implements SettlementInputs
{
    /** The currency of every amount the robot sends: SAP's local currency. */
    private const string PESOS = 'COP';

    /** Already in pesos: nothing to convert. */
    private const string NO_CONVERSION = '1';

    public function forPeriod(Period $period): SettlementInput
    {
        $payments = $this->inPeriod(PaymentRecord::query(), 'fecha_pago', $period)->get();
        $crossings = $this->inPeriod(CrossingRecord::query()->approved(), 'fecha_cruce', $period)->get();
        $invoices = $this->invoices([
            ...$payments->pluck('doc_entry_factura')->all(),
            ...$crossings->pluck('doc_entry_factura')->filter()->all(),
        ]);
        $overrides = $this->overrides($crossings->pluck('clave')->all());

        return new SettlementInput(
            $period,
            sales: $this->inPeriod(SaleRecord::query(), 'fecha_documento', $period)->get()
                ->map(fn (SaleRecord $sale): SaleLine => $this->sale($sale))->all(),
            payments: $payments->map(fn (PaymentRecord $payment): PaymentLine => $this->payment($payment, $invoices))->all(),
            crossings: $crossings->map(fn (CrossingRecord $crossing): CrossingLine => $this->crossing($crossing, $invoices, $overrides))->all(),
            assignments: $this->assignments(),
            budgets: BudgetRecord::query()->where('periodo', $period->key())->orderBy('id')->get()
                ->map(fn (BudgetRecord $budget): Budget => new Budget($budget->slp_code, Money::of($budget->valor)))->all(),
            obrSales: ObrRecord::query()->approved()->where('periodo', $period->key())->orderBy('id')->get()
                ->map(fn (ObrRecord $obr): ObrSale => new ObrSale(
                    $obr->slp_code,
                    $obr->descripcion,
                    Money::of($obr->valor_vendido),
                    Percentage::of($obr->tasa),
                ))->all(),
            adjustments: AdjustmentRecord::query()->approved()->where('periodo', $period->key())->orderBy('id')->get()
                ->map(fn (AdjustmentRecord $adjustment): ManualAdjustment => new ManualAdjustment(
                    $adjustment->slp_code,
                    AdjustmentKind::from($adjustment->tipo),
                    Money::of($adjustment->valor),
                    $adjustment->descripcion,
                ))->all(),
        );
    }

    /**
     * Payments of the period with no invoice date of their own nor a staged
     * invoice: they count from the payment date (0 days). The controls screen
     * shows them as "recaudos sin fecha de factura".
     */
    public function paymentsWithoutInvoiceDate(Period $period): int
    {
        return $this->inPeriod(PaymentRecord::query(), 'fecha_pago', $period)
            ->whereNull('fecha_factura')
            ->whereNotExists(fn ($invoice) => $invoice->selectRaw('1')
                ->from('comisiones_ventas')
                ->where('comisiones_ventas.tipo_objeto', SaleRecord::INVOICE)
                ->whereColumn('comisiones_ventas.doc_entry', 'comisiones_recaudos.doc_entry_factura'))
            ->count();
    }

    private function sale(SaleRecord $sale): SaleLine
    {
        return new SaleLine(
            key: $sale->clave,
            sellerCode: self::sellerCode($sale->slp_code),
            sellerText: self::sellerText($sale->slp_code, $sale->vendedor),
            documentNumber: (string) $sale->numero_documento,
            documentDate: $sale->fecha_documento,
            documentType: DocumentType::tryFrom($sale->clase_documento) ?? DocumentType::Invoice,
            clientCode: $sale->codigo_cliente,
            clientName: $sale->cliente ?? '',
            total: Money::of($sale->total),
            currency: self::PESOS,
            exchangeRate: self::NO_CONVERSION,
            // Never annulled: see the class comment on cancellations.
            annulled: false,
        );
    }

    /**
     * @param  array<int, SaleRecord>  $invoices  Staged invoices by doc_entry.
     */
    private function payment(PaymentRecord $payment, array $invoices): PaymentLine
    {
        $invoice = $invoices[$payment->doc_entry_factura] ?? null;

        return new PaymentLine(
            key: $payment->clave,
            sellerCode: self::sellerCode($payment->slp_code),
            sellerText: self::sellerText($payment->slp_code, $payment->vendedor),
            paymentNumber: (string) $payment->numero_pago,
            paymentDate: $payment->fecha_pago,
            invoiceNumber: (string) ($payment->numero_factura ?? $payment->doc_entry_factura),
            invoiceDate: $payment->fecha_factura ?? $invoice->fecha_documento ?? $payment->fecha_pago,
            clientCode: $payment->codigo_cliente,
            clientName: $payment->nombre_cliente ?? '',
            invoiceAmount: Money::of($payment->valor_factura ?? '0'),
            paidBase: Money::of($payment->valor_pagado_sin_iva),
            currency: self::PESOS,
            exchangeRate: self::NO_CONVERSION,
            annulled: $payment->estado_pago === 'anulado',
        );
    }

    /**
     * @param  array<int, SaleRecord>  $invoices  Staged invoices by doc_entry.
     * @param  array<string, int>  $overrides  Approved reassignment by crossing key.
     */
    private function crossing(CrossingRecord $crossing, array $invoices, array $overrides): CrossingLine
    {
        $invoice = $crossing->doc_entry_factura === null ? null : ($invoices[$crossing->doc_entry_factura] ?? null);

        return new CrossingLine(
            key: $crossing->clave,
            type: self::crossingType($crossing->tipo_cruce),
            source: CrossingSource::from($crossing->origen),
            documentNumber: $crossing->numero_cruce === null ? $crossing->clave : (string) $crossing->numero_cruce,
            crossingDate: $crossing->fecha_cruce,
            invoiceNumber: $crossing->numero_factura ?? (string) ($crossing->doc_entry_factura ?? ''),
            invoiceDate: $crossing->fecha_factura ?? $invoice->fecha_documento ?? $crossing->fecha_cruce,
            clientCode: $crossing->codigo_cliente,
            clientName: $crossing->razon_social ?? '',
            counterparty: $crossing->codigo_proveedor ?? $crossing->nit ?? '',
            invoiceAmount: Money::of($crossing->valor_factura ?? '0'),
            crossedAmount: Money::of($crossing->valor_compensado),
            invoiceTaxBase: self::money($crossing->base_gravable),
            invoiceVat: self::money($crossing->iva_factura),
            crossedAmountWithoutVat: self::money($crossing->valor_compensado_sin_iva),
            sellerOverride: $overrides[$crossing->clave] ?? $crossing->slp_code_capturado,
            currency: self::PESOS,
            exchangeRate: self::NO_CONVERSION,
            annulled: $crossing->estado_cruce === 'anulado',
        );
    }

    /**
     * @return list<ClientAssignment>
     */
    private function assignments(): array
    {
        return ClientAssignmentRecord::query()
            ->orderBy('codigo_cliente')
            ->orderBy('vigente_desde')
            ->get()
            ->map(fn (ClientAssignmentRecord $row): ClientAssignment => new ClientAssignment(
                $row->codigo_cliente,
                $row->slp_code,
                $row->vigente_desde,
            ))
            ->all();
    }

    /**
     * The staged invoices (tipo_objeto 13) with these doc entries, one per entry.
     *
     * @param  list<int|null>  $docEntries
     * @return array<int, SaleRecord>
     */
    private function invoices(array $docEntries): array
    {
        $invoices = [];
        $entries = array_values(array_unique(array_filter($docEntries, fn (?int $entry): bool => $entry !== null)));

        foreach (array_chunk($entries, 500) as $chunk) {
            $rows = SaleRecord::query()
                ->where('tipo_objeto', SaleRecord::INVOICE)
                ->whereIn('doc_entry', $chunk)
                ->orderBy('linea')
                ->get();

            foreach ($rows as $row) {
                $invoices[$row->doc_entry] ??= $row;
            }
        }

        return $invoices;
    }

    /**
     * The seller of the latest approved reassignment of each crossing.
     *
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    private function overrides(array $keys): array
    {
        $overrides = [];

        foreach (array_chunk($keys, 500) as $chunk) {
            $rows = ReassignmentRecord::query()
                ->approved()
                ->whereIn('clave_cruce', $chunk)
                ->orderBy('revisado_en')
                ->orderBy('id')
                ->get();

            foreach ($rows as $row) {
                $overrides[$row->clave_cruce] = $row->slp_code;
            }
        }

        return $overrides;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function inPeriod(Builder $query, string $column, Period $period): Builder
    {
        return $query
            ->whereBetween($column, [$period->start()->format('Y-m-d'), $period->end()->format('Y-m-d')])
            ->orderBy($column)
            ->orderBy('id');
    }

    /** SAP seller codes start at 1; 0 ("oficina") and -1 ("sin vendedor") are none. */
    private static function sellerCode(int $slpCode): ?int
    {
        return $slpCode >= 1 ? $slpCode : null;
    }

    private static function sellerText(int $slpCode, ?string $name): string
    {
        return trim("{$slpCode} ".($name ?? ''));
    }

    private static function crossingType(string $type): CrossingType
    {
        return match (str_replace('-', '_', strtolower(trim($type)))) {
            'anticipo' => CrossingType::Advance,
            'cliente_proveedor' => CrossingType::ClientSupplier,
            default => CrossingType::Other,
        };
    }

    private static function money(?string $amount): ?Money
    {
        return $amount === null ? null : Money::of($amount);
    }
}
