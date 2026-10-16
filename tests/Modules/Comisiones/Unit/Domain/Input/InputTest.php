<?php

use App\Modules\Comisiones\Domain\Input\AdjustmentKind;
use App\Modules\Comisiones\Domain\Input\ClientAssignment;
use App\Modules\Comisiones\Domain\Input\CrossingSource;
use App\Modules\Comisiones\Domain\Input\CrossingType;
use App\Modules\Comisiones\Domain\Input\DocumentType;
use App\Modules\Comisiones\Domain\Input\ManualAdjustment;
use App\Modules\Comisiones\Domain\Input\ObrSale;
use App\Modules\Comisiones\Domain\Input\SaleLine;
use App\Modules\Comisiones\Domain\Input\SettlementInput;
use App\Modules\Comisiones\Domain\Shared\Money;
use App\Modules\Comisiones\Domain\Shared\Percentage;
use App\Modules\Comisiones\Domain\Shared\Period;
use Tests\Modules\Comisiones\Support\Lines;

describe('DocumentType', function () {
    it('knows which documents reduce sales', function (DocumentType $type, bool $credit, string $label) {
        expect($type->isCredit())->toBe($credit)
            ->and($type->label())->toBe($label);
    })->with([
        'invoice' => [DocumentType::Invoice, false, 'Factura'],
        'credit note' => [DocumentType::CreditNote, true, 'Nota crédito'],
        'debit note' => [DocumentType::DebitNote, false, 'Nota débito'],
        'return' => [DocumentType::Return, true, 'Devolución'],
    ]);
});

describe('lines', function () {
    it('dates each line by the day that assigns its period', function () {
        expect(Lines::sale(14, 1_000, date: '2026-07-31')->periodDate()->format('Y-m-d'))->toBe('2026-07-31')
            ->and(Lines::payment(14, 1_000, paymentDate: '2026-08-05')->periodDate()->format('Y-m-d'))->toBe('2026-08-05')
            ->and(Lines::crossing(1_000, crossingDate: '2026-09-16')->periodDate()->format('Y-m-d'))->toBe('2026-09-16');
    });

    it('keeps the calendar day of a date with a time and a timezone', function () {
        $line = new SaleLine(
            key: 'F1-1',
            sellerCode: 14,
            sellerText: '14 MONICA RIVERA AREVALO',
            documentNumber: 'F1',
            documentDate: new DateTimeImmutable('2026-07-31 22:30', new DateTimeZone('America/Bogota')),
            documentType: DocumentType::Invoice,
            clientCode: 'C001',
            clientName: 'Cliente C001',
            total: Money::of(1_000),
        );

        expect($line->documentDate->format('Y-m-d H:i e'))->toBe('2026-07-31 00:00 UTC')
            ->and($line->currency)->toBe('COP')
            ->and($line->exchangeRate)->toBe('1')
            ->and($line->annulled)->toBeFalse();
    });

    it('detects a currency other than pesos, whatever the exchange rate', function (string $currency, string $rate, bool $foreign) {
        expect(Lines::sale(14, 1_000, currency: $currency, exchangeRate: $rate)->isForeignCurrency())->toBe($foreign)
            ->and(Lines::payment(14, 1_000, currency: $currency, exchangeRate: $rate)->isForeignCurrency())->toBe($foreign)
            ->and(Lines::crossing(1_000, currency: $currency, exchangeRate: $rate)->isForeignCurrency())->toBe($foreign);
    })->with([
        'pesos' => ['COP', '1', false],
        'pesos with decimals in the rate' => ['COP', '1.00', false],
        'pesos in lower case' => ['cop', '1', false],
        'dollars' => ['USD', '4000', true],
        'dollars at one' => ['USD', '1', true],
        'pesos with a rate, which never makes them foreign' => ['COP', '3950.5', false],
    ]);

    it('rejects a line without key', function (Closure $build) {
        $build();
    })->throws(InvalidArgumentException::class)->with([
        'sale' => [fn () => Lines::sale(14, 1_000, key: ' ')],
        'payment' => [fn () => Lines::payment(14, 1_000, key: '')],
        'crossing' => [fn () => Lines::crossing(1_000, key: ' ')],
    ]);

    it('rejects an exchange rate that is not a positive plain decimal', function (Closure $build) {
        $build();
    })->throws(InvalidArgumentException::class)->with([
        'zero' => [fn () => Lines::sale(14, 1_000, exchangeRate: '0')],
        'negative' => [fn () => Lines::payment(14, 1_000, exchangeRate: '-1')],
        'decimal comma' => [fn () => Lines::crossing(1_000, exchangeRate: '3950,5')],
        'empty' => [fn () => Lines::sale(14, 1_000, exchangeRate: '')],
    ]);

    it('rejects a blank currency', function () {
        Lines::sale(14, 1_000, currency: ' ');
    })->throws(InvalidArgumentException::class);
});

