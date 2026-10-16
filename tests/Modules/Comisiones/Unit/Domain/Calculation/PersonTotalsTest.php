<?php

use App\Modules\Comisiones\Domain\Calculation\CodeSettlement;
use App\Modules\Comisiones\Domain\Calculation\DerivedSettlement;
use App\Modules\Comisiones\Domain\Calculation\DirectorSettlement;
use App\Modules\Comisiones\Domain\Calculation\PersonRole;
use App\Modules\Comisiones\Domain\Calculation\PersonSettlement;
use App\Modules\Comisiones\Domain\Calculation\RoleKind;
use App\Modules\Comisiones\Domain\Input\SettlementInput;
use App\Modules\Comisiones\Domain\Shared\Money;
use App\Modules\Comisiones\Domain\Shared\Period;
use Tests\Modules\Comisiones\Support\Annex;
use Tests\Modules\Comisiones\Support\Lines;
use Tests\Modules\Comisiones\Support\Settle;

describe('totals per person (§5.7, July 2026)', function () {
    beforeEach(function () {
        $this->result = Settle::run(new SettlementInput(
            Period::of(2026, 7),
            sales: [
                Lines::sale(32, 10_000_000),
                Lines::sale(34, 4_000_000),
                Lines::sale(53, 500_303_052),
                Lines::sale(84, 10_000_000),
                Lines::sale(50, 95_019_688),
                Lines::sale(81, 100_000_000),
                Lines::sale(21, 300_000_000),
                Lines::sale(17, 1_000_000),
            ],
            payments: [
                Lines::payment(32, 1_000_000, paymentDate: '2026-07-20', days: 30),
                ...Annex::code85Payments('2026-07'),
            ],
            budgets: [
                Lines::budget(32, 10_000_000),
                Lines::budget(21, 300_000_000),
                Lines::budget(17, 1_000_000),
            ],
        ));
    });

    it('adds every role of the person', function (string $person, array $references, string $total) {
        $settlement = Settle::person($this->result, $person);

        expect(array_map(fn (PersonRole $role) => $role->reference, $settlement->roles))->toBe($references)
            ->and($settlement->total->amount())->toBe($total)
            ->and(Money::sum(array_map(fn (PersonRole $role) => $role->amount, $settlement->roles))->amount())->toBe($total);
    })->with([
        'Harold Marín = 32 + 34' => ['harold-marin', ['32', '34'], '112500'],
        'Jessika Quintero = 53 + 84 + director Distribución' => ['jessika-quintero', ['53', '84', 'distribucion'], '6135637'],
        'Andrea Hincapié = 50 + 81 + Residencial + Institucional' => [
            'andrea-hincapie',
            ['50', '81', 'construccion-residencial', 'construccion-institucional'],
            '5305881',
        ],
        'Sergio Caballero = 81.1 + 85.1' => ['sergio-caballero', ['81.1', '85.1'], '155487'],
        'Liliam Herrera = 85' => ['liliam-herrera', ['85'], '83231'],
    ]);

    it('takes each role from its code, derived commission or director team', function () {
        $result = $this->result;

        expect(Settle::roles(Settle::person($result, 'jessika-quintero')))->toBe([
            [RoleKind::Code, '53', 'Código 53 – Jessika Quintero', Settle::code($result, 53)->total->amount()],
            [RoleKind::Code, '84', 'Código 84 – Jessika Quintero', Settle::code($result, 84)->total->amount()],
            [RoleKind::Director, 'distribucion', 'Director Distribución', Settle::director($result, 'distribucion')->total->amount()],
        ])
            ->and(Settle::roles(Settle::person($result, 'sergio-caballero')))->toBe([
                [RoleKind::Derived, '81.1', 'Sergio Caballero (venta)', '100000'],
                [RoleKind::Derived, '85.1', 'Sergio Caballero (recaudo)', '55487'],
            ])
            ->and(Settle::roles(Settle::person($result, 'harold-marin')))->toBe([
                [RoleKind::Code, '32', 'Código 32 – Harold Marín', '82500'],
                [RoleKind::Code, '34', 'Código 34 – Harold Marín', '30000'],
            ])
            ->and(Settle::person($result, 'andrea-hincapie')->person->name)->toBe('Andrea Hincapié');
    });

    it('pays a code without person as its own person', function () {
        $settlement = Settle::person($this->result, 'codigo-17');

        expect($settlement->person->name)->toBe('Código 17')
            ->and(Settle::roles($settlement))->toBe([[RoleKind::Code, '17', 'Código 17', '7500']])
            ->and($settlement->total->amount())->toBe('7500');
    });

    it('lists by name the people with something in the period', function () {
        expect(array_map(fn (PersonSettlement $person) => $person->person->name, $this->result->persons()))->toBe([
            'Andrea Hincapié',
            'Código 17',
            'Código 21',
            'Harold Marín',
            'Jessika Quintero',
            'Liliam Herrera',
            'Sergio Caballero',
        ]);
    });

    it('pays to payroll exactly what the codes, derived commissions and directors add up to', function () {
        $result = $this->result;
        $codes = Money::sum(array_map(fn (CodeSettlement $code) => $code->total, $result->codes()));
        $derived = Money::sum(array_map(fn (DerivedSettlement $derived) => $derived->commission, $result->derivedCommissions()));
        $directors = Money::sum(array_map(fn (DirectorSettlement $director) => $director->total, $result->directors()));
        $persons = Money::sum(array_map(fn (PersonSettlement $person) => $person->total, $result->persons()));

        expect($persons->amount())->toBe($codes->plus($derived)->plus($directors)->amount())
            ->and($persons->amount())->toBe('15550236');
    });
});

