<?php

use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\Data\AgingBuckets;
use App\Modules\Core\Contracts\Data\PortfolioTotals;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Support\Formatting\Money;
use App\Support\Navigation\NavRegistry;
use Carbon\CarbonImmutable;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\Make;
use Tests\Modules\Gerencia\Support\GerenciaUsers;
use Tests\Modules\Gerencia\Support\PerCompanyHistoryCartera;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:30:00'));
    $this->fakes = ContractFakes::install($this->app);
    $this->fakes->cartera->cutoffDate = CarbonImmutable::parse('2026-10-19');

    /**
     * A client with an overdue and a not-yet-due invoice, so the totals of the fake add up.
     */
    $this->client = function (CompanyCode $company, float $overdue, float $notDue = 0.0, string $name = 'Cliente de prueba'): void {
        $summary = Make::client(company: $company, name: $name, balance: $overdue + $notDue, overdue: $overdue, maxDaysOverdue: $overdue > 0 ? 45 : 0);
        $invoices = [];

        if ($overdue > 0) {
            $invoices[] = Make::invoice(company: $company, clientId: $summary->id, amount: $overdue, daysOverdue: 45);
        }

        if ($notDue > 0) {
            $invoices[] = Make::invoice(company: $company, clientId: $summary->id, amount: $notDue, daysOverdue: -10);
        }

        $this->fakes->cartera->addClient(Make::clientDetail($summary), ...$invoices);
    };

    $this->snapshot = fn (string $cutoff, float $total, float $overdue): PortfolioTotals => new PortfolioTotals(
        cutoffDate: CarbonImmutable::parse($cutoff),
        total: $total,
        overdue: $overdue,
        clientCount: 1,
        overdueClientCount: 1,
        aging: new AgingBuckets,
    );

    $this->manager = fn () => $this->actingAs(GerenciaUsers::manager());
});

it('serves the dashboard at /gerencia', function () {
    expect(route('gerencia.tablero', absolute: false))->toBe('/gerencia');
});

it('sends guests to the sign-in page', function () {
    $this->get('/gerencia')->assertRedirect('/login');
});

it('opens for the portfolio manager', function () {
    ($this->manager)()->get('/gerencia')->assertOk()->assertSeeText('Tablero gerencial');
});

it('is closed to every other role and to users without one', function (?Role $role) {
    $this->actingAs(GerenciaUsers::create($role))->get('/gerencia')->assertForbidden();
})->with([
    'analyst' => [Role::PortfolioAnalyst],
    'admin' => [Role::Admin],
    'advisor' => [Role::SalesAdvisor],
    'sales manager' => [Role::SalesManager],
    'treasury' => [Role::Treasury],
    'no role' => [null],
]);

it('asks for a first load when there is no portfolio yet', function () {
    $this->fakes->cartera->cutoffDate = null;

    ($this->manager)()
        ->get('/gerencia')
        ->assertOk()
        ->assertSeeText('Aún no hay cartera cargada')
        ->assertDontSeeText('Cartera total')
        ->assertDontSeeText('Cartera vencida mes a mes');
});

it('shows the headline figures of the company in view', function () {
    ($this->client)(CompanyCode::SGX, overdue: 2_000_000, notDue: 6_000_000);

    ($this->manager)()
        ->get('/gerencia')
        ->assertOk()
        ->assertSeeText('Cartera total')
        ->assertSee(Money::format(8_000_000))
        ->assertSee(Money::format(2_000_000))
        ->assertSeeText('25,0 % de la cartera')
        ->assertSeeText('1 cliente vencido')
        ->assertSee('data-tone="warning"', false)
        ->assertDontSeeText('Grupo frente a cada empresa');
});

it('measures the rotation of the portfolio against the collections of the period', function () {
    ($this->client)(CompanyCode::SGX, overdue: 2_000_000, notDue: 6_000_000);
    $this->fakes->recaudo->addDaily(Make::dailyCollection(date: CarbonImmutable::parse('2026-10-10'), company: CompanyCode::SGX, total: 2_000_000.0));

    // 2 M collected over an 8 M portfolio in 20 days: a quarter of a turn, 80 days per turn.
    ($this->manager)()
        ->get('/gerencia')
        ->assertSeeText('0,3 veces')
        ->assertSeeText('Una vuelta cada 80 días.')
        ->assertSeeText('Rotación (aprox., por recaudo)')
        ->assertSeeText('Es aproximada: recaudo bancario del período ÷ cartera promedio, no ventas.');
});

