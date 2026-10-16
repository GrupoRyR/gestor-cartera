<?php

use App\Modules\Comisiones\Domain\Calculation\Alert;
use App\Modules\Comisiones\Domain\Calculation\AlertKind;
use App\Modules\Comisiones\Domain\Calculation\CollectionSource;
use App\Modules\Comisiones\Domain\Input\SettlementInput;
use App\Modules\Comisiones\Domain\Parameters\Options\CrossingSellerDate;
use App\Modules\Comisiones\Domain\Parameters\Options\CurrencyTreatment;
use App\Modules\Comisiones\Domain\Parameters\SettlementOptions;
use App\Modules\Comisiones\Domain\Shared\Period;
use Tests\Modules\Comisiones\Support\Lines;
use Tests\Modules\Comisiones\Support\Settle;

describe('Annex 2 crossing, code 41 (September 2026)', function () {
    beforeEach(function () {
        $this->result = Settle::collections(
            '2026-09',
            payments: [
                // Synthetic: the spec gives only the code total.
                Lines::payment(41, 50_000_000, paymentDate: '2026-09-10', days: 20),
                Lines::payment(41, 40_000_000, paymentDate: '2026-09-15', days: 20),
                Lines::payment(41, 18_788_323, paymentDate: '2026-09-25', days: 20),
            ],
            crossings: [
                Lines::crossing(
                    2_713_450,
                    crossingDate: '2026-09-16',
                    invoiceDate: '2026-08-19',
                    key: 'CR-41',
                    invoiceAmount: 2_713_450,
                    taxBase: 2_280_210,
                    client: 'C-41',
                    invoiceVat: 433_240,
                ),
            ],
            assignments: [Lines::assignment('C-41', 41, '2026-01-01')],
        );
    });

    it('commissions the crossing on its base without VAT', function () {
        $detail = Settle::detail($this->result, 41, 'CR-41');

        expect($detail->days)->toBe(28)
            ->and($detail->paidBase->amount())->toBe('2280210')
            ->and($detail->invoiceAmount->amount())->toBe('2713450')
            ->and($detail->share->percent())->toBe('100')
            ->and($detail->commission->amount())->toBe('17101.575')
            ->and($detail->commission->rounded()->amount())->toBe('17102')
            ->and($detail->paymentDate->format('Y-m-d'))->toBe('2026-09-16')
            ->and($detail->source)->toBe(CollectionSource::Crossing)
            ->and($detail->isCrossing())->toBeTrue()
            ->and($detail->sellerCode)->toBe(41)
            ->and($detail->clientLabel())->toBe('Cliente C-41 (cruce)');
    });

    it('adds the crossing to the collection commission of code 41', function () {
        $settlement = Settle::code($this->result, 41);

        expect($settlement->transactions())->toBe(4)
            ->and($settlement->collected->amount())->toBe('111068533')
            ->and($settlement->collectionCommission->amount())->toBe('833014')
            ->and($this->result->controls()->loadedCollections->amount())->toBe('111068533')
            ->and($this->result->controls()->collectionsReconcile())->toBeTrue()
            ->and($this->result->alerts())->toBe([]);
    });
});

