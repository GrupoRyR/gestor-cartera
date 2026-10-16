<?php

use App\Modules\Comisiones\Domain\Calculation\AlertKind;
use App\Modules\Comisiones\Domain\Calculation\CollectionDetail;
use App\Modules\Comisiones\Domain\Calculation\CollectionSource;
use App\Modules\Comisiones\Domain\Input\SettlementInput;
use App\Modules\Comisiones\Domain\Parameters\Options\CurrencyTreatment;
use App\Modules\Comisiones\Domain\Parameters\SettlementOptions;
use App\Modules\Comisiones\Domain\Shared\Period;
use Tests\Modules\Comisiones\Support\Lines;
use Tests\Modules\Comisiones\Support\Settle;

describe('Annex 2 collections, code 14 (August 2026)', function () {
    beforeEach(function () {
        $this->annexLines = [
            Lines::payment(14, 2_100_840, paymentDate: '2026-08-05', invoiceDate: '2026-04-28', key: 'P1-8225-1', invoice: '8225'),
            Lines::payment(14, 10_560_866, paymentDate: '2026-08-11', invoiceDate: '2026-06-30', key: 'P2-9098-1', invoice: '9098'),
        ];
    });

    it('commissions invoices 8225 and 9098 line by line', function (string $key, int $days, string $paid, string $share, string $exact, string $rounded) {
        $result = Settle::collections('2026-08', $this->annexLines);
        $detail = Settle::detail($result, 14, $key);

        expect($detail->days)->toBe($days)
            ->and($detail->paidBase->amount())->toBe($paid)
            ->and($detail->tranched)->toBeTrue()
            ->and($detail->share->percent())->toBe($share)
            ->and($detail->rate->percent())->toBe('0.75')
            ->and($detail->commission->amount())->toBe($exact)
            ->and($detail->commission->rounded()->amount())->toBe($rounded)
            ->and($detail->source)->toBe(CollectionSource::Payment)
            ->and($detail->isCrossing())->toBeFalse()
            ->and($detail->sellerCode)->toBe(14);
    })->with([
        '8225: 99 days at 50 %' => ['P1-8225-1', 99, '2100840', '50', '7878.15', '7878'],
        '9098: 42 days at 100 %' => ['P2-9098-1', 42, '10560866', '100', '79206.495', '79206'],
    ]);

    it('settles the 10 transactions of code 14 (the other 8 lines are synthetic: the spec gives only totals)', function () {
        $synthetic = [
            Lines::payment(14, 8_000_000, paymentDate: '2026-08-20', days: 30),
            Lines::payment(14, 6_500_000, paymentDate: '2026-08-20', days: 30),
            Lines::payment(14, 5_000_000, paymentDate: '2026-08-20', days: 30),
            Lines::payment(14, 4_000_000, paymentDate: '2026-08-20', days: 30),
            Lines::payment(14, 3_494_290, paymentDate: '2026-08-20', days: 30),
            Lines::payment(14, 2_000_000, paymentDate: '2026-08-20', days: 30),
            Lines::payment(14, 1_737_030, paymentDate: '2026-08-25', days: 85),
            Lines::payment(14, 1_500_000, paymentDate: '2026-08-28', days: 130),
        ];
        $result = Settle::collections('2026-08', [...$synthetic, ...$this->annexLines]);
        $settlement = Settle::code($result, 14);

        expect($settlement->transactions())->toBe(10)
            ->and($settlement->collected->amount())->toBe('44893026')
            ->and($settlement->collectionCommission->amount())->toBe('314964')
            ->and($settlement->saleCommission->amount())->toBe('0')
            ->and($settlement->total->amount())->toBe('314964')
            ->and($settlement->hasActivity())->toBeTrue()
            ->and($result->controls()->loadedCollections->amount())->toBe('44893026')
            ->and($result->controls()->settledCollections->amount())->toBe('44893026')
            ->and($result->controls()->unassignedCollections->amount())->toBe('0')
            ->and($result->controls()->collectionsReconcile())->toBeTrue()
            ->and($result->alerts())->toBe([]);
    });

    it('lists the lines by payment date, then key', function () {
        $result = Settle::collections('2026-08', [
            Lines::payment(14, 1_000, paymentDate: '2026-08-20', key: 'B'),
            Lines::payment(14, 1_000, paymentDate: '2026-08-20', key: 'A'),
            ...array_reverse($this->annexLines),
        ]);

        expect(array_map(fn (CollectionDetail $detail) => $detail->key, Settle::code($result, 14)->collectionDetails))
            ->toBe(['P1-8225-1', 'P2-9098-1', 'A', 'B']);
    });

    it('keeps the invoice data of each line', function () {
        $result = Settle::collections('2026-08', [
            Lines::payment(14, 2_100_840, paymentDate: '2026-08-05', invoiceDate: '2026-04-28', invoiceAmount: 2_500_000, invoice: '8225', client: 'C-14'),
        ]);
        $detail = Settle::code($result, 14)->collectionDetails[0];

        expect($detail->invoiceNumber)->toBe('8225')
            ->and($detail->invoiceDate->format('Y-m-d'))->toBe('2026-04-28')
            ->and($detail->paymentDate->format('Y-m-d'))->toBe('2026-08-05')
            ->and($detail->invoiceAmount->amount())->toBe('2500000')
            ->and($detail->clientCode)->toBe('C-14')
            ->and($detail->clientLabel())->toBe('Cliente C-14');
    });
});

