<?php

use App\Modules\Comisiones\Domain\Calculation\AlertKind;
use App\Modules\Comisiones\Domain\Calculation\CodeSettlement;
use App\Modules\Comisiones\Domain\Calculation\DerivedSettlement;
use App\Modules\Comisiones\Domain\Input\SettlementInput;
use App\Modules\Comisiones\Domain\Parameters\CodeScope;
use App\Modules\Comisiones\Domain\Shared\Money;
use App\Modules\Comisiones\Domain\Shared\Period;
use Tests\Modules\Comisiones\Support\Annex;
use Tests\Modules\Comisiones\Support\Lines;
use Tests\Modules\Comisiones\Support\Settle;

describe('OBR/Escudos and manual adjustments (§5.5, §5.7)', function () {
    it('adds the OBR commission and the reimbursements and takes off the deductions', function () {
        $result = Settle::run(new SettlementInput(
            Period::of(2026, 7),
            sales: [Lines::sale(34, 10_000_000)],
            obrSales: [Lines::obr(34, 10_000_000, '2')],
            adjustments: [
                Lines::reimbursement(34, 50_000),
                Lines::deduction(34, 20_000),
                Lines::deduction(34, 5_000),
            ],
        ));
        $settlement = Settle::code($result, 34);

        expect($settlement->saleCommission->amount())->toBe('75000')
            ->and($settlement->obrCommission->amount())->toBe('200000')
            ->and($settlement->reimbursements->amount())->toBe('50000')
            ->and($settlement->deductions->amount())->toBe('25000')
            ->and($settlement->total->amount())->toBe('300000')
            ->and($settlement->obrSales)->toHaveCount(1)
            ->and($settlement->obrSales[0]->description)->toBe('Escudos obsoletos')
            ->and($settlement->adjustments)->toHaveCount(3)
            ->and($result->alerts())->toBe([]);
    });

    it('rounds the OBR commission once per code, not per sale', function () {
        $result = Settle::run(new SettlementInput(
            Period::of(2026, 7),
            obrSales: [Lines::obr(34, 25, '2'), Lines::obr(34, 25, '2')],
        ));
        $settlement = Settle::code($result, 34);

        expect($settlement->obrCommission->amount())->toBe('1')
            ->and($settlement->total->amount())->toBe('1')
            ->and($settlement->hasActivity())->toBeTrue();
    });

    it('pays no OBR commission on a control code but applies its adjustments', function () {
        $result = Settle::run(new SettlementInput(
            Period::of(2026, 7),
            obrSales: [Lines::obr(80, 10_000_000, '2')],
            adjustments: [Lines::reimbursement(80, 30_000)],
        ));
        $settlement = Settle::code($result, 80);

        expect($settlement->obrCommission->amount())->toBe('0')
            ->and($settlement->obrSales)->toHaveCount(1)
            ->and($settlement->reimbursements->amount())->toBe('30000')
            ->and($settlement->total->amount())->toBe('30000');
    });

    it('leaves out the captures of a code without parameters and blocks the settlement', function () {
        $result = Settle::run(new SettlementInput(
            Period::of(2026, 7),
            sales: [Lines::sale(99, 1_000_000)],
            obrSales: [Lines::obr(99, 1_000_000, '2')],
            adjustments: [Lines::reimbursement(99, 10_000), Lines::deduction(98, 5_000)],
        ));
        $alerts = $result->alertsOf(AlertKind::UnknownSellerCode);

        expect($result->code(99))->toBeNull()
            ->and($result->code(98))->toBeNull()
            ->and(Settle::references($result, AlertKind::UnknownSellerCode))->toBe(['99', '98'])
            ->and($alerts[1]->sellerCode)->toBe(98)
            ->and($alerts[1]->isBlocking())->toBeTrue()
            ->and($alerts[1]->message)->toBe('El código 98 no existe en los parámetros del período; sus capturas manuales no se aplican.')
            ->and(Money::sum(array_map(fn (CodeSettlement $code) => $code->total, $result->codes()))->amount())->toBe('0');
    });

    it('flags a code whose deductions leave its total below zero', function () {
        $result = Settle::run(new SettlementInput(
            Period::of(2026, 7),
            sales: [Lines::sale(34, 1_000_000)],
            adjustments: [Lines::deduction(34, 10_000)],
        ));
        $alerts = $result->alertsOf(AlertKind::NegativeCommission);

        expect(Settle::code($result, 34)->saleCommission->amount())->toBe('7500')
            ->and(Settle::code($result, 34)->total->amount())->toBe('-2500')
            ->and($alerts)->toHaveCount(1)
            ->and($alerts[0]->reference)->toBe('34')
            ->and($alerts[0]->isBlocking())->toBeTrue();
    });
});

