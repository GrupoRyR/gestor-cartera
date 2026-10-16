<?php

namespace App\Modules\Comisiones\Domain\Calculation;

use App\Modules\Comisiones\Domain\Input\AdjustmentKind;
use App\Modules\Comisiones\Domain\Input\ManualAdjustment;
use App\Modules\Comisiones\Domain\Input\ObrSale;
use App\Modules\Comisiones\Domain\Parameters\CodeScope;
use App\Modules\Comisiones\Domain\Parameters\PeriodParameters;
use App\Modules\Comisiones\Domain\Parameters\SellerCodeRule;
use App\Modules\Comisiones\Domain\Shared\Money;

/**
 * The manual captures of a period, per code (§5.5, §5.7):
 *
 *   OBR commission = Σ sold value × rate of the code's OBR/Escudos sales (rounded once)
 *   reimbursements = Σ reimbursements, deductions = Σ deductions
 *
 * Control codes (80, 100) never earn an OBR commission, but their
 * adjustments still apply. A capture of a code without parameters in the
 * period is not applied and raises UnknownSellerCode, once per code.
 */
final class ManualCaptures
{
    /** @var array<int, list<ObrSale>> */
    private array $obrSales = [];

    /** @var array<int, list<ManualAdjustment>> */
    private array $adjustments = [];

    /**
     * @param  list<ObrSale>  $obrSales  OBR/Escudos sales of the period.
     * @param  list<ManualAdjustment>  $adjustments  Reimbursements and deductions of the period.
     */
    public function __construct(
        private readonly PeriodParameters $parameters,
        private readonly AlertCollector $alerts,
        array $obrSales,
        array $adjustments,
    ) {
        foreach ($obrSales as $sale) {
            if ($this->known($sale->sellerCode)) {
                $this->obrSales[$sale->sellerCode][] = $sale;
            }
        }

        foreach ($adjustments as $adjustment) {
            if ($this->known($adjustment->sellerCode)) {
                $this->adjustments[$adjustment->sellerCode][] = $adjustment;
            }
        }
    }

    public function forCode(SellerCodeRule $rule): CaptureFigures
    {
        $obrSales = $this->obrSales[$rule->code] ?? [];
        $adjustments = $this->adjustments[$rule->code] ?? [];

        $obrCommission = $rule->scope === CodeScope::Control
            ? Money::zero()
            : Money::sum(array_map(fn (ObrSale $sale) => $sale->soldValue->times($sale->rate), $obrSales))->rounded();

        return new CaptureFigures(
            $obrCommission,
            self::total($adjustments, AdjustmentKind::Reimbursement),
            self::total($adjustments, AdjustmentKind::Deduction),
            $obrSales,
            $adjustments,
        );
    }

    private function known(int $code): bool
    {
        if ($this->parameters->code($code) !== null) {
            return true;
        }

        $this->alerts->add(new Alert(
            AlertKind::UnknownSellerCode,
            (string) $code,
            "El código {$code} no existe en los parámetros del período; sus capturas manuales no se aplican.",
            $code,
        ));

        return false;
    }

    /**
     * @param  list<ManualAdjustment>  $adjustments
     */
    private static function total(array $adjustments, AdjustmentKind $kind): Money
    {
        return Money::sum(array_map(
            fn (ManualAdjustment $adjustment) => $adjustment->amount,
            array_filter($adjustments, fn (ManualAdjustment $adjustment) => $adjustment->kind === $kind),
        ));
    }
}
