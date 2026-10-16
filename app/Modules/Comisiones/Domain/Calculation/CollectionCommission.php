<?php

namespace App\Modules\Comisiones\Domain\Calculation;

use App\Modules\Comisiones\Domain\Input\ClientAssignment;
use App\Modules\Comisiones\Domain\Input\CrossingLine;
use App\Modules\Comisiones\Domain\Input\PaymentLine;
use App\Modules\Comisiones\Domain\Parameters\CodeScope;
use App\Modules\Comisiones\Domain\Parameters\PeriodParameters;
use App\Modules\Comisiones\Domain\Parameters\SellerCodeRule;
use App\Modules\Comisiones\Domain\Shared\Dates;
use App\Modules\Comisiones\Domain\Shared\Money;
use App\Modules\Comisiones\Domain\Shared\Percentage;

/**
 * The collection commission of every code (§5.3), line by line:
 *
 *   days       = MAX(payment date − invoice date, 0)
 *   share      = the tranche of the days (100 % for codes without tranches, e.g. 85)
 *   commission = paid base without VAT × collection rate × share   (exact)
 *
 * Each partial payment is its own line with its own days. Crossings (§5.4)
 * are collection lines too: the crossing date is the payment date and the
 * crossed amount without VAT is the paid base. The code's commission is the
 * sum of its exact line commissions, rounded once to the peso. Control codes
 * (80, 100) count their collections but never earn a commission.
 */
final class CollectionCommission
{
    public function __construct(
        private readonly PeriodParameters $parameters,
        private readonly LineFilter $filter,
        private readonly AlertCollector $alerts,
    ) {}

    /**
     * @param  list<PaymentLine>  $payments
     * @param  list<CrossingLine>  $crossings
     * @param  list<ClientAssignment>  $assignments  The whole client assignment history.
     */
    public function calculate(array $payments, array $crossings, array $assignments): CollectionOutcome
    {
        $details = [];
        $loaded = Money::zero();
        $unassigned = Money::zero();

        foreach ($this->filter->keep($payments) as $line) {
            $paid = $this->filter->pesos($line->paidBase, $line);
            $loaded = $loaded->plus($paid);
            $rule = $this->filter->rule($line->sellerCode, $line->sellerText);

            if ($rule === null) {
                $unassigned = $unassigned->plus($paid);

                continue;
            }

            $details[$rule->code][] = $this->detail($line, $rule, $paid);
        }

        $resolver = new CrossingResolver($this->parameters->options()->crossingSeller, $assignments, $this->alerts);

        foreach ($this->filter->keep($crossings) as $line) {
            $seller = $resolver->seller($line);
            $base = $resolver->base($line, $seller);

            if ($base === null) {
                continue;
            }

            $paid = $this->filter->pesos($base, $line);
            $loaded = $loaded->plus($paid);
            $rule = $seller === null ? null : $this->filter->rule($seller, '');

            if ($rule === null) {
                $unassigned = $unassigned->plus($paid);

                continue;
            }

            $details[$rule->code][] = $this->detail($line, $rule, $paid);
        }

        $codes = [];

        foreach (array_keys($this->parameters->codes()) as $code) {
            $codes[$code] = self::figures(self::sorted($details[$code] ?? []));
        }

        return new CollectionOutcome($codes, $loaded, $unassigned);
    }

    /**
     * One collection line of a code: its days, tranche share, rate and exact commission.
     *
     * @param  Money  $paid  The paid base without VAT, in pesos.
     */
    private function detail(PaymentLine|CrossingLine $line, SellerCodeRule $rule, Money $paid): CollectionDetail
    {
        $crossing = $line instanceof CrossingLine;
        $days = Dates::daysBetween($line->invoiceDate, $line->periodDate());

        if ($days < 0) {
            $this->alerts->add(new Alert(
                AlertKind::PaymentBeforeInvoice,
                $line->key,
                ($crossing ? 'El cruce' : 'El pago')
                    ." {$line->key} tiene fecha anterior a su factura {$line->invoiceNumber}; se contó con 0 días.",
                $rule->code,
            ));
            $days = 0;
        }

        $share = $rule->usesTranches ? $this->parameters->tranches()->shareFor($days) : Percentage::full();
        $rate = $rule->scope === CodeScope::Control ? Percentage::zero() : $rule->collectionRate;

        return new CollectionDetail(
            key: $line->key,
            source: $crossing ? CollectionSource::Crossing : CollectionSource::Payment,
            sellerCode: $rule->code,
            invoiceNumber: $line->invoiceNumber,
            clientCode: $line->clientCode,
            clientName: $line->clientName,
            invoiceDate: $line->invoiceDate,
            paymentDate: $line->periodDate(),
            invoiceAmount: $this->filter->pesos($line->invoiceAmount, $line),
            paidBase: $paid,
            days: $days,
            tranched: $rule->usesTranches,
            share: $share,
            rate: $rate,
            commission: $paid->times($rate)->times($share),
        );
    }

    /**
     * @param  list<CollectionDetail>  $details
     */
    private static function figures(array $details): CollectionFigures
    {
        return new CollectionFigures(
            Money::sum(array_map(fn (CollectionDetail $detail) => $detail->paidBase, $details)),
            Money::sum(array_map(fn (CollectionDetail $detail) => $detail->commission, $details))->rounded(),
            $details,
        );
    }

    /**
     * @param  list<CollectionDetail>  $details
     * @return list<CollectionDetail> By payment date, then key (PDF order, §8.2).
     */
    private static function sorted(array $details): array
    {
        usort($details, fn (CollectionDetail $a, CollectionDetail $b) => [$a->paymentDate, $a->key] <=> [$b->paymentDate, $b->key]);

        return $details;
    }
}