describe('code 85 without tranches (August 2026)', function () {
    it('pays the full 1.5 % on every payment, even one 200 days late', function () {
        $result = Settle::collections('2026-08', [
            Lines::payment(85, 2_000_000, paymentDate: '2026-08-10', days: 10),
            Lines::payment(85, 1_500_000, paymentDate: '2026-08-12', days: 70),
            Lines::payment(85, 1_048_738, paymentDate: '2026-08-18', days: 100),
            Lines::payment(85, 1_000_000, paymentDate: '2026-08-25', days: 200),
        ]);
        $settlement = Settle::code($result, 85);

        expect($settlement->collected->amount())->toBe('5548738')
            ->and($settlement->collectionCommission->amount())->toBe('83231')
            ->and($settlement->saleCommission->amount())->toBe('0')
            ->and(array_map(fn (CollectionDetail $detail) => $detail->share->percent(), $settlement->collectionDetails))
            ->toBe(['100', '100', '100', '100'])
            ->and(array_map(fn (CollectionDetail $detail) => $detail->tranched, $settlement->collectionDetails))
            ->toBe([false, false, false, false])
            ->and($settlement->collectionDetails[3]->days)->toBe(200)
            ->and($settlement->collectionDetails[3]->rate->percent())->toBe('1.5')
            ->and($settlement->collectionDetails[3]->commission->amount())->toBe('15000');
    });
});

describe('collection tranches (§5.3)', function () {
    it('pays the share of the tranche the days fall in', function (int $days, string $share, string $commission) {
        $result = Settle::collections('2026-08', [Lines::payment(34, 1_000_000, paymentDate: '2026-08-20', days: $days)]);
        $detail = Settle::code($result, 34)->collectionDetails[0];

        expect($detail->days)->toBe($days)
            ->and($detail->share->percent())->toBe($share)
            ->and($detail->commission->amount())->toBe($commission);
    })->with([
        '65 days' => [65, '100', '7500'],
        '66 days' => [66, '90', '6750'],
        '80 days' => [80, '90', '6750'],
        '81 days' => [81, '80', '6000'],
        '95 days' => [95, '80', '6000'],
        '96 days' => [96, '50', '3750'],
        '125 days' => [125, '50', '3750'],
        '126 days' => [126, '0', '0'],
    ]);

    it('counts a payment dated before its invoice as 0 days and warns about it', function () {
        $result = Settle::collections('2026-08', [
            Lines::payment(34, 1_000_000, paymentDate: '2026-08-05', invoiceDate: '2026-08-20', key: 'P9-1', invoice: '9001'),
        ]);
        $detail = Settle::code($result, 34)->collectionDetails[0];
        $alerts = $result->alertsOf(AlertKind::PaymentBeforeInvoice);

        expect($detail->days)->toBe(0)
            ->and($detail->share->percent())->toBe('100')
            ->and($detail->commission->amount())->toBe('7500')
            ->and($alerts)->toHaveCount(1)
            ->and($alerts[0]->reference)->toBe('P9-1')
            ->and($alerts[0]->sellerCode)->toBe(34)
            ->and($alerts[0]->isBlocking())->toBeFalse()
            ->and($alerts[0]->message)->toBe('El pago P9-1 tiene fecha anterior a su factura 9001; se contó con 0 días.');
    });

    it('settles each partial payment of an invoice with its own days', function () {
        $result = Settle::collections('2026-08', [
            Lines::payment(34, 600_000, paymentDate: '2026-08-03', invoiceDate: '2026-06-01', key: 'P1-7001-1', invoice: '7001'),
            Lines::payment(34, 400_000, paymentDate: '2026-08-20', invoiceDate: '2026-06-01', key: 'P2-7001-1', invoice: '7001'),
        ]);
        $settlement = Settle::code($result, 34);

        expect($settlement->transactions())->toBe(2)
            ->and(Settle::detail($result, 34, 'P1-7001-1')->days)->toBe(63)
            ->and(Settle::detail($result, 34, 'P1-7001-1')->commission->amount())->toBe('4500')
            ->and(Settle::detail($result, 34, 'P2-7001-1')->days)->toBe(80)
            ->and(Settle::detail($result, 34, 'P2-7001-1')->commission->amount())->toBe('2700')
            ->and($settlement->collected->amount())->toBe('1000000')
            ->and($settlement->collectionCommission->amount())->toBe('7200');
    });

    it('rounds the code total once, half up, not each line', function () {
        // 0.75 % of 100 is 0.75 per line: three lines are 2.25, which rounds to 2 (not 3).
        $result = Settle::collections('2026-08', [
            Lines::payment(34, 100, paymentDate: '2026-08-10', days: 1),
            Lines::payment(34, 100, paymentDate: '2026-08-11', days: 1),
            Lines::payment(34, 100, paymentDate: '2026-08-12', days: 1),
        ]);

        expect(Settle::code($result, 34)->collectionCommission->amount())->toBe('2');
    });
});

