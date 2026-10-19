<?php

use App\Modules\Comisiones\Domain\Parameters\MissingParameters;
use App\Modules\Comisiones\Domain\Parameters\Options\ComplianceScope;
use App\Modules\Comisiones\Domain\Parameters\Options\CreditNoteTreatment;
use App\Modules\Comisiones\Domain\Parameters\OptionsVersion;
use App\Modules\Comisiones\Domain\Parameters\ParameterBook;
use App\Modules\Comisiones\Domain\Parameters\PeriodParameters;
use App\Modules\Comisiones\Domain\Parameters\SettlementOptions;
use App\Modules\Comisiones\Domain\Parameters\TrancheTable;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Domain\Shared\Validity;
use Tests\Modules\Comisiones\Support\Params;

describe('forPeriod', function () {
    it('takes the latest version when a code changes mid-period', function () {
        $book = new ParameterBook(
            codes: [
                Params::rule(14, '2026-01-01', '2026-07-15', saleRate: '0.75'),
                Params::rule(14, '2026-07-16', saleRate: '1'),
            ],
            trancheTables: [Params::trancheTable()],
            teams: [],
            derived: [],
        );

        expect($book->forPeriod(Period::of(2026, 7))->code(14)?->saleRate->percent())->toBe('1')
            ->and($book->forPeriod(Period::of(2026, 6))->code(14)?->saleRate->percent())->toBe('0.75')
            ->and($book->forPeriod(Period::of(2026, 8))->code(14)?->saleRate->percent())->toBe('1');
    });

    it('ignores a version that ended before the period', function () {
        $book = new ParameterBook(
            codes: [
                Params::rule(14),
                Params::rule(99, '2026-01-01', '2026-06-30'),
            ],
            trancheTables: [Params::trancheTable()],
            teams: [],
            derived: [],
        );

        $parameters = $book->forPeriod(Period::of(2026, 7));

        expect($parameters->code(99))->toBeNull()
            ->and(array_keys($parameters->codes()))->toBe([14]);
    });

    it('ignores a version that starts after the period', function () {
        $book = new ParameterBook(
            codes: [Params::rule(14), Params::rule(70, '2026-08-01')],
            trancheTables: [Params::trancheTable()],
            teams: [],
            derived: [],
        );

        expect($book->forPeriod(Period::of(2026, 7))->code(70))->toBeNull()
            ->and($book->forPeriod(Period::of(2026, 8))->code(70)?->code)->toBe(70);
    });

    it('sorts the codes by number', function () {
        $book = new ParameterBook(
            codes: [Params::rule(85), Params::rule(14), Params::rule(50)],
            trancheTables: [Params::trancheTable()],
            teams: [],
            derived: [],
        );

        expect(array_keys($book->forPeriod(Period::of(2026, 7))->codes()))->toBe([14, 50, 85]);
    });

    it('resolves the tranche table, teams, derived rules and options of the period', function () {
        $book = new ParameterBook(
            codes: [Params::rule(14)],
            trancheTables: [
                Params::trancheTable('2026-01-01', '2026-06-30'),
                new TrancheTable(
                    Validity::from('2026-07-01'),
                    Params::standardTranches(),
                ),
            ],
            teams: [
                Params::team('distribucion', [14, 15], '0.2', '2026-01-01', '2026-06-30'),
                Params::team('distribucion', [14, 15, 16], '0.3', '2026-07-01'),
                Params::team('retired', [14], '0.1', '2025-01-01', '2025-12-31'),
            ],
            derived: [Params::derived('81.1'), Params::derived('85.1')],
            options: [
                new OptionsVersion(Validity::from('2026-01-01', '2026-06-30'), new SettlementOptions),
                new OptionsVersion(
                    Validity::from('2026-07-01'),
                    new SettlementOptions(creditNotes: CreditNoteTreatment::Exclude),
                ),
            ],
        );

        $parameters = $book->forPeriod(Period::of(2026, 7));

        expect($parameters)->toBeInstanceOf(PeriodParameters::class)
            ->and($parameters->period()->key())->toBe('2026-07')
            ->and($parameters->tranches()->validity->from->format('Y-m-d'))->toBe('2026-07-01')
            ->and(array_map(fn ($team) => $team->key, $parameters->teams()))->toBe(['distribucion'])
            ->and($parameters->teams()[0]->rate->percent())->toBe('0.3')
            ->and(array_map(fn ($rule) => $rule->key, $parameters->derived()))->toBe(['81.1', '85.1'])
            ->and($parameters->options()->creditNotes)->toBe(CreditNoteTreatment::Exclude);
    });

    it('uses the default options when none is in force', function () {
        $book = new ParameterBook(
            codes: [Params::rule(14)],
            trancheTables: [Params::trancheTable()],
            teams: [],
            derived: [],
            options: [new OptionsVersion(
                Validity::from('2027-01-01'),
                new SettlementOptions(complianceScope: ComplianceScope::Person),
            )],
        );

        expect($book->forPeriod(Period::of(2026, 7))->options()->complianceScope)->toBe(ComplianceScope::Code);
    });

    it('fails without a tranche table for the period', function () {
        $book = new ParameterBook(
            codes: [Params::rule(14)],
            trancheTables: [Params::trancheTable('2027-01-01')],
            teams: [],
            derived: [],
        );

        $book->forPeriod(Period::of(2026, 7));
    })->throws(MissingParameters::class, '2026-07');
});

