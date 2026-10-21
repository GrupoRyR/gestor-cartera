<?php

namespace App\Modules\Comisiones\Infrastructure\Dashboard;

use App\Modules\Comisiones\Domain\Calculation\CodeSettlement;
use App\Modules\Comisiones\Domain\Calculation\SettlementResult;

/**
 * The compliance-against-budget chart of the dashboard (§7.1; skill
 * dataviz: magnitude as horizontal bars of one hue, the minimum of each code
 * marked on its track and said in words, the table as the source of truth).
 *
 * Only codes with a budget are drawn: without one the compliance is zero by
 * rule (§5.2) and the table says "sin presupuesto". The scale starts at 0 and
 * ends at 120 %, or at the highest compliance (up to 200 %) when it is above,
 * so every bar and minimum share one axis; a compliance beyond 200 % fills
 * the track and its label tells the real value.
 */
final class ComplianceBars
{
    private const float MIN_SCALE = 120.0;

    private const float MAX_SCALE = 200.0;

    /**
     * @param  array<int, CodeSettlement>  $codes
     * @return array{scale: float, bars: list<ComplianceBar>}
     */
    public static function of(array $codes): array
    {
        $budgeted = array_values(array_filter($codes, fn (CodeSettlement $code): bool => $code->hasBudget()));
        $highest = 0.0;

        foreach ($budgeted as $code) {
            $highest = max($highest, (float) $code->compliance->percent());
        }

        $scale = min(self::MAX_SCALE, max(self::MIN_SCALE, ceil($highest / 10) * 10));
        $bars = [];

        foreach ($budgeted as $code) {
            $compliance = (float) $code->compliance->percent();
            $minimum = (float) $code->rule->minimumCompliance->percent();

            $bars[] = new ComplianceBar(
                code: $code,
                width: round(min($compliance, $scale) / $scale * 100, 2),
                minimumAt: $minimum > 0 ? round(min($minimum, $scale) / $scale * 100, 2) : null,
            );
        }

        return ['scale' => $scale, 'bars' => $bars];
    }

    /**
     * The codes the dashboard lists: regular and separate codes with
     * activity. Control codes (80, 100) pay nothing and are left out.
     *
     * @return array<int, CodeSettlement>
     */
    public static function listed(SettlementResult $result): array
    {
        $codes = array_filter(
            $result->regular() + $result->separate(),
            fn (CodeSettlement $code): bool => $code->hasActivity(),
        );
        ksort($codes);

        return $codes;
    }
}