describe('codes without collection commission (§5.3, §5.5)', function () {
    it('counts the collections of codes with a zero collection rate without paying them', function (int $code) {
        $result = Settle::collections('2026-08', [Lines::payment($code, 1_000_000, paymentDate: '2026-08-10', days: 10)]);
        $settlement = Settle::code($result, $code);

        expect($settlement->collected->amount())->toBe('1000000')
            ->and($settlement->collectionCommission->amount())->toBe('0')
            ->and($settlement->collectionDetails[0]->rate->percent())->toBe('0')
            ->and($result->controls()->collectionsReconcile())->toBeTrue();
    })->with([
        '50' => [50],
        '53' => [53],
        '81' => [81],
        '84' => [84],
        '90' => [90],
        'control 80' => [80],
        'control 100' => [100],
    ]);
});

describe('collection line filtering (§5.1)', function () {
    it('ignores the payments outside the period silently', function () {
        $result = Settle::collections('2026-08', [
            Lines::payment(34, 1_000_000, paymentDate: '2026-07-28', days: 5),
            Lines::payment(34, 2_000_000, paymentDate: '2026-08-01', days: 5),
            Lines::payment(34, 4_000_000, paymentDate: '2026-09-01', days: 5),
        ]);

        expect(Settle::code($result, 34)->collected->amount())->toBe('2000000')
            ->and($result->controls()->loadedCollections->amount())->toBe('2000000')
            ->and($result->alerts())->toBe([]);
    });

    it('counts a repeated payment once and flags it', function () {
        $result = Settle::collections('2026-08', [
            Lines::payment(34, 1_000_000, paymentDate: '2026-08-10', key: 'P5-1'),
            Lines::payment(34, 1_000_000, paymentDate: '2026-08-10', key: 'P5-1'),
        ]);

        expect(Settle::code($result, 34)->collected->amount())->toBe('1000000')
            ->and(Settle::references($result, AlertKind::DuplicateLine))->toBe(['pago:P5-1']);
    });

    it('leaves an annulled payment out and warns about it', function () {
        $result = Settle::collections('2026-08', [
            Lines::payment(34, 1_000_000, paymentDate: '2026-08-10'),
            Lines::payment(34, 5_000_000, paymentDate: '2026-08-12', key: 'P6-1', annulled: true),
        ]);
        $alerts = $result->alertsOf(AlertKind::AnnulledCollection);

        expect(Settle::code($result, 34)->collected->amount())->toBe('1000000')
            ->and(Settle::code($result, 34)->transactions())->toBe(1)
            ->and($result->controls()->loadedCollections->amount())->toBe('1000000')
            ->and($result->controls()->collectionsReconcile())->toBeTrue()
            ->and($alerts)->toHaveCount(1)
            ->and($alerts[0]->reference)->toBe('P6-1')
            ->and($alerts[0]->sellerCode)->toBe(34)
            ->and($alerts[0]->isBlocking())->toBeFalse()
            ->and($alerts[0]->message)->toBe('El pago P6-1 está anulado; no se liquida.');
    });

    it('leaves payments of unknown codes unassigned and blocks the settlement', function () {
        $result = Settle::collections('2026-08', [
            Lines::payment(34, 1_000_000, paymentDate: '2026-08-10'),
            Lines::payment(99, 300_000, paymentDate: '2026-08-10'),
            Lines::payment('SIN ASESOR', 200_000, paymentDate: '2026-08-10'),
        ]);
        $controls = $result->controls();

        expect($result->code(99))->toBeNull()
            ->and(Settle::references($result, AlertKind::UnknownSellerCode))->toBe(['99', 'SIN ASESOR'])
            ->and($controls->loadedCollections->amount())->toBe('1500000')
            ->and($controls->settledCollections->amount())->toBe('1000000')
            ->and($controls->unassignedCollections->amount())->toBe('500000')
            ->and($controls->collectionsReconcile())->toBeFalse()
            ->and($result->hasBlockingAlerts())->toBeTrue();
    });

    it('raises one unknown-code alert for the sales and the payments of a code', function () {
        $result = Settle::run(new SettlementInput(
            Period::of(2026, 8),
            sales: [Lines::sale(99, 1_000_000, date: '2026-08-10')],
            payments: [Lines::payment(99, 300_000, paymentDate: '2026-08-10')],
        ));

        expect(Settle::references($result, AlertKind::UnknownSellerCode))->toBe(['99'])
            ->and($result->controls()->unassignedSales->amount())->toBe('1000000')
            ->and($result->controls()->unassignedCollections->amount())->toBe('300000');
    });

    it('keeps a foreign currency payment as reported and warns about it', function () {
        $result = Settle::collections('2026-08', [
            Lines::payment(34, 1_000, paymentDate: '2026-08-10', key: 'P7-1', currency: 'USD', exchangeRate: '4000'),
        ]);

        expect(Settle::code($result, 34)->collected->amount())->toBe('1000')
            ->and(Settle::references($result, AlertKind::ForeignCurrency))->toBe(['P7-1']);
    });

    it('converts a foreign currency payment to pesos, to cents, when asked to', function () {
        $result = Settle::collections(
            '2026-08',
            [Lines::payment(34, '1000.333', paymentDate: '2026-08-10', days: 10, invoiceAmount: '1190.4', currency: 'USD', exchangeRate: '3950.55')],
            options: new SettlementOptions(currency: CurrencyTreatment::Convert),
        );
        $detail = Settle::code($result, 34)->collectionDetails[0];

        expect($detail->paidBase->amount())->toBe('3951865.53')
            ->and($detail->invoiceAmount->amount())->toBe('4702734.72')
            ->and($detail->commission->amount())->toBe('29638.991475')
            ->and(Settle::code($result, 34)->collectionCommission->amount())->toBe('29639')
            ->and($result->controls()->loadedCollections->amount())->toBe('3951865.53')
            ->and($result->alerts())->toBe([]);
    });

    it('still warns when converting a foreign currency at an exchange rate of 1 (§14.7)', function (string $currency, string $rate, array $references) {
        $result = Settle::collections(
            '2026-08',
            [Lines::payment(34, 1_000, paymentDate: '2026-08-10', key: 'P8-1', currency: $currency, exchangeRate: $rate)],
            options: new SettlementOptions(currency: CurrencyTreatment::Convert),
        );
        $alerts = $result->alertsOf(AlertKind::ForeignCurrency);

        expect(Settle::code($result, 34)->collected->amount())->toBe('1000')
            ->and(Settle::references($result, AlertKind::ForeignCurrency))->toBe($references);

        if ($alerts !== []) {
            expect($alerts[0]->isBlocking())->toBeFalse()
                ->and($alerts[0]->message)->toBe('La línea P8-1 viene en USD con TRM 1; revisa la TRM, se tomó el valor reportado.');
        }
    })->with([
        'USD at 1' => ['USD', '1', ['P8-1']],
        'COP at 1' => ['COP', '1', []],
    ]);
});

describe('negative collections', function () {
    it('keeps a negative collection commission and blocks the settlement', function () {
        $result = Settle::collections('2026-08', [Lines::payment(34, -1_000_000, paymentDate: '2026-08-10', days: 10)]);

        expect(Settle::code($result, 34)->collectionCommission->amount())->toBe('-7500')
            ->and(Settle::code($result, 34)->total->amount())->toBe('-7500')
            ->and(Settle::references($result, AlertKind::NegativeCommission))->toBe(['34']);
    });
});
