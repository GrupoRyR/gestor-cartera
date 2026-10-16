<?php

namespace App\Modules\Comisiones\Domain\Calculation;

use App\Modules\Comisiones\Domain\Input\Budget;
use App\Modules\Comisiones\Domain\Input\SaleLine;
use App\Modules\Comisiones\Domain\Parameters\CodeScope;
use App\Modules\Comisiones\Domain\Parameters\Options\ComplianceScope;
use App\Modules\Comisiones\Domain\Parameters\Options\CreditNoteTreatment;
use App\Modules\Comisiones\Domain\Parameters\Options\SalesBasis;
use App\Modules\Comisiones\Domain\Parameters\PeriodParameters;
use App\Modules\Comisiones\Domain\Parameters\SellerCodeRule;
use App\Modules\Comisiones\Domain\Shared\Money;
use App\Modules\Comisiones\Domain\Shared\Percentage;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The sales commission of every code (§5.2):
 *
 *   sales      = Σ line amounts of the code (credit notes net unless excluded, §14.2)
 *   compliance = sales / budget (zero without budget; per person when asked, §14.6)
 *   commission = sales × sale rate when sales ≥ budget × minimum, else zero
 *
 * The commission is paid on the whole sales, not on the excess, and is
 * rounded once to the peso. A compliance above 110 % is informative only.
 * Control codes (80, 100) count their sales but never earn a commission.
 */
final class SalesCommission
{
    public function __construct(
        private readonly PeriodParameters $parameters,
        private readonly LineFilter $filter,
        private readonly AlertCollector $alerts,
    ) {}

    /**
     * @param  list<SaleLine>  $lines
     * @param  list<Budget>  $budgets  Budgets of the period; codes without rule are ignored.
     */
    public function calculate(array $lines, array $budgets): SalesOutcome
    {
        $details = [];
        $loaded = Money::zero();
        $unassigned = Money::zero();

        foreach ($this->filter->keep($lines) as $line) {
            if ($this->parameters->options()->creditNotes === CreditNoteTreatment::Exclude && $line->documentType->isCredit()) {
                continue;
            }

            $amount = $this->amount($line);
            $loaded = $loaded->plus($amount);
            $rule = $this->filter->rule($line->sellerCode, $line->sellerText);

            if ($rule === null) {
                $unassigned = $unassigned->plus($amount);

                continue;
            }

            $details[$rule->code][] = self::detail($line, $rule->code, $amount);
        }

        $sales = [];
        $budgetByCode = self::budgets($budgets);

        foreach (array_keys($this->parameters->codes()) as $code) {
            $sales[$code] = Money::sum(array_map(fn (SaleDetail $detail) => $detail->amount, $details[$code] ?? []));
        }

        $codes = [];

        foreach ($this->parameters->codes() as $code => $rule) {
            $codes[$code] = $this->figures($rule, $sales, $budgetByCode, self::sorted($details[$code] ?? []));
        }

        return new SalesOutcome($codes, $loaded, $unassigned);
    }

    /**
     * The line total in pesos, without VAT when the SAP total includes it (§14.2).
     */
    private function amount(SaleLine $line): Money
    {
        $options = $this->parameters->options();
        $amount = $this->filter->pesos($line->total, $line);

        if ($options->salesBasis === SalesBasis::IncludesVat) {
            $divisor = BigDecimal::one()->plus($options->salesVatRate->fraction());
            $amount = Money::of($amount->decimal()->dividedBy($divisor, 2, RoundingMode::HalfUp));
        }

        return $amount;
    }

    /**
     * @param  array<int, Money>  $sales  Sales of every code with a rule.
     * @param  array<int, Money>  $budgets  Budget of every code that has rows.
     * @param  list<SaleDetail>  $details
     */
    private function figures(SellerCodeRule $rule, array $sales, array $budgets, array $details): SalesFigures
    {
        $codeSales = $sales[$rule->code];
        $budget = $budgets[$rule->code] ?? null;
        $hasBudget = $budget !== null && $budget->isPositive();
        [$basisSales, $basisBudget] = $this->basis($rule, $sales, $budgets);

        $meetsMinimum = $rule->minimumCompliance->isZero()
            || ($hasBudget && $basisSales->compareTo($basisBudget->times($rule->minimumCompliance)) >= 0);

        if (! $rule->minimumCompliance->isZero() && ! $hasBudget && ! $codeSales->isZero()) {
            $this->alerts->add(new Alert(
                AlertKind::MissingBudget,
                (string) $rule->code,
                "El código {$rule->code} tiene mínimo de cumplimiento y no tiene presupuesto cargado; su comisión por venta queda en cero.",
                $rule->code,
            ));
        }

        $commission = $meetsMinimum && $rule->scope !== CodeScope::Control
            ? $codeSales->times($rule->saleRate)->rounded()
            : Money::zero();

        return new SalesFigures(
            $codeSales,
            $budget,
            self::compliance($basisSales, $basisBudget),
            $meetsMinimum,
            $commission,
            $details,
        );
    }

    /**
     * The sales and budget the compliance is measured on: the code's own, or,
     * per person (§14.6), the sum over the person's codes that have a budget.
     * Codes without a person always stay per code.
     *
     * @param  array<int, Money>  $sales
     * @param  array<int, Money>  $budgets
     * @return array{Money, Money}
     */
    private function basis(SellerCodeRule $rule, array $sales, array $budgets): array
    {
        $person = $rule->person;

        if ($this->parameters->options()->complianceScope === ComplianceScope::Code || $person === null) {
            $budget = $budgets[$rule->code] ?? Money::zero();

            return [$sales[$rule->code], $budget];
        }

        $personSales = Money::zero();
        $personBudget = Money::zero();

        foreach ($this->parameters->codes() as $code => $other) {
            $budget = $budgets[$code] ?? null;

            if ($other->person?->id !== $person->id || $budget === null || ! $budget->isPositive()) {
                continue;
            }

            $personSales = $personSales->plus($sales[$code]);
            $personBudget = $personBudget->plus($budget);
        }

        return [$personSales, $personBudget];
    }

    /** Sales over budget at scale 6, half up; zero without budget or with negative sales. */
    private static function compliance(Money $sales, Money $budget): Percentage
    {
        if (! $budget->isPositive() || ! $sales->isPositive()) {
            return Percentage::zero();
        }

        return Percentage::fromFraction($sales->decimal()->dividedBy($budget->decimal(), 6, RoundingMode::HalfUp));
    }

    /**
     * @param  list<Budget>  $budgets
     * @return array<int, Money> The sum of the rows of each code.
     */
    private static function budgets(array $budgets): array
    {
        $byCode = [];

        foreach ($budgets as $budget) {
            $byCode[$budget->sellerCode] = ($byCode[$budget->sellerCode] ?? Money::zero())->plus($budget->amount);
        }

        return $byCode;
    }

    private static function detail(SaleLine $line, int $code, Money $amount): SaleDetail
    {
        return new SaleDetail(
            $line->key,
            $code,
            $line->documentNumber,
            $line->documentDate,
            $line->documentType,
            $line->clientCode,
            $line->clientName,
            $line->total,
            $amount,
        );
    }

    /**
     * @param  list<SaleDetail>  $details
     * @return list<SaleDetail> By document date, then key.
     */
    private static function sorted(array $details): array
    {
        usort($details, fn (SaleDetail $a, SaleDetail $b) => [$a->documentDate, $a->key] <=> [$b->documentDate, $b->key]);

        return $details;
    }
}
