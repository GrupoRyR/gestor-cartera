<?php

use App\Modules\Comisiones\Domain\Calculation\AlertKind;
use App\Modules\Comisiones\Domain\Calculation\DirectorTeamRow;
use App\Modules\Comisiones\Domain\Calculation\PersonRole;
use App\Modules\Comisiones\Domain\Calculation\PersonSettlement;
use App\Modules\Comisiones\Domain\Calculation\RoleKind;
use App\Modules\Comisiones\Domain\Input\SettlementInput;
use App\Modules\Comisiones\Domain\Parameters\Options\DirectorPlacement;
use App\Modules\Comisiones\Domain\Parameters\SettlementOptions;
use App\Modules\Comisiones\Domain\Shared\Period;
use Tests\Modules\Comisiones\Support\Annex;
use Tests\Modules\Comisiones\Support\Lines;
use Tests\Modules\Comisiones\Support\Params;
use Tests\Modules\Comisiones\Support\Settle;

describe('Annex 2 channel directors (July 2026)', function () {
    beforeEach(function () {
        $this->result = Settle::run(Annex::directorsInput());
    });

    it('pays the director rate on the whole team (the split per code is synthetic, the team totals are exact)', function (string $team, string $sales, string $salesCommission, string $collected, string $collectionCommission, string $total) {
        $director = Settle::director($this->result, $team);

        expect($director->sales->amount())->toBe($sales)
            ->and($director->salesCommission->amount())->toBe($salesCommission)
            ->and($director->collected->amount())->toBe($collected)
            ->and($director->collectionCommission->amount())->toBe($collectionCommission)
            ->and($director->total->amount())->toBe($total)
            ->and($director->paidWithCode)->toBeNull();
    })->with([
        'Distribución at 0.2 %' => ['distribucion', '1378490620', '2756981', '1546524797', '3093050', '5850031'],
        'Construcción Residencial at 1 %' => ['construccion-residencial', '395019688', '3950197', '185904516', '1859045', '5809242'],
        'Construcción Institucional at 1 %, on code 85' => ['construccion-institucional', '0', '0', '5548738', '55487', '55487'],
    ]);

    it('lists every member code of the team, in team order', function () {
        $rows = Settle::director($this->result, 'distribucion')->rows;

        expect(array_map(fn (DirectorTeamRow $row) => $row->code, $rows))
            ->toBe([14, 15, 16, 17, 18, 23, 32, 34, 41, 43, 51, 53, 67, 69])
            ->and([$rows[0]->name, $rows[0]->sales->amount(), $rows[0]->collected->amount()])
            ->toBe(['Mónica Rivera', '400000000', '500000000'])
            ->and([$rows[1]->name, $rows[1]->sales->amount(), $rows[1]->collected->amount()])
            ->toBe(['Paola Vargas', '0', '0'])
            ->and([$rows[11]->name, $rows[11]->sales->amount(), $rows[11]->collected->amount()])
            ->toBe(['Jessika Quintero', '500303052', '446524797']);
    });

    it('pays the director apart from the associated code by default (§14.4)', function () {
        expect(Settle::code($this->result, 53)->directorCommission->amount())->toBe('0')
            ->and(Settle::code($this->result, 53)->total->amount())->toBe('5003031')
            ->and(Settle::code($this->result, 50)->directorCommission->amount())->toBe('0')
            ->and(Settle::code($this->result, 50)->total->amount())->toBe('950197');
    });
});