describe('crossing seller (§5.4, §14.5)', function () {
    beforeEach(function () {
        // Client C-9 moved from code 14 to code 15 between the invoice and the crossing.
        $this->crossing = Lines::crossing(1_000_000, crossingDate: '2026-09-16', invoiceDate: '2026-08-19', key: 'CR-9', taxBase: 1_000_000, client: 'C-9');
        $this->assignments = [
            Lines::assignment('C-9', 15, '2026-09-01'),
            Lines::assignment('C-9', 14, '2026-01-01'),
            Lines::assignment('C-8', 16, '2026-01-01'),
        ];
    });

    it('goes to the seller of the client on the crossing date by default', function () {
        $result = Settle::collections('2026-09', crossings: [$this->crossing], assignments: $this->assignments);

        expect(Settle::code($result, 15)->transactions())->toBe(1)
            ->and(Settle::code($result, 14)->transactions())->toBe(0);
    });

    it('goes to the seller of the client on the invoice date when asked to', function () {
        $result = Settle::collections(
            '2026-09',
            crossings: [$this->crossing],
            assignments: $this->assignments,
            options: new SettlementOptions(crossingSeller: CrossingSellerDate::InvoiceDate),
        );

        expect(Settle::code($result, 14)->transactions())->toBe(1)
            ->and(Settle::code($result, 15)->transactions())->toBe(0);
    });

    it('lets a seller set by a user win over the assignment', function () {
        $crossing = Lines::crossing(1_000_000, crossingDate: '2026-09-16', taxBase: 1_000_000, sellerOverride: 16, client: 'C-9');
        $result = Settle::collections('2026-09', crossings: [$crossing], assignments: $this->assignments);

        expect(Settle::code($result, 16)->transactions())->toBe(1)
            ->and(Settle::code($result, 15)->transactions())->toBe(0);
    });

    it('takes the latest assignment row when two start the same day', function () {
        $result = Settle::collections('2026-09', crossings: [$this->crossing], assignments: [
            Lines::assignment('C-9', 14, '2026-09-01'),
            Lines::assignment('C-9', 17, '2026-09-01'),
        ]);

        expect(Settle::code($result, 17)->transactions())->toBe(1)
            ->and(Settle::code($result, 14)->transactions())->toBe(0);
    });

    it('leaves out a crossing whose client had no seller and blocks the settlement', function (array $assignments) {
        $result = Settle::collections('2026-09', crossings: [$this->crossing], assignments: $assignments);
        $alerts = $result->alertsOf(AlertKind::ClientWithoutSeller);
        $controls = $result->controls();

        expect($alerts)->toHaveCount(1)
            ->and($alerts[0]->reference)->toBe('CR-9')
            ->and($alerts[0]->sellerCode)->toBeNull()
            ->and($alerts[0]->isBlocking())->toBeTrue()
            ->and($alerts[0]->message)->toBe('El cruce CR-9 es del cliente C-9 (Cliente C-9), que no tenía vendedor asignado el 16/09/2026; asígnale uno o corrige el cruce.')
            ->and(Settle::code($result, 14)->transactions())->toBe(0)
            ->and($controls->loadedCollections->amount())->toBe('1000000')
            ->and($controls->settledCollections->amount())->toBe('0')
            ->and($controls->unassignedCollections->amount())->toBe('1000000')
            ->and($controls->collectionsReconcile())->toBeFalse();
    })->with([
        'no assignment at all' => [[]],
        'assigned only after the crossing' => [[Lines::assignment('C-9', 14, '2026-09-17')]],
        'only other clients assigned' => [[Lines::assignment('C-8', 14, '2026-01-01')]],
    ]);

    it('flags a crossing assigned to a code without parameters', function () {
        $result = Settle::collections('2026-09', crossings: [$this->crossing], assignments: [Lines::assignment('C-9', 99, '2026-01-01')]);

        expect(Settle::references($result, AlertKind::UnknownSellerCode))->toBe(['99'])
            ->and($result->controls()->unassignedCollections->amount())->toBe('1000000');
    });
});

describe('crossing base without VAT (§5.4)', function () {
    beforeEach(function () {
        $this->assignments = [Lines::assignment('C001', 34)];
    });

    it('finds the base of the crossed amount', function (array $amounts, string $base) {
        $result = Settle::collections('2026-09', crossings: [Lines::crossing(...$amounts)], assignments: $this->assignments);

        expect(Settle::code($result, 34)->collectionDetails[0]->paidBase->amount())->toBe($base)
            ->and($result->alerts())->toBe([]);
    })->with([
        'from the invoice tax base' => [['crossed' => 1_190_000, 'invoiceAmount' => 1_190_000, 'taxBase' => 1_000_000], '1000000'],
        'in proportion to a partial crossing' => [['crossed' => 595_000, 'invoiceAmount' => 1_190_000, 'taxBase' => 1_000_000], '500000'],
        'to cents, half up' => [['crossed' => 1_000_000, 'invoiceAmount' => 1_190_000, 'taxBase' => 1_000_000], '840336.13'],
        'from the invoice VAT only' => [['crossed' => 595_000, 'invoiceAmount' => 1_190_000, 'invoiceVat' => 190_000], '500000'],
        'from the crossed amount without VAT' => [['crossed' => 500_000, 'crossedWithoutVat' => '420168.07'], '420168.07'],
        'from the crossed amount without VAT when the invoice amount is zero' => [['crossed' => 500_000, 'invoiceAmount' => 0, 'taxBase' => 0, 'crossedWithoutVat' => '420168.07'], '420168.07'],
    ]);

    it('leaves out a crossing without any base and blocks the settlement', function () {
        $result = Settle::collections('2026-09', crossings: [Lines::crossing(500_000, key: 'CR-7', invoice: '7007')], assignments: $this->assignments);
        $alerts = $result->alertsOf(AlertKind::CrossingWithoutBase);

        expect(Settle::code($result, 34)->transactions())->toBe(0)
            ->and($alerts)->toHaveCount(1)
            ->and($alerts[0]->reference)->toBe('CR-7')
            ->and($alerts[0]->sellerCode)->toBe(34)
            ->and($alerts[0]->isBlocking())->toBeTrue()
            ->and($alerts[0]->message)->toBe('El cruce CR-7 no trae la base sin IVA de la factura 7007 ni el valor cruzado sin IVA; no se puede liquidar.')
            ->and($result->controls()->loadedCollections->amount())->toBe('0');
    });

    it('raises both alerts for a crossing without seller and without base', function () {
        $result = Settle::collections('2026-09', crossings: [Lines::crossing(500_000, key: 'CR-8')]);

        expect(Settle::references($result, AlertKind::ClientWithoutSeller))->toBe(['CR-8'])
            ->and(Settle::references($result, AlertKind::CrossingWithoutBase))->toBe(['CR-8']);
    });
});

