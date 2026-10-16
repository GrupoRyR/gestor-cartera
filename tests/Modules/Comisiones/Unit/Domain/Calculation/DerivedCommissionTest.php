<?php

use App\Modules\Comisiones\Domain\Calculation\AlertKind;
use App\Modules\Comisiones\Domain\Calculation\CollectionDetail;
use App\Modules\Comisiones\Domain\Calculation\CollectionSource;
use App\Modules\Comisiones\Domain\Calculation\DerivedSettlement;
use App\Modules\Comisiones\Domain\Input\SettlementInput;
use App\Modules\Comisiones\Domain\Parameters\CodeScope;
use App\Modules\Comisiones\Domain\Parameters\SettlementOptions;
use App\Modules\Comisiones\Domain\Shared\Money;
use App\Modules\Comisiones\Domain\Shared\Period;
use Tests\Modules\Comisiones\Support\Annex;
use Tests\Modules\Comisiones\Support\Lines;
use Tests\Modules\Comisiones\Support\Params;
use Tests\Modules\Comisiones\Support\Settle;

describe('81.1 on the sales of code 81 (§5.5)', function () {
    it('pays 0.1 % of the sales of code 81 as its own row, apart from code 81', function () {
        $result = Settle::sales([Lines::sale(81, 100_000_000)]);
        $derived = Settle::derived($result, '81.1');

        expect(Settle::code($result, 81)->saleCommission->amount())->toBe('350000')
            ->and(Settle::code($result, 81)->rule->scope)->toBe(CodeScope::Separate)
            ->and($derived->base->amount())->toBe('100000000')
            ->and($derived->commission->amount())->toBe('100000')
            ->and($derived->rule->label)->toBe('Sergio Caballero (venta)')
            ->and($derived->rule->person->id)->toBe('sergio-caballero')
            ->and($derived->details)->toBe([]);
    });

    it('rounds the derived commission once, half up', function () {
        $result = Settle::sales([Lines::sale(81, 1_234_500), Lines::sale(81, 1_000)]);

        expect(Settle::derived($result, '81.1')->base->amount())->toBe('1235500')
            ->and(Settle::derived($result, '81.1')->commission->amount())->toBe('1236');
    });
});

describe('85.1 on the collections of code 85 (Annex 2, August 2026)', function () {
    beforeEach(function () {
        $this->payments = Annex::code85Payments();
        $this->crossing = Lines::crossing(
            1_190_000,
            crossingDate: '2026-08-20',
            invoiceDate: '2026-08-01',
            key: 'CR-85',
            invoiceAmount: 1_190_000,
            taxBase: 1_000_000,
            client: 'C-85',
        );
        $this->assignments = [Lines::assignment('C-85', 85)];
    });

    it('pays a flat 1 % on the four payments of code 85', function () {
        $result = Settle::collections('2026-08', $this->payments);
        $derived = Settle::derived($result, '85.1');

        expect($derived->base->amount())->toBe('5548738')
            ->and($derived->commission->amount())->toBe('55487')
            ->and($derived->rule->label)->toBe('Sergio Caballero (recaudo)')
            ->and(Settle::code($result, 85)->collectionCommission->amount())->toBe('83231');
    });

    it('lists the payments of code 85 re-rated at 1 %, without tranches, for the 85.1 report', function () {
        $details = Settle::derived(Settle::collections('2026-08', $this->payments), '85.1')->details;

        expect($details)->toHaveCount(4)
            ->and(array_map(fn (CollectionDetail $detail) => $detail->rate->percent(), $details))->toBe(['1', '1', '1', '1'])
            ->and(array_map(fn (CollectionDetail $detail) => $detail->share->percent(), $details))->toBe(['100', '100', '100', '100'])
            ->and(array_map(fn (CollectionDetail $detail) => $detail->tranched, $details))->toBe([false, false, false, false])
            ->and(array_map(fn (CollectionDetail $detail) => $detail->commission->amount(), $details))
            ->toBe(['20000', '15000', '10487.38', '10000'])
            ->and($details[3]->days)->toBe(200)
            ->and($details[3]->sellerCode)->toBe(85)
            ->and($details[3]->source)->toBe(CollectionSource::Payment)
            ->and(Money::sum(array_map(fn (CollectionDetail $detail) => $detail->commission, $details))->rounded()->amount())
            ->toBe('55487');
    });

    it('leaves the crossings of code 85 out of 85.1 by default, as the current Excel does (§14.3)', function () {
        $result = Settle::collections('2026-08', $this->payments, [$this->crossing], $this->assignments);
        $derived = Settle::derived($result, '85.1');

        expect($derived->base->amount())->toBe('5548738')
            ->and($derived->commission->amount())->toBe('55487')
            ->and($derived->details)->toHaveCount(4)
            ->and(Settle::code($result, 85)->collected->amount())->toBe('6548738')
            ->and(Settle::code($result, 85)->collectionCommission->amount())->toBe('98231');
    });

    it('adds the crossings of code 85 to 85.1 when asked to (§14.3)', function () {
        $result = Settle::collections(
            '2026-08',
            $this->payments,
            [$this->crossing],
            $this->assignments,
            new SettlementOptions(crossingsInDerivedCollections: true),
        );
        $derived = Settle::derived($result, '85.1');
        $crossing = array_values(array_filter($derived->details, fn (CollectionDetail $detail) => $detail->isCrossing()));

        expect($derived->base->amount())->toBe('6548738')
            ->and($derived->commission->amount())->toBe('65487')
            ->and($derived->details)->toHaveCount(5)
            ->and($crossing)->toHaveCount(1)
            ->and($crossing[0]->key)->toBe('CR-85')
            ->and($crossing[0]->rate->percent())->toBe('1')
            ->and($crossing[0]->tranched)->toBeFalse()
            ->and($crossing[0]->commission->amount())->toBe('10000')
            ->and(Settle::code($result, 85)->collectionCommission->amount())->toBe('98231');
    });
});

describe('derived commissions without a base', function () {
    it('settles both derived commissions at zero when their codes have nothing', function () {
        $result = Settle::sales([]);

        expect(array_map(fn (DerivedSettlement $derived) => [$derived->rule->key, $derived->base->amount(), $derived->commission->amount()], $result->derivedCommissions()))
            ->toBe([['81.1', '0', '0'], ['85.1', '0', '0']]);
    });

    it('settles at zero a derived commission whose source code has no parameters', function () {
        $book = Settle::book(derived: [Params::derived('99.1', source: 99)]);
        $result = Settle::using($book, new SettlementInput(Period::of(2026, 7), sales: [Lines::sale(99, 1_000_000)]));

        expect(Settle::derived($result, '99.1')->base->amount())->toBe('0')
            ->and(Settle::derived($result, '99.1')->commission->amount())->toBe('0')
            ->and(Settle::references($result, AlertKind::UnknownSellerCode))->toBe(['99']);
    });
});
