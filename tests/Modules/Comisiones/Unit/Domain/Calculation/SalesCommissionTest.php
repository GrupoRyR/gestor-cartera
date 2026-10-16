<?php

use App\Modules\Comisiones\Domain\Calculation\Alert;
use App\Modules\Comisiones\Domain\Calculation\AlertCollector;
use App\Modules\Comisiones\Domain\Calculation\AlertKind;
use App\Modules\Comisiones\Domain\Calculation\LineFilter;
use App\Modules\Comisiones\Domain\Calculation\SettlementCalculator;
use App\Modules\Comisiones\Domain\Input\DocumentType;
use App\Modules\Comisiones\Domain\Input\SettlementInput;
use App\Modules\Comisiones\Domain\Parameters\CodeScope;
use App\Modules\Comisiones\Domain\Parameters\Options\ComplianceScope;
use App\Modules\Comisiones\Domain\Parameters\Options\CreditNoteTreatment;
use App\Modules\Comisiones\Domain\Parameters\Options\CurrencyTreatment;
use App\Modules\Comisiones\Domain\Parameters\Options\SalesBasis;
use App\Modules\Comisiones\Domain\Parameters\SettlementOptions;
use App\Modules\Comisiones\Domain\Shared\Period;
use Tests\Modules\Comisiones\Support\Lines;
use Tests\Modules\Comisiones\Support\Settle;

describe('Annex 2 sales (July 2026)', function () {
    beforeEach(function () {
        $this->result = Settle::sales(
            sales: [
                Lines::sale(14, 60_000_000, date: '2026-07-03'),
                Lines::sale(14, 9_915_134, date: '2026-07-20'),
                Lines::sale(16, 478_187_568),
                Lines::sale(15, 78_565_924),
            ],
            budgets: [
                Lines::budget(14, 77_241_857),
                Lines::budget(16, 442_043_556),
                Lines::budget(15, 370_321_392),
            ],
        );
    });

    it('pays the sales commission of each code', function (int $code, string $sales, string $budget, string $compliance, bool $meets, string $commission) {
        $settlement = Settle::code($this->result, $code);

        expect($settlement->sales->amount())->toBe($sales)
            ->and($settlement->budget?->amount())->toBe($budget)
            ->and($settlement->compliance->percent())->toBe($compliance)
            ->and($settlement->meetsMinimum)->toBe($meets)
            ->and($settlement->saleCommission->amount())->toBe($commission)
            ->and($settlement->total->amount())->toBe($commission);
    })->with([
        '14 Mónica Rivera, 90.5 % at 0.75 %' => [14, '69915134', '77241857', '90.5146', true, '524364'],
        '16 Carolina Ramírez, 108.2 % at 1.25 % without bonus' => [16, '478187568', '442043556', '108.1766', true, '5977345'],
        '15 Paola Vargas, 21.2 % below the minimum' => [15, '78565924', '370321392', '21.2156', false, '0'],
    ]);

    it('keeps every sales line of the code, by date', function () {
        $details = Settle::code($this->result, 14)->saleDetails;

        expect($details)->toHaveCount(2)
            ->and($details[0]->documentDate->format('Y-m-d'))->toBe('2026-07-03')
            ->and($details[0]->amount->amount())->toBe('60000000')
            ->and($details[0]->sellerCode)->toBe(14)
            ->and($details[1]->amount->amount())->toBe('9915134');
    });

    it('reconciles the loaded sales with the sales by code', function () {
        $controls = $this->result->controls();

        expect($controls->loadedSales->amount())->toBe('626668626')
            ->and($controls->salesByCode->amount())->toBe('626668626')
            ->and($controls->unassignedSales->amount())->toBe('0')
            ->and($controls->salesReconcile())->toBeTrue()
            ->and($controls->loadedCollections->amount())->toBe('0')
            ->and($controls->collectionsReconcile())->toBeTrue();
    });

    it('raises no alert', function () {
        expect($this->result->alerts())->toBe([])
            ->and($this->result->hasBlockingAlerts())->toBeFalse();
    });

    it('adds the regular totals', function () {
        expect($this->result->regularTotal()->amount())->toBe('6501709');
    });

    it('has no collections without payments, and leaves derived commissions, directors and persons for later', function () {
        $settlement = Settle::code($this->result, 14);

        expect($settlement->collected->amount())->toBe('0')
            ->and($settlement->collectionCommission->amount())->toBe('0')
            ->and($settlement->obrCommission->amount())->toBe('0')
            ->and($settlement->reimbursements->amount())->toBe('0')
            ->and($settlement->deductions->amount())->toBe('0')
            ->and($settlement->directorCommission->amount())->toBe('0')
            ->and($settlement->collectionDetails)->toBe([])
            ->and($settlement->transactions())->toBe(0)
            ->and($this->result->derivedCommissions())->toBe([])
            ->and($this->result->directors())->toBe([])
            ->and($this->result->persons())->toBe([]);
    });
});