describe('overlapping validities', function () {
    it('rejects two versions of the same key in force on the same day', function (Closure $build, string $message) {
        expect($build)->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'code' => [fn () => new ParameterBook(
            codes: [Params::rule(14, '2026-01-01', '2026-07-01'), Params::rule(14, '2026-07-01')],
            trancheTables: [Params::trancheTable()],
            teams: [],
            derived: [],
        ), 'code 14'],
        'open-ended code' => [fn () => new ParameterBook(
            codes: [Params::rule(14), Params::rule(14, '2026-07-01')],
            trancheTables: [Params::trancheTable()],
            teams: [],
            derived: [],
        ), 'code 14'],
        'tranche table' => [fn () => new ParameterBook(
            codes: [],
            trancheTables: [Params::trancheTable(), Params::trancheTable('2026-07-01')],
            teams: [],
            derived: [],
        ), 'tranche table'],
        'team' => [fn () => new ParameterBook(
            codes: [],
            trancheTables: [Params::trancheTable()],
            teams: [Params::team('distribucion'), Params::team('distribucion', from: '2026-03-01')],
            derived: [],
        ), 'team distribucion'],
        'derived' => [fn () => new ParameterBook(
            codes: [],
            trancheTables: [Params::trancheTable()],
            teams: [],
            derived: [Params::derived('81.1'), Params::derived('81.1', from: '2026-03-01')],
        ), 'derived commission 81.1'],
        'options' => [fn () => new ParameterBook(
            codes: [],
            trancheTables: [Params::trancheTable()],
            teams: [],
            derived: [],
            options: [
                new OptionsVersion(Validity::from('2026-01-01'), new SettlementOptions),
                new OptionsVersion(Validity::from('2026-05-01', '2026-05-31'), new SettlementOptions),
            ],
        ), 'options'],
    ]);

    it('accepts consecutive versions and different keys', function () {
        $book = new ParameterBook(
            codes: [Params::rule(14, '2026-01-01', '2026-06-30'), Params::rule(14, '2026-07-01'), Params::rule(15)],
            trancheTables: [Params::trancheTable()],
            teams: [Params::team('distribucion'), Params::team('residencial')],
            derived: [Params::derived('81.1'), Params::derived('85.1')],
        );

        expect(array_keys($book->forPeriod(Period::of(2026, 7))->codes()))->toBe([14, 15]);
    });
});

describe('keys across kinds', function () {
    it('rejects a key shared by a director team and a derived commission, so their alerts never merge', function () {
        expect(fn () => new ParameterBook(
            codes: [],
            trancheTables: [Params::trancheTable()],
            teams: [Params::team('especial')],
            derived: [Params::derived('especial', from: '2027-01-01')],
        ))->toThrow(InvalidArgumentException::class, 'especial');
    });
});

describe('PeriodParameters', function () {
    it('keys the codes by number and rejects duplicates', function () {
        new PeriodParameters(
            Period::of(2026, 7),
            [Params::rule(14), Params::rule(14)],
            Params::trancheTable(),
            [],
            [],
            new SettlementOptions,
        );
    })->throws(InvalidArgumentException::class, 'code 14');
});
