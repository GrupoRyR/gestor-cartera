<?php

use App\Modules\Comisiones\Application\DefaultParameters;
use App\Modules\Comisiones\Domain\Parameters\CodeScope;
use App\Modules\Comisiones\Domain\Parameters\DerivedBase;
use App\Modules\Comisiones\Domain\Parameters\DirectorTeam;
use App\Modules\Comisiones\Domain\Parameters\MissingParameters;
use App\Modules\Comisiones\Domain\Parameters\Options\DirectorPlacement;
use App\Modules\Comisiones\Domain\Parameters\PeriodParameters;
use App\Modules\Comisiones\Domain\Shared\Period;

function defaultParametersFor(int $year, int $month): PeriodParameters
{
    return DefaultParameters::book()->forPeriod(Period::of($year, $month));
}

describe('codes (Annex 1)', function () {
    it('loads every code of Annex 1', function () {
        expect(array_keys(defaultParametersFor(2026, 7)->codes()))->toBe([
            14, 15, 16, 17, 18, 21, 23, 32, 33, 34, 36, 41, 42, 43, 44, 50, 51, 52, 53,
            61, 66, 67, 69, 80, 81, 82, 84, 85, 86, 90, 100,
        ]);
    });

    it('applies the rates, minimum and scope of each code', function (
        int $code,
        string $sale,
        string $collection,
        string $minimum,
        CodeScope $scope,
        bool $tranches,
    ) {
        $rule = defaultParametersFor(2026, 7)->code($code);

        expect($rule)->not->toBeNull()
            ->and($rule?->saleRate->percent())->toBe($sale)
            ->and($rule?->collectionRate->percent())->toBe($collection)
            ->and($rule?->minimumCompliance->percent())->toBe($minimum)
            ->and($rule?->scope)->toBe($scope)
            ->and($rule?->usesTranches)->toBe($tranches);
    })->with([
        '14 general rule' => [14, '0.75', '0.75', '90', CodeScope::Regular, true],
        '16 special rate, minimum 80 %' => [16, '1.25', '1.25', '80', CodeScope::Regular, true],
        '21 special rate' => [21, '1.25', '1.25', '90', CodeScope::Regular, true],
        '34 no minimum' => [34, '0.75', '0.75', '0', CodeScope::Regular, true],
        '67 vacant, no minimum' => [67, '0.75', '0.75', '0', CodeScope::Regular, true],
        '50 sales only' => [50, '1', '0', '0', CodeScope::Regular, true],
        '53 sales only' => [53, '1', '0', '0', CodeScope::Regular, true],
        '85 collections only, no tranches' => [85, '0', '1.5', '0', CodeScope::Regular, false],
        '81 key accounts, separate' => [81, '0.35', '0', '0', CodeScope::Separate, true],
        '84 retail, separate' => [84, '1', '0', '0', CodeScope::Separate, true],
        '90 e-commerce, separate' => [90, '2', '0', '0', CodeScope::Separate, true],
        '80 office, control' => [80, '0', '0', '0', CodeScope::Control, true],
        '100 other income, control' => [100, '0', '0', '0', CodeScope::Control, true],
    ]);

    it('groups the codes of the same person', function (int $code, string $personKey, string $name) {
        $rule = defaultParametersFor(2026, 7)->code($code);

        expect($rule?->personKey())->toBe($personKey)
            ->and($rule?->name)->toBe($name);
    })->with([
        '14' => [14, 'monica-rivera', 'Mónica Rivera'],
        '16' => [16, 'carolina-ramirez', 'Carolina Ramírez'],
        '32' => [32, 'harold-marin', 'Harold Marín'],
        '34' => [34, 'harold-marin', 'Harold Marín'],
        '41' => [41, 'jenny-rocio-buitrago', 'Jenny Rocío Buitrago'],
        '44' => [44, 'jenny-rocio-buitrago', 'Jenny Rocío Buitrago'],
        '42' => [42, 'adriana-russi', 'Adriana Russi'],
        '82' => [82, 'adriana-russi', 'Adriana Russi'],
        '50' => [50, 'andrea-hincapie', 'Andrea Hincapié'],
        '81' => [81, 'andrea-hincapie', 'Andrea Hincapié'],
        '53' => [53, 'jessika-quintero', 'Jessika Quintero'],
        '84' => [84, 'jessika-quintero', 'Jessika Quintero'],
        '85' => [85, 'liliam-herrera', 'Liliam Herrera'],
        '90' => [90, 'anderson-avendano', 'Anderson Avendaño'],
        '17 without a person' => [17, 'codigo-17', 'Código 17'],
        '80 without a person' => [80, 'codigo-80', 'Oficina – Varios'],
        '100 without a person' => [100, 'codigo-100', 'Otros ingresos'],
    ]);
});