describe('minimum compliance', function () {
    it('pays code 16 at 85 % (80 % minimum) but not a 90 % code', function () {
        $result = Settle::sales(
            sales: [Lines::sale(16, 85_000_000), Lines::sale(14, 85_000_000)],
            budgets: [Lines::budget(16, 100_000_000), Lines::budget(14, 100_000_000)],
        );

        expect(Settle::code($result, 16)->meetsMinimum)->toBeTrue()
            ->and(Settle::code($result, 16)->saleCommission->amount())->toBe('1062500')
            ->and(Settle::code($result, 14)->meetsMinimum)->toBeFalse()
            ->and(Settle::code($result, 14)->saleCommission->amount())->toBe('0');
    });

    it('pays exactly at the minimum', function () {
        $result = Settle::sales([Lines::sale(14, 90_000_000)], [Lines::budget(14, 100_000_000)]);

        expect(Settle::code($result, 14)->compliance->percent())->toBe('90')
            ->and(Settle::code($result, 14)->saleCommission->amount())->toBe('675000');
    });

    it('compares the exact amounts, not the displayed compliance', function () {
        $result = Settle::sales([Lines::sale(14, '89999999.99')], [Lines::budget(14, 100_000_000)]);

        expect(Settle::code($result, 14)->compliance->percent())->toBe('90')
            ->and(Settle::code($result, 14)->meetsMinimum)->toBeFalse()
            ->and(Settle::code($result, 14)->saleCommission->amount())->toBe('0');
    });

    it('adds every budget row of a code', function () {
        $result = Settle::sales(
            [Lines::sale(14, 90_000_000)],
            [Lines::budget(14, 60_000_000), Lines::budget(14, 40_000_000)],
        );

        expect(Settle::code($result, 14)->budget?->amount())->toBe('100000000')
            ->and(Settle::code($result, 14)->saleCommission->amount())->toBe('675000');
    });

    it('pays codes without minimum from the first peso and without budget', function (int $code, string $commission) {
        $result = Settle::sales([Lines::sale($code, 10_000_000)]);
        $settlement = Settle::code($result, $code);

        expect($settlement->budget)->toBeNull()
            ->and($settlement->hasBudget())->toBeFalse()
            ->and($settlement->compliance->percent())->toBe('0')
            ->and($settlement->meetsMinimum)->toBeTrue()
            ->and($settlement->saleCommission->amount())->toBe($commission)
            ->and($result->alerts())->toBe([]);
    })->with([
        '50 at 1 %' => [50, '100000'],
        '53 at 1 %' => [53, '100000'],
        '34 at 0.75 %' => [34, '75000'],
    ]);

    it('does not pay a code with minimum and no budget, and asks for the budget', function () {
        $result = Settle::sales([Lines::sale(14, 5_000_000)]);
        $alerts = $result->alertsOf(AlertKind::MissingBudget);

        expect(Settle::code($result, 14)->compliance->percent())->toBe('0')
            ->and(Settle::code($result, 14)->meetsMinimum)->toBeFalse()
            ->and(Settle::code($result, 14)->saleCommission->amount())->toBe('0')
            ->and($alerts)->toHaveCount(1)
            ->and($alerts[0]->reference)->toBe('14')
            ->and($alerts[0]->sellerCode)->toBe(14)
            ->and($alerts[0]->isBlocking())->toBeTrue()
            ->and($alerts[0]->message)->toBe('El código 14 tiene mínimo de cumplimiento y no tiene presupuesto cargado; su comisión por venta queda en cero.');
    });

    it('does not ask for a budget for the codes that sold nothing', function () {
        $result = Settle::sales([Lines::sale(16, 1_000_000)], [Lines::budget(16, 1_000_000)]);

        expect($result->alertsOf(AlertKind::MissingBudget))->toBe([]);
    });

    it('treats a zero budget as no budget', function () {
        $result = Settle::sales([Lines::sale(14, 5_000_000)], [Lines::budget(14, 0)]);

        expect(Settle::code($result, 14)->budget?->amount())->toBe('0')
            ->and(Settle::code($result, 14)->hasBudget())->toBeFalse()
            ->and(Settle::code($result, 14)->saleCommission->amount())->toBe('0')
            ->and(Settle::references($result, AlertKind::MissingBudget))->toBe(['14']);
    });
});

