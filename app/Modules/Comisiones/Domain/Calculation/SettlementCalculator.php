<?php

namespace App\Modules\Comisiones\Domain\Calculation;

use App\Modules\Comisiones\Domain\Input\SettlementInput;
use App\Modules\Comisiones\Domain\Parameters\PeriodParameters;
use App\Modules\Comisiones\Domain\Shared\Money;
use InvalidArgumentException;

/**
 * Settles one period (§5): filters the SAP lines (§5.1), computes the sales
 * commission (§5.2) and the collection commission, payments and crossings
 * (§5.3, §5.4), of every code, adds up each code (§5.7) and gathers the
 * alerts and controls (§9). Pure and repeatable: the same input and
 * parameters always give the same result.
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

        $codes = [];

        foreach ($parameters->codes() as $code => $rule) {
            $figures = $sales->forCode($code);
            $collection = $collections->forCode($code);

            $settlement = new CodeSettlement(
                rule: $rule,
                sales: $figures->sales,
                budget: $figures->budget,
                compliance: $figures->compliance,
                meetsMinimum: $figures->meetsMinimum,
                saleCommission: $figures->commission,
                collected: $collection->collected,
                collectionCommission: $collection->commission,
                obrCommission: Money::zero(),
                reimbursements: Money::zero(),
                deductions: Money::zero(),
                directorCommission: Money::zero(),
                saleDetails: $figures->details,
                collectionDetails: $collection->details,
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
            derived: [],
            directors: [],
            persons: [],
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
}