it('compares the group with each company in the group view', function () {
    $this->fakes->currentCompany->switchTo(null);
    ($this->client)(CompanyCode::SGX, overdue: 1_000_000, notDue: 3_000_000);
    ($this->client)(CompanyCode::SDC, overdue: 500_000, notDue: 500_000);

    ($this->manager)()
        ->get('/gerencia')
        ->assertOk()
        ->assertSeeText('Grupo frente a cada empresa')
        ->assertSeeText('Grupo RYR')
        ->assertSeeText(CompanyCode::SGX->label())
        ->assertSeeText(CompanyCode::SDC->label())
        ->assertSee(Money::format(5_000_000))
        ->assertSee(Money::format(1_500_000))
        ->assertSeeText('Porcentaje vencido')
        ->assertSeeText('Días por vuelta');
});

it('selects the period with the periodo parameter and keeps the month for anything else', function (string $query, string $label, string $dates) {
    ($this->client)(CompanyCode::SGX, overdue: 1_000_000);

    ($this->manager)()
        ->get('/gerencia'.$query)
        ->assertOk()
        ->assertSeeText($dates)
        ->assertSee('<a href="'.route('gerencia.tablero', ['periodo' => 'trimestre']).'"', false)
        ->assertSeeInOrder(['aria-current="page"', '<span>'.$label.'</span>'], false);
})->with([
    'default' => ['', 'Este mes', '01/10/2026 al 20/10/2026'],
    'previous month' => ['?periodo=mes_anterior', 'Mes anterior', '01/09/2026 al 30/09/2026'],
    'quarter' => ['?periodo=trimestre', 'Trimestre móvil', '01/08/2026 al 20/10/2026'],
    'year' => ['?periodo=anio', 'Este año', '01/01/2026 al 20/10/2026'],
    'unknown value' => ['?periodo=nunca', 'Este mes', '01/10/2026 al 20/10/2026'],
    'not a text' => ['?periodo[]=anio', 'Este mes', '01/10/2026 al 20/10/2026'],
]);

it('draws the overdue trend of the group and of each company', function () {
    $this->fakes->currentCompany->switchTo(null);
    ($this->client)(CompanyCode::SGX, overdue: 1_000_000, notDue: 3_000_000);
    ($this->client)(CompanyCode::SDC, overdue: 500_000, notDue: 500_000);

    $cartera = (new PerCompanyHistoryCartera($this->fakes->cartera))
        ->withGroupHistory([
            ($this->snapshot)('2026-08-31', 1_000.0, 100.0),
            ($this->snapshot)('2026-09-30', 1_000.0, 200.0),
        ])
        ->withCompanyHistory(CompanyCode::SGX, [
            ($this->snapshot)('2026-08-31', 800.0, 40.0),
            ($this->snapshot)('2026-09-30', 800.0, 80.0),
        ])
        ->withCompanyHistory(CompanyCode::SDC, [
            ($this->snapshot)('2026-09-30', 200.0, 100.0),
        ]);
    $this->app->instance(CarteraRepository::class, $cartera);

    $response = ($this->manager)()->get('/gerencia');

    $response->assertOk()
        ->assertSeeText('Cartera vencida mes a mes')
        // One hover text per point, with the full month, the series and the value.
        ->assertSee('<title>Septiembre 2026 · Grupo RYR: 20,0 %</title>', false)
        ->assertSee('<title>Agosto 2026 · '.CompanyCode::SGX->label().': 5,0 %</title>', false)
        ->assertSee('<title>Septiembre 2026 · '.CompanyCode::SDC->label().': 50,0 %</title>', false)
        ->assertDontSee('<title>Agosto 2026 · '.CompanyCode::SDC->label(), false)
        // The color follows the company; the group is the dashed one. SDC has a single month, so it is a marker without a line.
        ->assertSee('stroke-viz-1', false)
        ->assertSee('fill-viz-2', false)
        ->assertSee('stroke-dasharray="6 4"', false)
        // Axis text and the table that stands in for the chart.
        ->assertSee('>20 %<', false)
        ->assertSeeText('Ver los datos en una tabla')
        ->assertSeeText('% vencido · '.CompanyCode::SDC->label());
});

it('says the trend needs history when there are fewer than two months', function () {
    ($this->client)(CompanyCode::SGX, overdue: 1_000_000);

    ($this->manager)()
        ->get('/gerencia')
        ->assertSeeText('Todavía no hay historia')
        ->assertDontSee('<title>Septiembre 2026', false);
});