describe('compliance scope (§14.6)', function () {
    beforeEach(function () {
        $this->sales = [
            Lines::sale(32, 80_000_000),
            Lines::sale(34, 120_000_000),
            Lines::sale(17, 95_000_000),
        ];
        $this->budgets = [
            Lines::budget(32, 100_000_000),
            Lines::budget(34, 100_000_000),
            Lines::budget(17, 100_000_000),
        ];
    });

    it('measures each code against its own budget by default', function () {
        $result = Settle::sales($this->sales, $this->budgets);

        expect(Settle::code($result, 32)->compliance->percent())->toBe('80')
            ->and(Settle::code($result, 32)->saleCommission->amount())->toBe('0')
            ->and(Settle::code($result, 34)->saleCommission->amount())->toBe('900000');
    });

    it('measures the codes of a person together when asked to', function () {
        $result = Settle::sales(
            $this->sales,
            $this->budgets,
            new SettlementOptions(complianceScope: ComplianceScope::Person),
        );

        expect(Settle::code($result, 32)->compliance->percent())->toBe('100')
            ->and(Settle::code($result, 32)->meetsMinimum)->toBeTrue()
            ->and(Settle::code($result, 32)->saleCommission->amount())->toBe('600000')
            ->and(Settle::code($result, 34)->compliance->percent())->toBe('100')
            ->and(Settle::code($result, 34)->saleCommission->amount())->toBe('900000')
            ->and(Settle::code($result, 17)->compliance->percent())->toBe('95')
            ->and(Settle::code($result, 17)->saleCommission->amount())->toBe('712500');
    });

    it('leaves the codes of a person without budget out of the aggregate', function () {
        $result = Settle::sales(
            [Lines::sale(32, 80_000_000), Lines::sale(34, 120_000_000)],
            [Lines::budget(32, 100_000_000)],
            new SettlementOptions(complianceScope: ComplianceScope::Person),
        );

        expect(Settle::code($result, 32)->compliance->percent())->toBe('80')
            ->and(Settle::code($result, 32)->saleCommission->amount())->toBe('0')
            ->and(Settle::code($result, 34)->compliance->percent())->toBe('80');
    });

    it('measures a code without its own budget on the budget of its person', function (int $sales34, bool $meets, string $commission) {
        // Harold Marín: 32 has a 90 % minimum, 34 none; only 34 has a budget.
        $result = Settle::sales(
            [Lines::sale(32, 10_000_000), Lines::sale(34, $sales34)],
            [Lines::budget(34, 100_000_000)],
            new SettlementOptions(complianceScope: ComplianceScope::Person),
        );
        $settlement = Settle::code($result, 32);

        expect($settlement->hasBudget())->toBeFalse()
            ->and($settlement->meetsMinimum)->toBe($meets)
            ->and($settlement->saleCommission->amount())->toBe($commission)
            ->and($result->alertsOf(AlertKind::MissingBudget))->toBe([]);
    })->with([
        'the person reaches 95 %' => [95_000_000, true, '75000'],
        'the person stays at 85 %' => [85_000_000, false, '0'],
    ]);

    it('asks for a budget when no code of the person has one', function () {
        $result = Settle::sales(
            [Lines::sale(32, 10_000_000), Lines::sale(34, 95_000_000)],
            options: new SettlementOptions(complianceScope: ComplianceScope::Person),
        );

        expect(Settle::code($result, 32)->meetsMinimum)->toBeFalse()
            ->and(Settle::code($result, 32)->saleCommission->amount())->toBe('0')
            ->and(Settle::code($result, 34)->saleCommission->amount())->toBe('712500')
            ->and(Settle::references($result, AlertKind::MissingBudget))->toBe(['32']);
    });
});

