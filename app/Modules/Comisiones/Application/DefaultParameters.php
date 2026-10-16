<?php

namespace App\Modules\Comisiones\Application;

use App\Modules\Comisiones\Domain\Parameters\CodeScope;
use App\Modules\Comisiones\Domain\Parameters\CollectionTranche;
use App\Modules\Comisiones\Domain\Parameters\DerivedBase;
use App\Modules\Comisiones\Domain\Parameters\DerivedCommissionRule;
use App\Modules\Comisiones\Domain\Parameters\DirectorTeam;
use App\Modules\Comisiones\Domain\Parameters\ParameterBook;
use App\Modules\Comisiones\Domain\Parameters\SellerCodeRule;
use App\Modules\Comisiones\Domain\Parameters\TrancheTable;
use App\Modules\Comisiones\Domain\Shared\Percentage;
use App\Modules\Comisiones\Domain\Shared\PersonRef;
use App\Modules\Comisiones\Domain\Shared\Validity;
use DateTimeImmutable;

/**
 * The initial commission parameters: Annex 1 (codes), §5.3 (tranches),
 * §5.5 (special cases) and §5.6 (director teams). The infrastructure session
 * seeds them once; afterwards users edit them as new versions.
 */
final class DefaultParameters
{
    /** Codes on the general rule: 0.75 % sales and collections, 90 % minimum. */
    private const GENERAL = [14, 15, 17, 18, 23, 32, 33, 36, 41, 42, 43, 51, 52, 61, 66, 82, 86];

    /** Codes on the general rates without a minimum (§5.2). */
    private const NO_MINIMUM = [34, 44, 69, 67];

    /** Names of the people known from the specification, by person id. */
    private const PEOPLE = [
        'monica-rivera' => 'Mónica Rivera',
        'paola-vargas' => 'Paola Vargas',
        'carolina-ramirez' => 'Carolina Ramírez',
        'harold-marin' => 'Harold Marín',
        'jenny-rocio-buitrago' => 'Jenny Rocío Buitrago',
        'adriana-russi' => 'Adriana Russi',
        'jessika-quintero' => 'Jessika Quintero',
        'andrea-hincapie' => 'Andrea Hincapié',
        'liliam-herrera' => 'Liliam Herrera',
        'anderson-avendano' => 'Anderson Avendaño',
        'sergio-caballero' => 'Sergio Caballero',
    ];

    /** The person of each code that has one (§4, §5.5, §5.6); other codes are paid as themselves. */
    private const CODE_PEOPLE = [
        14 => 'monica-rivera',
        15 => 'paola-vargas',
        16 => 'carolina-ramirez',
        32 => 'harold-marin',
        34 => 'harold-marin',
        41 => 'jenny-rocio-buitrago',
        44 => 'jenny-rocio-buitrago',
        42 => 'adriana-russi',
        82 => 'adriana-russi',
        53 => 'jessika-quintero',
        84 => 'jessika-quintero',
        50 => 'andrea-hincapie',
        81 => 'andrea-hincapie',
        85 => 'liliam-herrera',
        90 => 'anderson-avendano',
    ];

    /** Names of codes without a person. */
    private const CODE_NAMES = [
        80 => 'Oficina – Varios',
        100 => 'Otros ingresos',
    ];

    /**
     * Every version valid from $from, open-ended.
     */
    public static function book(string|DateTimeImmutable $from = '2026-01-01'): ParameterBook
    {
        $validity = Validity::from($from);

        return new ParameterBook(
            codes: self::codes($validity),
            trancheTables: [self::tranches($validity)],
            teams: self::teams($validity),
            derived: self::derived($validity),
        );
    }

