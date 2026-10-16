<?php

use App\Modules\Comisiones\Domain\Calculation\Alert;
use App\Modules\Comisiones\Domain\Calculation\AlertKind;
use App\Modules\Comisiones\Domain\Calculation\CollectionDetail;
use App\Modules\Comisiones\Domain\Calculation\CollectionSource;
use App\Modules\Comisiones\Domain\Calculation\ControlTotals;
use App\Modules\Comisiones\Domain\Calculation\DerivedSettlement;
use App\Modules\Comisiones\Domain\Calculation\DirectorSettlement;
use App\Modules\Comisiones\Domain\Calculation\DirectorTeamRow;
use App\Modules\Comisiones\Domain\Calculation\PersonRole;
use App\Modules\Comisiones\Domain\Calculation\PersonSettlement;
use App\Modules\Comisiones\Domain\Calculation\RoleKind;
use App\Modules\Comisiones\Domain\Calculation\SettlementResult;
use App\Modules\Comisiones\Domain\Shared\Dates;
use App\Modules\Comisiones\Domain\Shared\Money;
use App\Modules\Comisiones\Domain\Shared\Percentage;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Domain\Shared\PersonRef;
use Tests\Modules\Comisiones\Support\Lines;
use Tests\Modules\Comisiones\Support\Settle;

describe('SettlementResult', function () {
    beforeEach(function () {
        $base = Settle::sales(
            [Lines::sale(14, 90_000_000), Lines::sale(81, 10_000_000), Lines::sale(80, 5_000_000)],
            [Lines::budget(14, 100_000_000)],
        );
        $parameters = $base->parameters();
        $this->parameters = $parameters;
        $this->base = $base;

        $this->result = new SettlementResult(
            Period::of(2026, 7),
            $parameters,
            $base->codes(),
            [new DerivedSettlement($parameters->derived()[0], Money::of(10_000_000), Money::of(10_000))],
            [new DirectorSettlement(
                $parameters->teams()[0],
                [new DirectorTeamRow(14, 'Mónica Rivera', Money::of(90_000_000), Money::zero())],
                Money::of(90_000_000),
                Money::zero(),
                Money::of(180_000),
                Money::zero(),
                Money::of(180_000),
            )],
            [new PersonSettlement(
                new PersonRef('monica-rivera', 'Mónica Rivera'),
                [new PersonRole(RoleKind::Code, '14', 'Código 14 – Mónica Rivera', Money::of(675_000))],
                Money::of(675_000),
            )],
            [
                new Alert(AlertKind::ForeignCurrency, 'F7-1', 'Moneda extranjera.'),
                new Alert(AlertKind::UnknownSellerCode, '99', 'Código 99.', 99),
            ],
            $base->controls(),
        );
    });

    it('groups the codes by scope', function () {
        expect(array_keys($this->result->regular()))->toBe([
            14, 15, 16, 17, 18, 21, 23, 32, 33, 34, 36, 41, 42, 43, 44, 50, 51, 52, 53, 61, 66, 67, 69, 82, 85, 86,
        ])
            ->and(array_keys($this->result->separate()))->toBe([81, 84, 90])
            ->and(array_keys($this->result->control()))->toBe([80, 100]);
    });

    it('adds the regular code totals and the derived commissions', function () {
        expect($this->base->regularTotal()->amount())->toBe('675000')
            ->and($this->result->regularTotal()->amount())->toBe('685000');
    });

    it('finds derived commissions, directors and persons by key', function () {
        expect($this->result->derived('81.1')?->commission->amount())->toBe('10000')
            ->and($this->result->derived('99.1'))->toBeNull()
            ->and($this->result->director('distribucion')?->total->amount())->toBe('180000')
            ->and($this->result->director('desconocido'))->toBeNull()
            ->and($this->result->person('monica-rivera')?->roles[0]->reference)->toBe('14')
            ->and($this->result->person('nadie'))->toBeNull()
            ->and($this->result->derivedCommissions())->toHaveCount(1)
            ->and($this->result->directors())->toHaveCount(1)
            ->and($this->result->persons())->toHaveCount(1);
    });

    it('filters the alerts by kind and severity', function () {
        expect($this->result->alerts())->toHaveCount(2)
            ->and($this->result->alertsOf(AlertKind::ForeignCurrency))->toHaveCount(1)
            ->and($this->result->alertsOf(AlertKind::MissingBudget))->toBe([])
            ->and(array_map(fn (Alert $alert) => $alert->key(), $this->result->blockingAlerts()))->toBe(['codigo_desconocido:99'])
            ->and($this->result->hasBlockingAlerts())->toBeTrue();
    });

    it('rejects parameters of another period', function () {
        new SettlementResult(
            Period::of(2026, 8),
            $this->parameters,
            [],
            [],
            [],
            [],
            [],
            new ControlTotals(Money::zero(), Money::zero(), Money::zero(), Money::zero(), Money::zero(), Money::zero()),
        );
    })->throws(InvalidArgumentException::class);
});

describe('ControlTotals', function () {
    it('reconciles collections against the settled ones', function () {
        $balanced = new ControlTotals(Money::zero(), Money::zero(), Money::zero(), Money::of(100), Money::of('100.00'), Money::zero());
        $unbalanced = new ControlTotals(Money::zero(), Money::zero(), Money::zero(), Money::of(100), Money::of(90), Money::of(10));

        expect($balanced->collectionsReconcile())->toBeTrue()
            ->and($unbalanced->collectionsReconcile())->toBeFalse()
            ->and($unbalanced->unassignedCollections->amount())->toBe('10');
    });
});

describe('collection details', function () {
    it('marks the crossings in the client label (§5.4)', function () {
        $detail = fn (CollectionSource $source) => new CollectionDetail(
            key: 'CR1',
            source: $source,
            sellerCode: 41,
            invoiceNumber: '7001',
            clientCode: 'C041',
            clientName: 'Constructora Andina',
            invoiceDate: Dates::day('2026-08-19'),
            paymentDate: Dates::day('2026-09-16'),
            invoiceAmount: Money::of(2_713_450),
            paidBase: Money::of(2_280_210),
            days: 28,
            tranched: true,
            share: Percentage::full(),
            rate: Percentage::of('0.75'),
            commission: Money::of('17101.575'),
        );

        $payment = $detail(CollectionSource::Payment);
        $crossing = $detail(CollectionSource::Crossing);

        expect($payment->isCrossing())->toBeFalse()
            ->and($payment->clientLabel())->toBe('Constructora Andina')
            ->and($crossing->isCrossing())->toBeTrue()
            ->and($crossing->clientLabel())->toBe('Constructora Andina (cruce)')
            ->and($crossing->commission->rounded()->amount())->toBe('17102');
    });

    it('labels the sources and the roles in Spanish', function () {
        expect(CollectionSource::Payment->label())->toBe('Pago')
            ->and(CollectionSource::Crossing->label())->toBe('Cruce')
            ->and(RoleKind::Code->label())->toBe('Código')
            ->and(RoleKind::Derived->label())->toBe('Especial')
            ->and(RoleKind::Director->label())->toBe('Director');
    });
});