describe('special codes', function () {
    it('loads the sales of the control codes without paying them', function () {
        $result = Settle::sales([
            Lines::sale(80, 3_000_000),
            Lines::sale(100, 1_000_000),
            Lines::sale(34, 2_000_000),
        ]);

        expect(Settle::code($result, 80)->sales->amount())->toBe('3000000')
            ->and(Settle::code($result, 80)->saleCommission->amount())->toBe('0')
            ->and(Settle::code($result, 100)->saleCommission->amount())->toBe('0')
            ->and(array_keys($result->control()))->toBe([80, 100])
            ->and($result->controls()->salesByCode->amount())->toBe('6000000')
            ->and($result->controls()->salesReconcile())->toBeTrue()
            ->and($result->regularTotal()->amount())->toBe('15000');
    });

    it('calculates the sales commission of the codes settled apart', function (int $code, string $commission) {
        $result = Settle::sales([Lines::sale($code, 10_000_000)]);

        expect(Settle::code($result, $code)->rule->scope)->toBe(CodeScope::Separate)
            ->and(Settle::code($result, $code)->saleCommission->amount())->toBe($commission)
            ->and(array_keys($result->separate()))->toBe([81, 84, 90])
            ->and($result->regularTotal()->amount())->toBe('0');
    })->with([
        '81 at 0.35 %' => [81, '35000'],
        '84 at 1 %' => [84, '100000'],
        '90 at 2 %' => [90, '200000'],
    ]);

    it('settles every code with a rule in the period, sorted, with or without activity', function () {
        $result = Settle::sales([Lines::sale(14, 1_000_000)], [Lines::budget(15, 1_000_000)]);
        $parameters = Settle::parameters(Period::of(2026, 7));

        expect(array_keys($result->codes()))->toBe(array_keys($parameters->codes()))
            ->and(Settle::code($result, 14)->hasActivity())->toBeTrue()
            ->and(Settle::code($result, 15)->hasActivity())->toBeTrue()
            ->and(Settle::code($result, 17)->hasActivity())->toBeFalse()
            ->and(Settle::code($result, 17)->total->amount())->toBe('0')
            ->and($result->code(99))->toBeNull();
    });
});

describe('unknown sellers', function () {
    it('leaves unknown codes and lines without code out of every code', function () {
        $result = Settle::sales(
            [
                Lines::sale(14, 90_000_000),
                Lines::sale(99, 1_000_000),
                Lines::sale('99 NUEVO ASESOR', 400_000),
                Lines::sale('SIN ASESOR', 500_000),
            ],
            [Lines::budget(14, 100_000_000)],
        );
        $alerts = $result->alertsOf(AlertKind::UnknownSellerCode);
        $controls = $result->controls();

        expect($result->code(99))->toBeNull()
            ->and(Settle::code($result, 14)->sales->amount())->toBe('90000000')
            ->and(Settle::references($result, AlertKind::UnknownSellerCode))->toBe(['99', 'SIN ASESOR'])
            ->and($alerts[0]->sellerCode)->toBe(99)
            ->and($alerts[0]->isBlocking())->toBeTrue()
            ->and($alerts[0]->message)->toBe('El código 99 no existe en los parámetros del período; sus líneas quedan sin asignar.')
            ->and($alerts[1]->sellerCode)->toBeNull()
            ->and($alerts[1]->message)->toBe('El vendedor «SIN ASESOR» no tiene código en SAP; sus líneas quedan sin asignar.')
            ->and($controls->loadedSales->amount())->toBe('91900000')
            ->and($controls->salesByCode->amount())->toBe('90000000')
            ->and($controls->unassignedSales->amount())->toBe('1900000')
            ->and($controls->salesReconcile())->toBeFalse()
            ->and($result->hasBlockingAlerts())->toBeTrue();
    });

    it('names a line with a blank seller text', function () {
        $result = Settle::sales([Lines::sale(' ', 500_000)]);

        expect(Settle::references($result, AlertKind::UnknownSellerCode))->toBe(['sin vendedor']);
    });
});