describe('special codes (§5.5, Annex 1)', function () {
    it('settles each special code on its own rates', function (int $code, ?int $budget, string $sale, string $collection, CodeScope $scope) {
        $result = Settle::run(new SettlementInput(
            Period::of(2026, 7),
            sales: [Lines::sale($code, 100_000_000)],
            payments: [Lines::payment($code, 10_000_000, paymentDate: '2026-07-20', days: 30)],
            budgets: $budget === null ? [] : [Lines::budget($code, $budget)],
        ));
        $settlement = Settle::code($result, $code);

        expect($settlement->saleCommission->amount())->toBe($sale)
            ->and($settlement->collected->amount())->toBe('10000000')
            ->and($settlement->collectionCommission->amount())->toBe($collection)
            ->and($settlement->total->amount())->toBe(Money::of($sale)->plus(Money::of($collection))->amount())
            ->and($settlement->rule->scope)->toBe($scope)
            ->and($result->alerts())->toBe([]);
    })->with([
        '84 Retail: 1 % on sales only, without budget' => [84, null, '1000000', '0', CodeScope::Separate],
        '90 E-commerce: 2 % on sales only, without budget' => [90, null, '2000000', '0', CodeScope::Separate],
        '81 Cuentas Clave: 0.35 % on sales only, without budget' => [81, null, '350000', '0', CodeScope::Separate],
        '50: 1 % on sales only, without budget' => [50, null, '1000000', '0', CodeScope::Regular],
        '53: 1 % on sales only, without budget' => [53, null, '1000000', '0', CodeScope::Regular],
        '16: 1.25 % on sales and collections' => [16, 100_000_000, '1250000', '125000', CodeScope::Regular],
        '21: 1.25 % on sales and collections' => [21, 100_000_000, '1250000', '125000', CodeScope::Regular],
    ]);
});

describe('consolidated sections (§8.1)', function () {
    beforeEach(function () {
        $this->result = Settle::run(new SettlementInput(
            Period::of(2026, 7),
            sales: [
                Lines::sale(14, 90_000_000),
                Lines::sale(81, 100_000_000),
                Lines::sale(84, 10_000_000),
                Lines::sale(90, 10_000_000),
                Lines::sale(80, 5_000_000),
                Lines::sale(100, 1_000_000),
            ],
            payments: Annex::code85Payments('2026-07'),
            budgets: [Lines::budget(14, 100_000_000)],
        ));
    });

    it('settles 81, 84 and 90 apart from the regular team', function () {
        expect(array_values(array_intersect(array_keys($this->result->regular()), [81, 84, 90])))->toBe([])
            ->and(array_keys($this->result->regular()))->toContain(14, 50, 53, 85)
            ->and(array_map(fn (CodeSettlement $code) => $code->total->amount(), $this->result->separate()))
            ->toBe([81 => '350000', 84 => '100000', 90 => '200000']);
    });

    it('adds 81.1 and 85.1 to the regular total, but not the codes settled apart', function () {
        $codes = Money::sum(array_map(fn (CodeSettlement $code) => $code->total, $this->result->regular()));
        $derived = Money::sum(array_map(fn (DerivedSettlement $derived) => $derived->commission, $this->result->derivedCommissions()));

        expect($codes->amount())->toBe('758231')
            ->and($derived->amount())->toBe('155487')
            ->and($this->result->regularTotal()->amount())->toBe('913718');
    });

    it('keeps 80 and 100 only to reconcile the sales', function () {
        $controls = $this->result->controls();

        expect(array_keys($this->result->control()))->toBe([80, 100])
            ->and(Settle::code($this->result, 80)->sales->amount())->toBe('5000000')
            ->and(Settle::code($this->result, 100)->sales->amount())->toBe('1000000')
            ->and(Settle::code($this->result, 80)->total->amount())->toBe('0')
            ->and(Settle::code($this->result, 100)->total->amount())->toBe('0')
            ->and($controls->loadedSales->amount())->toBe('216000000')
            ->and($controls->salesByCode->amount())->toBe('216000000')
            ->and($controls->salesReconcile())->toBeTrue()
            ->and($controls->collectionsReconcile())->toBeTrue();
    });
});
