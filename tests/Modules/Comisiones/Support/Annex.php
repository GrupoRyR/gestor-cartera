<?php

namespace Tests\Modules\Comisiones\Support;

use App\Modules\Comisiones\Domain\Input\PaymentLine;
use App\Modules\Comisiones\Domain\Input\SettlementInput;
use App\Modules\Comisiones\Domain\Shared\Period;

/**
 * Annex 2 data shared by several tests. The spec gives only totals for these
 * cases, so the split into lines is synthetic; the totals are exact.
 */
final class Annex
{
    /**
     * The four payments of code 85 (Annex 2, August 2026): 5,548,738 in all,
     * one of them 200 days after its invoice. Code 85 has no tranches, so it
     * earns 83,231 and 85.1 earns 55,487.
     *
     * @param  string  $month  The month the payments fall in, "2026-08" by default.
     * @return list<PaymentLine>
     */
    public static function code85Payments(string $month = '2026-08'): array
    {
        return [
            Lines::payment(85, 2_000_000, paymentDate: "{$month}-10", days: 10),
            Lines::payment(85, 1_500_000, paymentDate: "{$month}-12", days: 70),
            Lines::payment(85, 1_048_738, paymentDate: "{$month}-18", days: 100),
            Lines::payment(85, 1_000_000, paymentDate: "{$month}-25", days: 200),
        ];
    }

    /**
     * The channel directors of July 2026 (Annex 2). Distribución: sales
     * 1,378,490,620 and collections 1,546,524,797 over codes 14, 16 and 53;
     * Construcción Residencial: sales 395,019,688 and collections 185,904,516
     * over codes 21 and 50; Construcción Institucional: the payments of code
     * 85. Collections are payments at 30 days (85 has no tranches anyway).
     */
    public static function directorsInput(): SettlementInput
    {
        return new SettlementInput(
            Period::of(2026, 7),
            sales: [
                Lines::sale(14, 400_000_000),
                Lines::sale(16, 478_187_568),
                Lines::sale(53, 500_303_052),
                Lines::sale(21, 300_000_000),
                Lines::sale(50, 95_019_688),
            ],
            payments: [
                Lines::payment(14, 500_000_000, paymentDate: '2026-07-20', days: 30),
                Lines::payment(16, 600_000_000, paymentDate: '2026-07-20', days: 30),
                Lines::payment(53, 446_524_797, paymentDate: '2026-07-20', days: 30),
                Lines::payment(21, 100_000_000, paymentDate: '2026-07-20', days: 30),
                Lines::payment(50, 85_904_516, paymentDate: '2026-07-20', days: 30),
                ...self::code85Payments('2026-07'),
            ],
        );
    }
}