describe('line filtering (§5.1)', function () {
    it('ignores the lines outside the period', function () {
        $result = Settle::sales([
            Lines::sale(34, 1_000_000, date: '2026-07-01'),
            Lines::sale(34, 2_000_000, date: '2026-07-31'),
            Lines::sale(34, 4_000_000, date: '2026-06-28'),
            Lines::sale(34, 8_000_000, date: '2026-08-01'),
        ]);

        expect(Settle::code($result, 34)->sales->amount())->toBe('3000000')
            ->and($result->controls()->loadedSales->amount())->toBe('3000000')
            ->and($result->alerts())->toBe([]);
    });

    it('counts a repeated key once and flags it', function () {
        $result = Settle::sales([
            Lines::sale(34, 1_000_000, key: 'F100-1'),
            Lines::sale(34, 1_000_000, key: 'F100-1'),
            Lines::sale(34, 1_000_000, key: 'F100-1'),
            Lines::sale(34, 2_000_000, key: 'F100-2'),
        ]);
        $alerts = $result->alertsOf(AlertKind::DuplicateLine);

        expect(Settle::code($result, 34)->sales->amount())->toBe('3000000')
            ->and($result->controls()->loadedSales->amount())->toBe('3000000')
            ->and($alerts)->toHaveCount(1)
            ->and($alerts[0]->reference)->toBe('venta:F100-1')
            ->and($alerts[0]->key())->toBe('linea_duplicada:venta:F100-1')
            ->and($alerts[0]->isBlocking())->toBeTrue()
            ->and($alerts[0]->message)->toBe('La venta F100-1 viene repetida; se tomó solo la primera.');
    });

    it('tells a repeated sale, payment or crossing apart when they share a key', function () {
        $alerts = new AlertCollector;
        $filter = new LineFilter(Settle::parameters(Period::of(2026, 7)), $alerts);

        $filter->keep([Lines::sale(34, 1_000, key: 'K-1'), Lines::sale(34, 1_000, key: 'K-1')]);
        $filter->keep([
            Lines::payment(34, 1_000, paymentDate: '2026-07-10', key: 'K-1'),
            Lines::payment(34, 1_000, paymentDate: '2026-07-10', key: 'K-1'),
        ]);
        $filter->keep([
            Lines::crossing(1_000, crossingDate: '2026-07-10', key: 'K-1'),
            Lines::crossing(1_000, crossingDate: '2026-07-10', key: 'K-1'),
        ]);

        expect(array_map(fn (Alert $alert) => $alert->reference, $alerts->all()))
            ->toBe(['venta:K-1', 'pago:K-1', 'cruce:K-1'])
            ->and($alerts->all()[1]->message)->toBe('El pago K-1 viene repetido; se tomó solo el primero.')
            ->and($alerts->all()[2]->message)->toBe('El cruce K-1 viene repetido; se tomó solo el primero.');
    });

    it('drops annulled sales silently', function () {
        $result = Settle::sales([
            Lines::sale(34, 1_000_000),
            Lines::sale(34, 5_000_000, annulled: true),
        ]);

        expect(Settle::code($result, 34)->sales->amount())->toBe('1000000')
            ->and($result->controls()->loadedSales->amount())->toBe('1000000')
            ->and($result->alerts())->toBe([]);
    });

    it('keeps a foreign currency amount as reported and warns about it', function () {
        $result = Settle::sales([Lines::sale(34, 1_000, key: 'F7-1', currency: 'USD', exchangeRate: '4000')]);
        $alerts = $result->alertsOf(AlertKind::ForeignCurrency);

        expect(Settle::code($result, 34)->sales->amount())->toBe('1000')
            ->and($alerts)->toHaveCount(1)
            ->and($alerts[0]->reference)->toBe('F7-1')
            ->and($alerts[0]->sellerCode)->toBe(34)
            ->and($alerts[0]->isBlocking())->toBeFalse()
            ->and($alerts[0]->message)->toBe('La línea F7-1 viene en USD con TRM 4000; se tomó el valor reportado.')
            ->and($result->hasBlockingAlerts())->toBeFalse();
    });

    it('converts a foreign currency amount to pesos, to cents, when asked to', function () {
        $result = Settle::sales(
            [Lines::sale(34, '1000.333', currency: 'USD', exchangeRate: '3950.55')],
            options: new SettlementOptions(currency: CurrencyTreatment::Convert),
        );

        expect(Settle::code($result, 34)->sales->amount())->toBe('3951865.53')
            ->and(Settle::code($result, 34)->saleDetails[0]->total->amount())->toBe('1000.333')
            ->and($result->alerts())->toBe([]);
    });
});

