<?php

namespace App\Modules\Comisiones\Domain\Calculation;

use App\Modules\Comisiones\Domain\Parameters\DerivedBase;
use App\Modules\Comisiones\Domain\Parameters\DerivedCommissionRule;
use App\Modules\Comisiones\Domain\Parameters\PeriodParameters;
use App\Modules\Comisiones\Domain\Shared\Money;
use App\Modules\Comisiones\Domain\Shared\Percentage;

/**
 * The commissions derived from another code (§5.5), each one its own row:
 *
 *   81.1: base = sales of code 81
 *   85.1: base = collections of code 85, flat (no tranches)
 *   commission = base × rate, rounded once to the peso
 *
 * The collections are the source code's payments; its crossings count only
 * when the options say so (§14.3; the current Excel leaves them out). A
 * source code without parameters in the period gives a zero base.
 */
final class DerivedCommission
{
    public function __construct(
        private readonly PeriodParameters $parameters,
    ) {}

    /**
     * @return list<DerivedSettlement> One per derived commission in force, in parameter order.
     */
    public function calculate(SalesOutcome $sales, CollectionOutcome $collections): array
    {
        $settlements = [];

        foreach ($this->parameters->derived() as $rule) {
            if ($this->parameters->code($rule->sourceCode) === null) {
                $settlements[] = new DerivedSettlement($rule, Money::zero(), Money::zero());

                continue;
            }

            $settlements[] = match ($rule->base) {
                DerivedBase::Sales => self::settled($rule, $sales->forCode($rule->sourceCode)->sales),
                DerivedBase::Collections => $this->onCollections($rule, $collections->forCode($rule->sourceCode)),
            };
        }

        return $settlements;
    }

    /**
     * The source lines re-rated at the derived rate, at 100 % and without
     * tranches, so the derived report (85.1) adds up to its commission and
     * shows "—" for the days (§8.2).
     */
    private function onCollections(DerivedCommissionRule $rule, CollectionFigures $source): DerivedSettlement
    {
        $details = array_map(fn (CollectionDetail $line) => new CollectionDetail(
            key: $line->key,
            source: $line->source,
            sellerCode: $line->sellerCode,
            invoiceNumber: $line->invoiceNumber,
            clientCode: $line->clientCode,
            clientName: $line->clientName,
            invoiceDate: $line->invoiceDate,
            paymentDate: $line->paymentDate,
            invoiceAmount: $line->invoiceAmount,
            paidBase: $line->paidBase,
            days: $line->days,
            tranched: false,
            share: Percentage::full(),
            rate: $rule->rate,
            commission: $line->paidBase->times($rule->rate),
        ), $source->lines($this->parameters->options()->crossingsInDerivedCollections));

        $base = Money::sum(array_map(fn (CollectionDetail $detail) => $detail->paidBase, $details));

        return self::settled($rule, $base, $details);
    }

    /**
     * @param  list<CollectionDetail>  $details
     */
    private static function settled(DerivedCommissionRule $rule, Money $base, array $details = []): DerivedSettlement
    {
        return new DerivedSettlement($rule, $base, $base->times($rule->rate)->rounded(), $details);
    }
}
