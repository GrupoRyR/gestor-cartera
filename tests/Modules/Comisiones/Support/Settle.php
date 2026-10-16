<?php

namespace Tests\Modules\Comisiones\Support;

use App\Modules\Comisiones\Application\DefaultParameters;
use App\Modules\Comisiones\Domain\Calculation\Alert;
use App\Modules\Comisiones\Domain\Calculation\AlertKind;
use App\Modules\Comisiones\Domain\Calculation\CodeSettlement;
use App\Modules\Comisiones\Domain\Calculation\SettlementCalculator;
use App\Modules\Comisiones\Domain\Calculation\SettlementResult;
use App\Modules\Comisiones\Domain\Input\Budget;
use App\Modules\Comisiones\Domain\Input\SaleLine;
use App\Modules\Comisiones\Domain\Input\SettlementInput;
use App\Modules\Comisiones\Domain\Parameters\OptionsVersion;
use App\Modules\Comisiones\Domain\Parameters\ParameterBook;
use App\Modules\Comisiones\Domain\Parameters\PeriodParameters;
use App\Modules\Comisiones\Domain\Parameters\SettlementOptions;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Domain\Shared\Validity;
use PHPUnit\Framework\Assert;

/**
 * Runs the settlement engine on the default parameters (Annex 1) for the
 * period of the input, optionally answering the open points with options.
 */
final class Settle
{
    public static function run(SettlementInput $input, ?SettlementOptions $options = null): SettlementResult
    {
        return (new SettlementCalculator)->calculate($input, self::parameters($input->period, $options));
    }

    /**
     * A July 2026 settlement with only sales and budgets.
     *
     * @param  list<SaleLine>  $sales
     * @param  list<Budget>  $budgets
     */
    public static function sales(array $sales, array $budgets = [], ?SettlementOptions $options = null): SettlementResult
    {
        return self::run(new SettlementInput(Period::of(2026, 7), sales: $sales, budgets: $budgets), $options);
    }

    /**
     * The settlement of a code, failing the test when the code has none.
     */
    public static function code(SettlementResult $result, int $code): CodeSettlement
    {
        $settlement = $result->code($code);

        Assert::assertNotNull($settlement, "Code {$code} has no settlement.");

        return $settlement;
    }

    /**
     * The references of the alerts of one kind, in order.
     *
     * @return list<string>
     */
    public static function references(SettlementResult $result, AlertKind $kind): array
    {
        return array_map(fn (Alert $alert) => $alert->reference, $result->alertsOf($kind));
    }

    /**
     * The default parameters in force for the period, with the options when given.
     */
    public static function parameters(Period $period, ?SettlementOptions $options = null): PeriodParameters
    {
        $book = DefaultParameters::book();

        if ($options !== null) {
            $book = new ParameterBook(
                codes: $book->codes,
                trancheTables: $book->trancheTables,
                teams: $book->teams,
                derived: $book->derived,
                options: [new OptionsVersion(Validity::from('2026-01-01'), $options)],
            );
        }

        return $book->forPeriod($period);
    }
}