    /**
     * @return list<SellerCodeRule>
     */
    private static function codes(Validity $validity): array
    {
        $rules = [];

        foreach (self::GENERAL as $code) {
            $rules[] = self::rule($validity, $code, '0.75', '0.75', '90', CodeScope::Regular);
        }

        $rules[] = self::rule($validity, 16, '1.25', '1.25', '80', CodeScope::Regular);
        $rules[] = self::rule($validity, 21, '1.25', '1.25', '90', CodeScope::Regular);

        foreach (self::NO_MINIMUM as $code) {
            $rules[] = self::rule($validity, $code, '0.75', '0.75', '0', CodeScope::Regular);
        }

        // Sales only, no budget (§5.5).
        $rules[] = self::rule($validity, 50, '1', '0', '0', CodeScope::Regular);
        $rules[] = self::rule($validity, 53, '1', '0', '0', CodeScope::Regular);

        // Collections only, full rate whatever the days (§5.3).
        $rules[] = self::rule($validity, 85, '0', '1.5', '0', CodeScope::Regular, usesTranches: false);

        // Channel directors settled apart, sales only (§5.5).
        $rules[] = self::rule($validity, 81, '0.35', '0', '0', CodeScope::Separate);
        $rules[] = self::rule($validity, 84, '1', '0', '0', CodeScope::Separate);
        $rules[] = self::rule($validity, 90, '2', '0', '0', CodeScope::Separate);

        // Loaded only to reconcile sales with SAP (§5.3).
        $rules[] = self::rule($validity, 80, '0', '0', '0', CodeScope::Control);
        $rules[] = self::rule($validity, 100, '0', '0', '0', CodeScope::Control);

        return $rules;
    }

    private static function rule(
        Validity $validity,
        int $code,
        string $saleRate,
        string $collectionRate,
        string $minimum,
        CodeScope $scope,
        bool $usesTranches = true,
    ): SellerCodeRule {
        $person = isset(self::CODE_PEOPLE[$code]) ? self::person(self::CODE_PEOPLE[$code]) : null;

        return new SellerCodeRule(
            $validity,
            $code,
            $person->name ?? self::CODE_NAMES[$code] ?? "Código {$code}",
            $person,
            Percentage::of($saleRate),
            Percentage::of($collectionRate),
            Percentage::of($minimum),
            $scope,
            $usesTranches,
        );
    }

    /** §5.3: 0–65 days 100 %, 66–80 90 %, 81–95 80 %, 96–125 50 %, 126 or more 0 %. */
    private static function tranches(Validity $validity): TrancheTable
    {
        return new TrancheTable($validity, [
            new CollectionTranche(0, 65, Percentage::of(100)),
            new CollectionTranche(66, 80, Percentage::of(90)),
            new CollectionTranche(81, 95, Percentage::of(80)),
            new CollectionTranche(96, 125, Percentage::of(50)),
            new CollectionTranche(126, null, Percentage::zero()),
        ]);
    }

    /**
     * @return list<DirectorTeam>
     */
    private static function teams(Validity $validity): array
    {
        return [
            new DirectorTeam(
                $validity,
                'distribucion',
                'Distribución',
                self::person('jessika-quintero'),
                53,
                Percentage::of('0.2'),
                [14, 15, 16, 17, 18, 23, 32, 34, 41, 43, 51, 53, 67, 69],
            ),
            new DirectorTeam(
                $validity,
                'construccion-residencial',
                'Construcción Residencial',
                self::person('andrea-hincapie'),
                50,
                Percentage::of(1),
                [21, 33, 36, 42, 44, 50, 52, 61, 66, 82, 86],
            ),
            new DirectorTeam(
                $validity,
                'construccion-institucional',
                'Construcción Institucional',
                self::person('andrea-hincapie'),
                50,
                Percentage::of(1),
                [85],
            ),
        ];
    }

    /**
     * @return list<DerivedCommissionRule>
     */
    private static function derived(Validity $validity): array
    {
        return [
            new DerivedCommissionRule(
                $validity,
                '81.1',
                'Sergio Caballero (venta)',
                self::person('sergio-caballero'),
                81,
                DerivedBase::Sales,
                Percentage::of('0.1'),
            ),
            new DerivedCommissionRule(
                $validity,
                '85.1',
                'Sergio Caballero (recaudo)',
                self::person('sergio-caballero'),
                85,
                DerivedBase::Collections,
                Percentage::of(1),
            ),
        ];
    }

    private static function person(string $id): PersonRef
    {
        return new PersonRef($id, self::PEOPLE[$id]);
    }
}
