<?php

namespace App\Modules\Comisiones\Domain\Calculation;

use App\Modules\Comisiones\Domain\Input\ClientAssignment;
use App\Modules\Comisiones\Domain\Input\CrossingLine;
use App\Modules\Comisiones\Domain\Parameters\Options\CrossingSellerDate;
use App\Modules\Comisiones\Domain\Shared\Money;
use Brick\Math\RoundingMode;

/**
 * Who a crossing belongs to and on which amount it is commissioned (§5.4).
 * SAP does not tie a crossing to a seller: it goes to the seller of the
 * client, and its crossed amount comes with VAT.
 */
final class CrossingResolver
{
    /** @var array<string, list<ClientAssignment>> Each client's history, oldest first. */
    private array $history = [];

    /**
     * @param  list<ClientAssignment>  $assignments  The whole assignment history (Report 5), in any order.
     */
    public function __construct(
        private readonly CrossingSellerDate $sellerDate,
        array $assignments,
        private readonly AlertCollector $alerts,
    ) {
        foreach ($assignments as $assignment) {
            $this->history[$assignment->clientCode][] = $assignment;
        }

        foreach ($this->history as $client => $rows) {
            // usort is stable: of two rows starting the same day, the later one wins.
            usort($rows, fn (ClientAssignment $a, ClientAssignment $b) => $a->effectiveFrom <=> $b->effectiveFrom);
            $this->history[$client] = $rows;
        }
    }

    /**
     * The seller code of the crossing: the one a user set with support, else
     * the seller assigned to the client on the crossing date (or on the
     * invoice date when the options say so, §14.5), that is, the assignment
     * with the latest start on or before that date. Without one the crossing
     * raises ClientWithoutSeller and belongs to nobody.
     */
    public function seller(CrossingLine $line): ?int
    {
        if ($line->sellerOverride !== null) {
            return $line->sellerOverride;
        }

        $date = $this->sellerDate === CrossingSellerDate::InvoiceDate ? $line->invoiceDate : $line->crossingDate;
        $seller = null;

        foreach ($this->history[$line->clientCode] ?? [] as $assignment) {
            if ($assignment->effectiveFrom > $date) {
                break;
            }

            $seller = $assignment->sellerCode;
        }

        if ($seller === null) {
            $this->alerts->add(new Alert(
                AlertKind::ClientWithoutSeller,
                LineFilter::reference($line),
                "El cruce {$line->key} es del cliente {$line->clientCode} ({$line->clientName}), que no tenía vendedor asignado el {$date->format('d/m/Y')}; asígnale uno o corrige el cruce.",
            ));
        }

        return $seller;
    }

    /**
     * The crossed amount without VAT, to cents (half up), in the currency of
     * the line. It is taken, in this order, from:
     * 1. the invoice base (given, or invoice amount − invoice VAT), in
     *    proportion: crossed amount × base / invoice amount;
     * 2. the crossed amount without VAT, when SAP or the user gives it.
     * Without either the crossing raises CrossingWithoutBase and is left out.
     *
     * @param  int|null  $sellerCode  The seller found for the crossing, for the alert.
     */
    public function base(CrossingLine $line, ?int $sellerCode): ?Money
    {
        $taxBase = $line->invoiceTaxBase
            ?? ($line->invoiceVat === null ? null : $line->invoiceAmount->minus($line->invoiceVat));

        if ($taxBase !== null && ! $line->invoiceAmount->isZero()) {
            return Money::of($line->crossedAmount->decimal()
                ->multipliedBy($taxBase->decimal())
                ->dividedBy($line->invoiceAmount->decimal(), 2, RoundingMode::HalfUp));
        }

        if ($line->crossedAmountWithoutVat !== null) {
            return $line->crossedAmountWithoutVat;
        }

        $this->alerts->add(new Alert(
            AlertKind::CrossingWithoutBase,
            LineFilter::reference($line),
            "El cruce {$line->key} no trae la base sin IVA de la factura {$line->invoiceNumber} ni el valor cruzado sin IVA; no se puede liquidar.",
            $sellerCode,
        ));

        return null;
    }
}
