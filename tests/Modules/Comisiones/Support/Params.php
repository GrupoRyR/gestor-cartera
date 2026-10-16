<?php

namespace Tests\Modules\Comisiones\Support;

use App\Modules\Comisiones\Domain\Parameters\CodeScope;
use App\Modules\Comisiones\Domain\Parameters\CollectionTranche;
use App\Modules\Comisiones\Domain\Parameters\DerivedBase;
use App\Modules\Comisiones\Domain\Parameters\DerivedCommissionRule;
use App\Modules\Comisiones\Domain\Parameters\DirectorTeam;
use App\Modules\Comisiones\Domain\Parameters\SellerCodeRule;
use App\Modules\Comisiones\Domain\Parameters\TrancheTable;
use App\Modules\Comisiones\Domain\Shared\Percentage;
use App\Modules\Comisiones\Domain\Shared\PersonRef;
use App\Modules\Comisiones\Domain\Shared\Validity;

/**
 * Small builders for commission parameters in tests. Every version is valid
 * from 2026-01-01 and open-ended unless the test says otherwise.
 */
final class Params
{
    /**
     * The §5.3 table: 0–65 100 %, 66–80 90 %, 81–95 80 %, 96–125 50 %, 126+ 0 %.
     *
     * @return list<CollectionTranche>
     */
    public static function standardTranches(): array
    {
        return [
            new CollectionTranche(0, 65, Percentage::of(100)),
            new CollectionTranche(66, 80, Percentage::of(90)),
            new CollectionTranche(81, 95, Percentage::of(80)),
            new CollectionTranche(96, 125, Percentage::of(50)),
            new CollectionTranche(126, null, Percentage::zero()),
        ];
    }

    public static function trancheTable(string $from = '2026-01-01', ?string $until = null): TrancheTable
    {
        return new TrancheTable(Validity::from($from, $until), self::standardTranches());
    }

    public static function rule(
        int $code,
        string $from = '2026-01-01',
        ?string $until = null,
        string $saleRate = '0.75',
        string $collectionRate = '0.75',
        string $minimum = '90',
        CodeScope $scope = CodeScope::Regular,
        ?PersonRef $person = null,
    ): SellerCodeRule {
        return new SellerCodeRule(
            Validity::from($from, $until),
            $code,
            "Código {$code}",
            $person,
            Percentage::of($saleRate),
            Percentage::of($collectionRate),
            Percentage::of($minimum),
            $scope,
        );
    }

    /**
     * @param  list<int>  $members
     */
    public static function team(
        string $key,
        array $members = [14, 15],
        string $rate = '0.2',
        string $from = '2026-01-01',
        ?string $until = null,
        string $channel = 'Distribución',
        ?int $associatedCode = 53,
    ): DirectorTeam {
        return new DirectorTeam(
            Validity::from($from, $until),
            $key,
            $channel,
            new PersonRef('jessika-quintero', 'Jessika Quintero'),
            $associatedCode,
            Percentage::of($rate),
            $members,
        );
    }

    public static function derived(
        string $key,
        string $rate = '0.1',
        string $from = '2026-01-01',
        ?string $until = null,
        int $source = 81,
        DerivedBase $base = DerivedBase::Sales,
        string $label = 'Sergio Caballero (venta)',
    ): DerivedCommissionRule {
        return new DerivedCommissionRule(
            Validity::from($from, $until),
            $key,
            $label,
            new PersonRef('sergio-caballero', 'Sergio Caballero'),
            $source,
            $base,
            Percentage::of($rate),
        );
    }
}