describe('manual captures', function () {
    it('keeps a client assignment from its first day', function () {
        $assignment = new ClientAssignment('C041', 41, new DateTimeImmutable('2026-08-01 15:00'));

        expect($assignment->effectiveFrom->format('Y-m-d H:i'))->toBe('2026-08-01 00:00')
            ->and($assignment->sellerCode)->toBe(41);
    });

    it('rejects a client assignment without client', function () {
        new ClientAssignment(' ', 41, new DateTimeImmutable('2026-08-01'));
    })->throws(InvalidArgumentException::class);

    it('rejects a negative budget', function () {
        Lines::budget(14, -1);
    })->throws(InvalidArgumentException::class);

    it('accepts a zero budget', function () {
        expect(Lines::budget(14, 0)->amount->isZero())->toBeTrue();
    });

    it('keeps an OBR sale with its rate', function () {
        $sale = new ObrSale(14, 'Escudos obra norte', Money::of(10_000_000), Percentage::of(1));

        expect($sale->rate->percent())->toBe('1')
            ->and($sale->soldValue->amount())->toBe('10000000');
    });

    it('rejects an adjustment that is not positive or has no description', function (string $amount, string $description) {
        new ManualAdjustment(14, AdjustmentKind::Deduction, Money::of($amount), $description);
    })->throws(InvalidArgumentException::class)->with([
        'zero' => ['0', 'Anticipo'],
        'negative' => ['-100', 'Anticipo'],
        'blank description' => ['100', ' '],
    ]);

    it('labels every enum in Spanish', function () {
        expect(AdjustmentKind::Reimbursement->label())->toBe('Reintegro')
            ->and(AdjustmentKind::Deduction->label())->toBe('Descuento')
            ->and(CrossingType::Advance->label())->toBe('Anticipo')
            ->and(CrossingType::ClientSupplier->label())->toBe('Compensación cliente-proveedor')
            ->and(CrossingType::Other->label())->toBe('Otro')
            ->and(CrossingSource::Sap->label())->toBe('SAP')
            ->and(CrossingSource::Manual->label())->toBe('Manual');
    });
});

describe('SettlementInput', function () {
    it('starts empty except for the period', function () {
        $input = new SettlementInput(Period::of(2026, 7));

        expect($input->period->key())->toBe('2026-07')
            ->and($input->sales)->toBe([])
            ->and($input->payments)->toBe([])
            ->and($input->crossings)->toBe([])
            ->and($input->assignments)->toBe([])
            ->and($input->budgets)->toBe([])
            ->and($input->obrSales)->toBe([])
            ->and($input->adjustments)->toBe([]);
    });

    it('carries every kind of line', function () {
        $input = new SettlementInput(
            Period::of(2026, 8),
            sales: [Lines::sale(14, 1_000)],
            payments: [Lines::payment(14, 1_000)],
            crossings: [Lines::crossing(1_000)],
            assignments: [new ClientAssignment('C001', 41, new DateTimeImmutable('2026-01-01'))],
            budgets: [Lines::budget(14, 1_000)],
            obrSales: [new ObrSale(14, 'Escudos', Money::of(1_000), Percentage::of(1))],
            adjustments: [new ManualAdjustment(14, AdjustmentKind::Reimbursement, Money::of(50_000), 'Viáticos')],
        );

        expect($input->sales)->toHaveCount(1)
            ->and($input->payments)->toHaveCount(1)
            ->and($input->crossings)->toHaveCount(1)
            ->and($input->assignments)->toHaveCount(1)
            ->and($input->budgets)->toHaveCount(1)
            ->and($input->obrSales)->toHaveCount(1)
            ->and($input->adjustments)->toHaveCount(1);
    });
});