describe('codes without activity in the totals per person (§5.7, §8.1 annex)', function () {
    it('leaves out a code without activity, and a person left without roles', function () {
        // Only code 34 of Harold Marín sells: 32 has nothing, Mónica Rivera (14) and code 17 neither.
        $result = Settle::sales([Lines::sale(34, 1_000_000)]);

        expect(Settle::roles(Settle::person($result, 'harold-marin')))->toBe([[RoleKind::Code, '34', 'Código 34 – Harold Marín', '7500']])
            ->and($result->person('monica-rivera'))->toBeNull()
            ->and($result->person('codigo-17'))->toBeNull();
    });

    it('keeps a code with activity even when it pays nothing', function () {
        // Code 14 sells 1 % of its budget: below the minimum, so it pays zero, but it had activity.
        $result = Settle::sales([Lines::sale(14, 1_000_000)], [Lines::budget(14, 100_000_000), Lines::budget(15, 50_000_000)]);

        expect(Settle::roles(Settle::person($result, 'monica-rivera')))->toBe([[RoleKind::Code, '14', 'Código 14 – Mónica Rivera', '0']])
            ->and(Settle::roles(Settle::person($result, 'paola-vargas')))->toBe([[RoleKind::Code, '15', 'Código 15 – Paola Vargas', '0']]);
    });

    it('keeps the derived and director roles even at zero', function () {
        $result = Settle::sales([]);

        expect(array_map(fn (PersonSettlement $person) => $person->person->name, $result->persons()))
            ->toBe(['Andrea Hincapié', 'Jessika Quintero', 'Sergio Caballero'])
            ->and(Settle::roles(Settle::person($result, 'jessika-quintero')))
            ->toBe([[RoleKind::Director, 'distribucion', 'Director Distribución', '0']])
            ->and(array_map(fn (PersonRole $role) => $role->reference, Settle::person($result, 'sergio-caballero')->roles))
            ->toBe(['81.1', '85.1']);
    });
});

describe('control codes in the totals per person (§5.7)', function () {
    it('leaves out a control code with nothing to pay', function () {
        $result = Settle::sales([Lines::sale(80, 5_000_000), Lines::sale(100, 1_000_000)]);

        expect($result->person('codigo-80'))->toBeNull()
            ->and($result->person('codigo-100'))->toBeNull();
    });

    it('pays a control code with manual adjustments as its own person', function () {
        $result = Settle::run(new SettlementInput(
            Period::of(2026, 7),
            sales: [Lines::sale(80, 5_000_000)],
            adjustments: [Lines::reimbursement(80, 30_000)],
        ));
        $settlement = Settle::person($result, 'codigo-80');

        expect($settlement->person->name)->toBe('Oficina – Varios')
            ->and(Settle::roles($settlement))->toBe([[RoleKind::Code, '80', 'Código 80 – Oficina – Varios', '30000']])
            ->and($settlement->total->amount())->toBe('30000');
    });
});