describe('credit notes and VAT (§14.2)', function () {
    beforeEach(function () {
        $this->sales = [
            Lines::sale(14, 10_000_000),
            Lines::sale(14, -1_000_000, type: DocumentType::CreditNote),
            Lines::sale(14, -500_000, type: DocumentType::Return),
            Lines::sale(14, 200_000, type: DocumentType::DebitNote),
        ];
        $this->budgets = [Lines::budget(14, 9_000_000)];
    });

    it('nets credit notes and returns by default', function () {
        $result = Settle::sales($this->sales, $this->budgets);

        expect(Settle::code($result, 14)->sales->amount())->toBe('8700000')
            ->and(Settle::code($result, 14)->compliance->percent())->toBe('96.6667')
            ->and(Settle::code($result, 14)->saleCommission->amount())->toBe('65250')
            ->and(Settle::code($result, 14)->saleDetails)->toHaveCount(4);
    });

    it('ignores credit notes and returns when asked to', function () {
        $result = Settle::sales(
            $this->sales,
            $this->budgets,
            new SettlementOptions(creditNotes: CreditNoteTreatment::Exclude),
        );

        expect(Settle::code($result, 14)->sales->amount())->toBe('10200000')
            ->and(Settle::code($result, 14)->saleCommission->amount())->toBe('76500')
            ->and(Settle::code($result, 14)->saleDetails)->toHaveCount(2)
            ->and($result->controls()->loadedSales->amount())->toBe('10200000')
            ->and($result->controls()->salesReconcile())->toBeTrue();
    });

    it('removes the VAT from the reported total, to cents, when asked to', function () {
        $result = Settle::sales(
            [Lines::sale(14, 11_900_000), Lines::sale(14, 1_000_000)],
            [Lines::budget(14, 10_000_000)],
            new SettlementOptions(salesBasis: SalesBasis::IncludesVat),
        );
        $details = Settle::code($result, 14)->saleDetails;

        expect($details[0]->total->amount())->toBe('11900000')
            ->and($details[0]->amount->amount())->toBe('10000000')
            ->and($details[1]->amount->amount())->toBe('840336.13')
            ->and(Settle::code($result, 14)->sales->amount())->toBe('10840336.13')
            ->and(Settle::code($result, 14)->saleCommission->amount())->toBe('81303')
            ->and($result->controls()->loadedSales->amount())->toBe('10840336.13');
    });
});

describe('negative commissions', function () {
    it('keeps a negative commission and blocks the settlement', function () {
        $result = Settle::sales([Lines::sale(34, -1_000_000, type: DocumentType::CreditNote)]);
        $alerts = $result->alertsOf(AlertKind::NegativeCommission);

        expect(Settle::code($result, 34)->saleCommission->amount())->toBe('-7500')
            ->and(Settle::code($result, 34)->total->amount())->toBe('-7500')
            ->and($alerts)->toHaveCount(1)
            ->and($alerts[0]->reference)->toBe('34')
            ->and($alerts[0]->isBlocking())->toBeTrue();
    });

    it('shows a zero compliance for negative sales', function () {
        $result = Settle::sales(
            [Lines::sale(14, -1_000_000, type: DocumentType::CreditNote)],
            [Lines::budget(14, 1_000_000)],
        );

        expect(Settle::code($result, 14)->sales->amount())->toBe('-1000000')
            ->and(Settle::code($result, 14)->compliance->percent())->toBe('0')
            ->and(Settle::code($result, 14)->saleCommission->amount())->toBe('0')
            ->and($result->alertsOf(AlertKind::NegativeCommission))->toBe([]);
    });
});

describe('SettlementCalculator', function () {
    it('rejects parameters of another period', function () {
        (new SettlementCalculator)->calculate(
            new SettlementInput(Period::of(2026, 7)),
            Settle::parameters(Period::of(2026, 8)),
        );
    })->throws(InvalidArgumentException::class);

    it('settles an empty period', function () {
        $result = Settle::run(new SettlementInput(Period::of(2026, 7)));

        expect($result->period()->key())->toBe('2026-07')
            ->and($result->parameters()->period()->key())->toBe('2026-07')
            ->and($result->alerts())->toBe([])
            ->and($result->controls()->salesReconcile())->toBeTrue()
            ->and($result->regularTotal()->amount())->toBe('0');
    });
});