it('lists the compliance of each advisor, the least compliant first', function () {
    $this->fakes->cartera->addAdvisor(
        Make::advisor(company: CompanyCode::SGX, code: 7, name: 'Carlos Pérez'),
        Make::advisor(company: CompanyCode::SGX, code: 8, name: 'Diana Gómez'),
    );
    $due = fn (int $day) => CarbonImmutable::parse("2026-10-{$day}");
    $this->fakes->compromisos->add(
        Make::commitment(advisorCode: 8, status: CommitmentStatus::Fulfilled, dueDate: $due(5), fulfilledAt: $due(4)),
        Make::commitment(advisorCode: 8, status: CommitmentStatus::Fulfilled, dueDate: $due(6), fulfilledAt: $due(6)),
        Make::commitment(advisorCode: 7, status: CommitmentStatus::Fulfilled, dueDate: $due(5), fulfilledAt: $due(4)),
        Make::commitment(advisorCode: 7, status: CommitmentStatus::Broken, dueDate: $due(6)),
        Make::commitment(advisorCode: null, status: CommitmentStatus::Broken, dueDate: $due(7)),
    );

    ($this->manager)()
        ->get('/gerencia')
        ->assertOk()
        ->assertSeeText('Cumplimiento de compromisos por asesor')
        // 0 % (no advisor), 50 % (Carlos) and 100 % (Diana).
        ->assertSeeInOrder(['Sin asesor', 'Carlos Pérez', 'Diana Gómez'])
        ->assertSeeText('0,0 %')
        ->assertSeeText('50,0 %')
        ->assertSeeText('100,0 %')
        // 3 of 5 evaluated commitments were kept.
        ->assertSeeText('60,0 %')
        ->assertSeeText('3 de 5 compromisos cumplidos')
        ->assertDontSeeText('Cifras parciales');
});

it('warns that the compliance covers only part of the period when the commitments were cut off', function () {
    config(['gerencia.compliance_page_size' => 1, 'gerencia.compliance_max_pages' => 1]);
    $this->fakes->compromisos->add(
        Make::commitment(advisorCode: 7, status: CommitmentStatus::Broken, dueDate: CarbonImmutable::parse('2026-10-05')),
        Make::commitment(advisorCode: 7, status: CommitmentStatus::Broken, dueDate: CarbonImmutable::parse('2026-10-06')),
    );

    ($this->manager)()
        ->get('/gerencia')
        ->assertOk()
        ->assertSeeText('Cifras parciales')
        ->assertSeeText('estas cifras cubren solo una parte del período');
});

it('shows an empty state when no commitment fell due in the period', function () {
    ($this->manager)()
        ->get('/gerencia')
        ->assertSeeText('Sin compromisos en el período');
});

it('reports the outcome of the Cobro emails of the period from the activity log', function () {
    $sentOn = CarbonImmutable::parse('2026-10-05 09:00:00');
    $this->fakes->gestiones->add(
        Make::activity(type: ActivityType::EmailSent, occurredAt: $sentOn, metadata: ['cobro_run_item_id' => 1]),
        Make::activity(type: ActivityType::EmailSent, occurredAt: $sentOn, metadata: ['cobro_run_item_id' => 2]),
        Make::activity(type: ActivityType::EmailSent, occurredAt: $sentOn, metadata: ['cobro_run_item_id' => 3]),
        Make::activity(type: ActivityType::EmailReceived, occurredAt: $sentOn, metadata: ['cobro_inbound_id' => 1]),
        Make::activity(type: ActivityType::EmailReceived, occurredAt: $sentOn, metadata: ['cobro_inbound_id' => 2, 'bounce' => true]),
        // Not Cobro emails: no marker, and one outside the period.
        Make::activity(type: ActivityType::EmailSent, occurredAt: $sentOn),
        Make::activity(type: ActivityType::EmailSent, occurredAt: CarbonImmutable::parse('2026-09-05'), metadata: ['cobro_run_item_id' => 4]),
    );
    $this->fakes->mailbox->add(Make::inboundMail(company: CompanyCode::SGX, read: false));

    ($this->manager)()
        ->get('/gerencia')
        ->assertOk()
        ->assertSeeText('Resultados del cobro por correo')
        ->assertSeeText('Reúne el cobro semanal y la circularización; Cobro todavía no los separa.')
        ->assertSeeTextInOrder([
            'Enviados', '3',
            'Entregados', '2',
            'Rebotes', '1', '33,3 % de lo enviado',
            'Respuestas', '1', '33,3 % de lo enviado',
            'Sin leer ahora', '1',
        ])
        ->assertDontSeeText('Solo se leyó parte de la bitácora');
});

it('warns when only part of the activity log was read', function () {
    config(['gerencia.activity_read_limit' => 2]);
    $sentOn = CarbonImmutable::parse('2026-10-05 09:00:00');
    $this->fakes->gestiones->add(
        Make::activity(type: ActivityType::EmailSent, occurredAt: $sentOn, metadata: ['cobro_run_item_id' => 1]),
        Make::activity(type: ActivityType::EmailSent, occurredAt: $sentOn, metadata: ['cobro_run_item_id' => 2]),
        Make::activity(type: ActivityType::EmailSent, occurredAt: $sentOn, metadata: ['cobro_run_item_id' => 3]),
    );

    ($this->manager)()
        ->get('/gerencia')
        ->assertSeeText('Solo se leyó parte de la bitácora');
});