describe('tranches (§5.3)', function () {
    it('loads the collection tranches', function (int $days, string $share) {
        expect(defaultParametersFor(2026, 8)->tranches()->shareFor($days)->percent())->toBe($share);
    })->with([
        '42 days' => [42, '100'],
        '65 days' => [65, '100'],
        '66 days' => [66, '90'],
        '81 days' => [81, '80'],
        '99 days' => [99, '50'],
        '126 days' => [126, '0'],
    ]);
});

describe('director teams (§5.6)', function () {
    it('loads the three teams', function (string $key, string $channel, string $director, int $associated, string $rate, array $members) {
        $teams = array_values(array_filter(
            defaultParametersFor(2026, 7)->teams(),
            fn (DirectorTeam $team): bool => $team->key === $key,
        ));

        expect($teams)->toHaveCount(1)
            ->and($teams[0]->channel)->toBe($channel)
            ->and($teams[0]->director->id)->toBe($director)
            ->and($teams[0]->associatedCode)->toBe($associated)
            ->and($teams[0]->rate->percent())->toBe($rate)
            ->and($teams[0]->memberCodes)->toBe($members);
    })->with([
        'distribución' => [
            'distribucion', 'Distribución', 'jessika-quintero', 53, '0.2',
            [14, 15, 16, 17, 18, 23, 32, 34, 41, 43, 51, 53, 67, 69],
        ],
        'construcción residencial' => [
            'construccion-residencial', 'Construcción Residencial', 'andrea-hincapie', 50, '1',
            [21, 33, 36, 42, 44, 50, 52, 61, 66, 82, 86],
        ],
        'construcción institucional' => [
            'construccion-institucional', 'Construcción Institucional', 'andrea-hincapie', 50, '1',
            [85],
        ],
    ]);

    it('only names codes that have a rule', function () {
        $parameters = defaultParametersFor(2026, 7);

        foreach ($parameters->teams() as $team) {
            foreach ($team->memberCodes as $code) {
                expect($parameters->code($code))->not->toBeNull();
            }
        }
    });
});

describe('derived commissions (§5.5)', function () {
    it('loads 81.1 and 85.1 for Sergio Caballero', function () {
        $derived = defaultParametersFor(2026, 7)->derived();

        expect($derived)->toHaveCount(2)
            ->and($derived[0]->key)->toBe('81.1')
            ->and($derived[0]->label)->toBe('Sergio Caballero (venta)')
            ->and($derived[0]->person->id)->toBe('sergio-caballero')
            ->and($derived[0]->sourceCode)->toBe(81)
            ->and($derived[0]->base)->toBe(DerivedBase::Sales)
            ->and($derived[0]->rate->percent())->toBe('0.1')
            ->and($derived[1]->key)->toBe('85.1')
            ->and($derived[1]->label)->toBe('Sergio Caballero (recaudo)')
            ->and($derived[1]->person->id)->toBe('sergio-caballero')
            ->and($derived[1]->sourceCode)->toBe(85)
            ->and($derived[1]->base)->toBe(DerivedBase::Collections)
            ->and($derived[1]->rate->percent())->toBe('1');
    });
});

describe('validity', function () {
    it('uses the default options', function () {
        expect(defaultParametersFor(2026, 7)->options()->directorPlacement)->toBe(DirectorPlacement::Separate);
    });

    it('is in force from 2026-01-01 by default', function () {
        expect(fn () => DefaultParameters::book()->forPeriod(Period::of(2025, 12)))
            ->toThrow(MissingParameters::class);
    });

    it('accepts another start date', function () {
        $parameters = DefaultParameters::book('2025-06-01')->forPeriod(Period::of(2025, 6));

        expect($parameters->code(14)?->validity->from->format('Y-m-d'))->toBe('2025-06-01')
            ->and($parameters->code(14)?->validity->until)->toBeNull();
    });
});
