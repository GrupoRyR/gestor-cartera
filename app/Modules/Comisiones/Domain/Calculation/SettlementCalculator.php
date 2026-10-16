<?php

namespace App\Modules\Comisiones\Domain\Calculation;

use App\Modules\Comisiones\Domain\Input\SettlementInput;
use App\Modules\Comisiones\Domain\Parameters\PeriodParameters;
use App\Modules\Comisiones\Domain\Shared\Money;
use InvalidArgumentException;

/**
 * Settles one period (§5): filters the SAP lines (§5.1), computes the sales
 * commission (§5.2), the collection commission of payments and crossings
 * (§5.3, §5.4), the manual captures and the derived commissions (§5.5) and
 * the channel directors (§5.6) of every code, adds up each code and each
 * person (§5.7) and gathers the alerts and controls (§9). Pure and
 * repeatable: the same input and parameters always give the same result.
 */
final class SettlementCalculator
{
    /**
     * @throws InvalidArgumentException when the parameters are not those of the input period.
     */
    public function calculate(SettlementInput $input, PeriodParameters $parameters): SettlementResult
    {
        if (! $input->period->equals($parameters->period())) {
            throw new InvalidArgumentException(sprintf(
                'The %s input cannot be settled with the parameters of %s.',
                $input->period->key(),
                $parameters->period()->key(),
            ));
        }

        $alerts = new AlertCollector;
        $filter = new LineFilter($parameters, $alerts);
        $sales = (new SalesCommission($parameters, $filter, $alerts))->calculate($input->sales, $input->budgets);
        $collections = (new CollectionCommission($parameters, $filter, $alerts))
            ->calculate($input->payments, $input->crossings, $input->assignments);
        $captures = new ManualCaptures($parameters, $alerts, $input->obrSales, $input->adjustments);
        $directors = (new DirectorCommission($parameters))->calculate($sales, $collections);
        $derived = (new DerivedCommission($parameters))->calculate($sales, $collections);

        $codes = [];

        foreach ($parameters->codes() as $code => $rule) {
            $figures = $sales->forCode($code);
            $collection = $collections->forCode($code);
            $capture = $captures->forCode($rule);

            $settlement = new CodeSettlement(
                rule: $rule,
                sales: $figures->sales,
                budget: $figures->budget,
                compliance: $figures->compliance,
                meetsMinimum: $figures->meetsMinimum,
                saleCommission: $figures->commission,
                collected: $collection->collected,
                collectionCommission: $collection->commission,
                obrCommission: $capture->obrCommission,
                reimbursements: $capture->reimbursements,
                deductions: $capture->deductions,
                directorCommission: self::directorCommissionOf($code, $directors),
                saleDetails: $figures->details,
                collectionDetails: $collection->details,
                obrSales: $capture->obrSales,
                adjustments: $capture->adjustments,
            );

            if ($settlement->hasNegativeCommission()) {
                $alerts->add(new Alert(
                    AlertKind::NegativeCommission,
                    (string) $code,
                    "El código {$code} quedó con comisión negativa; revísala antes de aprobar.",
                    $code,
                ));
            }

            $codes[$code] = $settlement;
        }

        return new SettlementResult(
            period: $input->period,
            parameters: $parameters,
            codes: $codes,
            derived: $derived,
            directors: $directors,
            persons: PersonTotals::calculate($codes, $derived, $directors),
            alerts: $alerts->all(),
            controls: new ControlTotals(
                loadedSales: $sales->loaded,
                salesByCode: $sales->byCode(),
                unassignedSales: $sales->unassigned,
                loadedCollections: $collections->loaded,
                settledCollections: $collections->settled(),
                unassignedCollections: $collections->unassigned,
            ),
        );
    }

    /**
     * The director commissions the options place on this code (§14.4); zero
     * when the directors are paid apart.
     *
     * @param  list<DirectorSettlement>  $directors
     */
    private static function directorCommissionOf(int $code, array $directors): Money
    {
        return Money::sum(array_map(
            fn (DirectorSettlement $director) => $director->total,
            array_filter($directors, fn (DirectorSettlement $director) => $director->paidWithCode === $code),
        ));
    }
}