it('says there were no sends instead of dividing by zero', function () {
    ($this->manager)()
        ->get('/gerencia')
        ->assertSeeText('Sin envíos en el período')
        ->assertDontSeeText('NAN');
});

it('links to the Cobro reports the manager may open', function () {
    ($this->manager)()
        ->get('/gerencia')
        ->assertSee('href="'.route('cobro.lotes.index').'"', false)
        ->assertSee('href="'.route('cobro.respuestas.index').'"', false);
});

it('explains that bank movements arrive the day after when there is no collection', function () {
    ($this->client)(CompanyCode::SGX, overdue: 1_000_000);

    ($this->manager)()
        ->get('/gerencia')
        ->assertSeeText('Aún no hay recaudo en este período')
        ->assertSeeText('Los movimientos bancarios llegan al día siguiente')
        ->assertDontSeeText('Recaudo día por día en una tabla');
});

it('draws the daily collections with a hover text per day and a table behind the chart', function () {
    ($this->client)(CompanyCode::SGX, overdue: 1_000_000);
    $this->fakes->recaudo->addDaily(
        Make::dailyCollection(date: CarbonImmutable::parse('2026-10-10'), company: CompanyCode::SGX, total: 1_500_000.0),
        Make::dailyCollection(date: CarbonImmutable::parse('2026-10-12'), company: CompanyCode::SGX, total: 500_000.0),
    );

    ($this->manager)()
        ->get('/gerencia')
        ->assertOk()
        ->assertSeeText('Recaudo del período')
        ->assertSee(Money::format(2_000_000))
        ->assertSee('<title>10/10/2026: '.Money::format(1_500_000).'</title>', false)
        // A day without movements is still on the axis, at zero.
        ->assertSee('<title>11/10/2026: '.Money::format(0).'</title>', false)
        ->assertSeeText('Recaudo por semana')
        ->assertSeeText('Ver el recaudo día por día en una tabla')
        ->assertSeeText('Recaudo del día');
});

it('draws a long period week by week', function () {
    ($this->client)(CompanyCode::SGX, overdue: 1_000_000);
    $this->fakes->recaudo->addDaily(
        Make::dailyCollection(date: CarbonImmutable::parse('2026-01-06'), company: CompanyCode::SGX, total: 3_000_000.0),
        Make::dailyCollection(date: CarbonImmutable::parse('2026-01-08'), company: CompanyCode::SGX, total: 1_000_000.0),
    );

    ($this->manager)()
        ->get('/gerencia?periodo=anio')
        ->assertOk()
        ->assertSeeText('semana por semana')
        ->assertSee('<title>Semana del 05/01/2026 al 11/01/2026: '.Money::format(4_000_000).'</title>', false)
        ->assertDontSee('<title>10/01/2026:', false);
});

it('adds the collections of both companies in the group view and names the company in the weekly table', function () {
    $this->fakes->currentCompany->switchTo(null);
    ($this->client)(CompanyCode::SGX, overdue: 1_000_000);
    ($this->client)(CompanyCode::SDC, overdue: 1_000_000);
    $this->fakes->recaudo->addDaily(
        Make::dailyCollection(date: CarbonImmutable::parse('2026-10-10'), company: CompanyCode::SGX, total: 1_000_000.0),
        Make::dailyCollection(date: CarbonImmutable::parse('2026-10-10'), company: CompanyCode::SDC, total: 250_000.0),
    );

    ($this->manager)()
        ->get('/gerencia')
        ->assertSee('<title>10/10/2026: '.Money::format(1_250_000).'</title>', false)
        ->assertSee('data-part="text">SGX<', false)
        ->assertSee('data-part="text">SDC<', false);
});

it('adds the dashboard to the manager menu after the Cartera entries', function () {
    $manager = GerenciaUsers::manager();
    $labels = array_map(fn ($item): string => $item->label, app(NavRegistry::class)->visibleTo($manager));

    expect($labels)->toContain('Tablero gerencial')
        ->and($labels[0])->toBe('Tablero')
        ->and(array_search('Tablero gerencial', $labels, true))->toBeGreaterThan(0);
});

it('keeps the analyst away from the menu entry', function () {
    $analyst = GerenciaUsers::create(Role::PortfolioAnalyst);
    $labels = array_map(fn ($item): string => $item->label, app(NavRegistry::class)->visibleTo($analyst));

    expect($labels)->not->toContain('Tablero gerencial');
});

it('still sends the manager from / to the Cartera dashboard', function () {
    $this->actingAs(GerenciaUsers::manager())->get('/')->assertRedirect(route('cartera.tablero'));
});