describe('crossing line filtering (§5.1)', function () {
    beforeEach(function () {
        $this->assignments = [Lines::assignment('C001', 34)];
    });

    it('assigns a crossing to the period of its crossing date', function () {
        $result = Settle::collections('2026-09', crossings: [
            Lines::crossing(1_000_000, crossingDate: '2026-08-31', taxBase: 1_000_000),
            Lines::crossing(2_000_000, crossingDate: '2026-09-30', taxBase: 2_000_000),
        ], assignments: $this->assignments);

        expect(Settle::code($result, 34)->collected->amount())->toBe('2000000');
    });

    it('leaves an annulled crossing out and warns about it', function () {
        $result = Settle::collections('2026-09', crossings: [
            Lines::crossing(1_000_000, key: 'CR-5', taxBase: 1_000_000, sellerOverride: 34, annulled: true),
        ], assignments: $this->assignments);
        $alerts = $result->alertsOf(AlertKind::AnnulledCollection);

        expect(Settle::code($result, 34)->transactions())->toBe(0)
            ->and($alerts)->toHaveCount(1)
            ->and($alerts[0]->reference)->toBe('cruce:CR-5')
            ->and($alerts[0]->sellerCode)->toBe(34)
            ->and($alerts[0]->message)->toBe('El cruce CR-5 está anulado; no se liquida.')
            ->and($result->controls()->loadedCollections->amount())->toBe('0');
    });

    it('counts a crossing dated before its invoice as 0 days and warns about it', function () {
        $result = Settle::collections('2026-09', crossings: [
            Lines::crossing(1_000_000, crossingDate: '2026-09-02', invoiceDate: '2026-09-10', key: 'CR-6', taxBase: 1_000_000, invoice: '6006'),
        ], assignments: $this->assignments);

        expect(Settle::code($result, 34)->collectionDetails[0]->days)->toBe(0)
            ->and(Settle::references($result, AlertKind::PaymentBeforeInvoice))->toBe(['cruce:CR-6'])
            ->and($result->alertsOf(AlertKind::PaymentBeforeInvoice)[0]->message)
            ->toBe('El cruce CR-6 tiene fecha anterior a su factura 6006; se contó con 0 días.');
    });

    it('keeps apart the alerts of a sale, a payment and a crossing that share a key', function () {
        $result = Settle::run(new SettlementInput(
            Period::of(2026, 9),
            sales: [Lines::sale(34, 1_000, date: '2026-09-02', key: 'K-1', currency: 'USD', exchangeRate: '4000')],
            payments: [
                Lines::payment(34, 1_000, paymentDate: '2026-09-02', invoiceDate: '2026-09-10', key: 'K-1', currency: 'USD', exchangeRate: '4000'),
                Lines::payment(34, 1_000, paymentDate: '2026-09-02', key: 'K-2', annulled: true),
            ],
            crossings: [
                Lines::crossing(1_000, crossingDate: '2026-09-02', invoiceDate: '2026-09-10', key: 'K-1', taxBase: 1_000, currency: 'USD', exchangeRate: '4000'),
                Lines::crossing(1_000, crossingDate: '2026-09-02', key: 'K-2', taxBase: 1_000, sellerOverride: 34, annulled: true),
            ],
            assignments: $this->assignments,
        ));

        expect(Settle::references($result, AlertKind::ForeignCurrency))->toBe(['venta:K-1', 'pago:K-1', 'cruce:K-1'])
            ->and(Settle::references($result, AlertKind::PaymentBeforeInvoice))->toBe(['pago:K-1', 'cruce:K-1'])
            ->and(Settle::references($result, AlertKind::AnnulledCollection))->toBe(['pago:K-2', 'cruce:K-2'])
            ->and(array_map(fn (Alert $alert) => $alert->key(), $result->alertsOf(AlertKind::AnnulledCollection)))
            ->toBe(['recaudo_anulado:pago:K-2', 'recaudo_anulado:cruce:K-2']);
    });

    it('converts the base of a foreign currency crossing to pesos when asked to', function () {
        $result = Settle::collections(
            '2026-09',
            crossings: [Lines::crossing(1_190, invoiceAmount: 1_190, taxBase: 1_000, currency: 'USD', exchangeRate: '4000')],
            assignments: $this->assignments,
            options: new SettlementOptions(currency: CurrencyTreatment::Convert),
        );
        $detail = Settle::code($result, 34)->collectionDetails[0];

        expect($detail->paidBase->amount())->toBe('4000000')
            ->and($detail->invoiceAmount->amount())->toBe('4760000')
            ->and($detail->commission->amount())->toBe('30000')
            ->and($result->alerts())->toBe([]);
    });
});