describe('director base (§5.6, §14.3)', function () {
    it('counts the crossings in the team collections only when asked to', function (?SettlementOptions $options, string $collected, string $commission) {
        $result = Settle::collections(
            '2026-07',
            [Lines::payment(14, 1_000_000, paymentDate: '2026-07-20', days: 30)],
            [Lines::crossing(1_000_000, crossingDate: '2026-07-20', invoiceDate: '2026-07-01', taxBase: 1_000_000, client: 'C-14')],
            [Lines::assignment('C-14', 14)],
            $options,
        );
        $director = Settle::director($result, 'distribucion');

        expect(Settle::code($result, 14)->collected->amount())->toBe('2000000')
            ->and($director->rows[0]->collected->amount())->toBe($collected)
            ->and($director->collected->amount())->toBe($collected)
            ->and($director->collectionCommission->amount())->toBe($commission);
    })->with([
        'payments only by default' => [null, '1000000', '2000'],
        'payments and crossings when asked to' => [new SettlementOptions(crossingsInDirectorCollections: true), '2000000', '4000'],
    ]);

    it('settles Construcción Institucional on the sales and collections of code 85', function () {
        $result = Settle::run(new SettlementInput(
            Period::of(2026, 8),
            sales: [Lines::sale(85, 10_000_000, date: '2026-08-10')],
            payments: Annex::code85Payments(),
        ));
        $director = Settle::director($result, 'construccion-institucional');

        expect(array_map(fn (DirectorTeamRow $row) => [$row->code, $row->name], $director->rows))->toBe([[85, 'Liliam Herrera']])
            ->and($director->sales->amount())->toBe('10000000')
            ->and($director->salesCommission->amount())->toBe('100000')
            ->and($director->collected->amount())->toBe('5548738')
            ->and($director->collectionCommission->amount())->toBe('55487')
            ->and($director->total->amount())->toBe('155487');
    });

    it('lists a member code without parameters as an empty row', function () {
        $book = Settle::book(teams: [Params::team('prueba', [14, 99], rate: '1', channel: 'Prueba')]);
        $result = Settle::using($book, new SettlementInput(
            Period::of(2026, 7),
            sales: [Lines::sale(14, 1_000_000), Lines::sale(99, 5_000_000)],
        ));
        $director = Settle::director($result, 'prueba');

        expect([$director->rows[1]->code, $director->rows[1]->name, $director->rows[1]->sales->amount(), $director->rows[1]->collected->amount()])
            ->toBe([99, 'Código 99', '0', '0'])
            ->and($director->sales->amount())->toBe('1000000')
            ->and($director->salesCommission->amount())->toBe('10000')
            ->and(Settle::references($result, AlertKind::UnknownSellerCode))->toBe(['99']);
    });
});

describe('director placement (§14.4)', function () {
    beforeEach(function () {
        $this->separate = Settle::run(Annex::directorsInput());
        $this->associated = Settle::run(Annex::directorsInput(), new SettlementOptions(directorPlacement: DirectorPlacement::AssociatedCode));
    });

    it('adds each team to the total of its associated code', function () {
        expect(Settle::code($this->associated, 53)->directorCommission->amount())->toBe('5850031')
            ->and(Settle::code($this->associated, 53)->total->amount())->toBe('10853062')
            ->and(Settle::code($this->associated, 50)->directorCommission->amount())->toBe('5864729')
            ->and(Settle::code($this->associated, 50)->total->amount())->toBe('6814926')
            ->and(Settle::director($this->associated, 'distribucion')->paidWithCode)->toBe(53)
            ->and(Settle::director($this->associated, 'construccion-residencial')->paidWithCode)->toBe(50)
            ->and(Settle::director($this->associated, 'construccion-institucional')->paidWithCode)->toBe(50);
    });

    it('does not pay the teams again as roles of the director', function () {
        $references = fn (PersonSettlement $person) => array_map(fn (PersonRole $role) => $role->reference, $person->roles);

        expect($references(Settle::person($this->associated, 'jessika-quintero')))->toBe(['53', '84'])
            ->and($references(Settle::person($this->associated, 'andrea-hincapie')))->toBe(['50', '81'])
            ->and($references(Settle::person($this->separate, 'jessika-quintero')))->toBe(['53', '84', 'distribucion'])
            ->and($references(Settle::person($this->separate, 'andrea-hincapie')))
            ->toBe(['50', '81', 'construccion-residencial', 'construccion-institucional']);
    });

    it('pays every person the same total under both placements', function () {
        $totals = fn (array $persons) => array_combine(
            array_map(fn (PersonSettlement $person) => $person->person->id, $persons),
            array_map(fn (PersonSettlement $person) => $person->total->amount(), $persons),
        );

        expect($totals($this->associated->persons()))->toBe($totals($this->separate->persons()))
            ->and(Settle::person($this->associated, 'jessika-quintero')->total->amount())->toBe('10853062');
    });

    it('keeps a team apart when its associated code has no parameters', function (?int $associatedCode) {
        $book = Settle::book(
            new SettlementOptions(directorPlacement: DirectorPlacement::AssociatedCode),
            teams: [Params::team('prueba', [14], channel: 'Prueba', associatedCode: $associatedCode)],
        );
        $result = Settle::using($book, new SettlementInput(Period::of(2026, 7), sales: [Lines::sale(14, 1_000_000)]));
        $roles = Settle::person($result, 'jessika-quintero')->roles;

        expect(Settle::director($result, 'prueba')->paidWithCode)->toBeNull()
            ->and(Settle::director($result, 'prueba')->total->amount())->toBe('2000')
            ->and(array_map(fn (PersonRole $role) => [$role->kind, $role->reference, $role->label, $role->amount->amount()], array_slice($roles, 2)))
            ->toBe([[RoleKind::Director, 'prueba', 'Director Prueba', '2000']]);
    })->with([
        'a code without parameters' => [99],
        'no associated code' => [null],
    ]);
});
