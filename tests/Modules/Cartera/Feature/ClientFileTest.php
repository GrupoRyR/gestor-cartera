<?php

use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Contracts\MailboxReader;
use App\Support\Formatting\Money;
use Carbon\CarbonImmutable;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\Make;
use Tests\Modules\Cartera\Support\CarteraUsers;
use Tests\Modules\Cartera\Support\CarteraWorld;

beforeEach(function () {
    $this->fakes = ContractFakes::install($this->app);
});

it('serves the client file at /cartera/clientes/{client}', function () {
    expect(route('cartera.clientes.show', 12, absolute: false))->toBe('/cartera/clientes/12');
});

it('sends guests to the sign-in page', function () {
    $client = CarteraWorld::client($this->fakes, overdue: 1_000_000);

    $this->get("/cartera/clientes/{$client->id}")->assertRedirect('/login');
});

it('opens for the portfolio manager and the analyst only', function (?Role $role, int $status) {
    $client = CarteraWorld::client($this->fakes, overdue: 1_000_000);

    $this->actingAs(CarteraUsers::create($role))->get("/cartera/clientes/{$client->id}")->assertStatus($status);
})->with([
    'manager' => [Role::PortfolioManager, 200],
    'analyst' => [Role::PortfolioAnalyst, 200],
    'advisor' => [Role::SalesAdvisor, 403],
    'sales manager' => [Role::SalesManager, 403],
    'no role' => [null, 403],
]);

it('answers 404 for a client that does not exist or is out of reach', function () {
    $this->fakes->cartera->restrictToAdvisor(999);
    $hidden = CarteraWorld::client($this->fakes, name: 'Cliente ajeno', overdue: 1_000_000);
    $analyst = CarteraUsers::analyst();

    $this->actingAs($analyst)->get('/cartera/clientes/987654')->assertNotFound();
    $this->actingAs($analyst)->get("/cartera/clientes/{$hidden->id}")->assertNotFound();
    $this->actingAs($analyst)->get('/cartera/clientes/abc')->assertNotFound();
});

it('shows who the client is and what it owes', function () {
    $client = CarteraWorld::client(
        $this->fakes,
        name: 'Ferretería El Tornillo SAS',
        overdue: 2_000_000,
        notDue: 1_000_000,
        daysOverdue: 38,
        status: ClientStatus::Overdue,
        advisor: Make::advisor(name: 'Carlos Pérez'),
        manager: Make::userRef(name: 'María Paula Rojas'),
        taxId: '900123456',
        group: 'Distribuidores',
        city: 'Medellín',
        creditLimit: 10_000_000,
    );

    $this->actingAs(CarteraUsers::analyst())
        ->get("/cartera/clientes/{$client->id}")
        ->assertOk()
        ->assertSeeText('Ferretería El Tornillo SAS')
        ->assertSeeText('SGX · NIT 900123456')
        ->assertSee(Money::format(3_000_000))
        ->assertSee(Money::format(2_000_000))
        ->assertSeeText('38 días')
        ->assertSeeText('Disponible: '.Money::format(7_000_000))
        ->assertSeeText('Vencida')
        ->assertSeeText($client->code)
        ->assertSeeText('Segurex Latam')
        ->assertSeeText('Distribuidores')
        ->assertSeeText('Medellín')
        ->assertSeeText('30 días')
        ->assertSeeText('Carlos Pérez')
        ->assertSeeText('María Paula Rojas');
});

it('flags a client over its credit limit and one without a limit', function () {
    $over = CarteraWorld::client($this->fakes, name: 'Cliente pasado', overdue: 5_000_000, creditLimit: 3_000_000);
    $none = CarteraWorld::client($this->fakes, name: 'Cliente sin cupo', overdue: 1_000_000, creditLimit: null);
    $analyst = CarteraUsers::analyst();

    $this->actingAs($analyst)->get("/cartera/clientes/{$over->id}")->assertSeeText('Excedido en '.Money::format(2_000_000));
    $this->actingAs($analyst)->get("/cartera/clientes/{$none->id}")->assertSeeText('Sin cupo asignado');
});

it('lists the contacts that come from SAP', function () {
    $client = CarteraWorld::client($this->fakes, overdue: 1_000_000, contacts: [
        Make::contact(name: 'Laura Martínez', email: 'laura@cliente.example', phone: '601 555 0123', position: 'Tesorera', isPrimary: true),
        CarteraWorld::bareContact('Pedro Gómez'),
    ]);

    $this->actingAs(CarteraUsers::analyst())
        ->get("/cartera/clientes/{$client->id}")
        ->assertSeeText('Laura Martínez')
        ->assertSeeText('Tesorera')
        ->assertSeeText('laura@cliente.example')
        ->assertSeeText('Principal')
        ->assertSee('href="tel:6015550123"', false)
        ->assertSeeText('Pedro Gómez');
});

it('lists the open invoices with their age and the balance they add up to', function () {
    $summary = Make::client(name: 'Cliente con facturas', balance: 3_000_000, overdue: 2_000_000, maxDaysOverdue: 40);
    $this->fakes->cartera->addClient(
        Make::clientDetail($summary),
        Make::invoice(company: CompanyCode::SGX, clientId: $summary->id, documentNumber: 'FV-1001', amount: 2_000_000, daysOverdue: 40),
        Make::invoice(company: CompanyCode::SGX, clientId: $summary->id, documentNumber: 'FV-1002', amount: 1_000_000, balance: 600_000, daysOverdue: -5),
    );

    $this->actingAs(CarteraUsers::analyst())
        ->get("/cartera/clientes/{$summary->id}")
        ->assertSeeText('Facturas abiertas (2)')
        ->assertSeeInOrder(['FV-1001', '40 días de mora', 'FV-1002', 'Vence en 5 días'])
        ->assertSee(Money::format(2_600_000));
});

it('says there are no open invoices when the client owes nothing', function () {
    $client = CarteraWorld::client($this->fakes, name: 'Cliente al corriente');

    $this->actingAs(CarteraUsers::analyst())
        ->get("/cartera/clientes/{$client->id}")
        ->assertSeeText('Sin facturas abiertas');
});

it('shows the log of the client, newest first, with who wrote each entry', function () {
    $client = CarteraWorld::client($this->fakes, overdue: 1_000_000);
    $this->fakes->gestiones->add(
        Make::activity(clientId: $client->id, type: ActivityType::Call, summary: 'Llamada al área de pagos', result: 'Promete pago el viernes', details: 'Habló con Laura', user: Make::userRef(name: 'María Paula Rojas'), occurredAt: CarbonImmutable::now()->subDays(2)),
        Make::activity(clientId: $client->id, type: ActivityType::System, summary: 'Cartera recalculada', result: null, occurredAt: CarbonImmutable::now()->subDay()),
        Make::activity(clientId: Make::nextId(), type: ActivityType::Note, summary: 'Nota de otro cliente'),
    );

    $this->actingAs(CarteraUsers::analyst())
        ->get("/cartera/clientes/{$client->id}")
        ->assertSeeInOrder(['Cartera recalculada', 'Registrado por el sistema', 'Llamada al área de pagos', 'Habló con Laura', 'por María Paula Rojas'])
        ->assertSeeText('Resultado: Promete pago el viernes')
        ->assertDontSeeText('Nota de otro cliente');
});

it('shows the commitments of the client', function () {
    $client = CarteraWorld::client($this->fakes, overdue: 3_000_000);
    $this->fakes->compromisos->add(
        Make::commitment(clientId: $client->id, amount: 1_500_000, dueDate: CarbonImmutable::today()->addDays(5), notes: 'Paga con transferencia', createdBy: Make::userRef(name: 'Carlos Pérez')),
        Make::commitment(clientId: $client->id, amount: 900_000, dueDate: CarbonImmutable::today()->subDays(3), status: CommitmentStatus::Broken),
        Make::commitment(clientId: Make::nextId(), amount: 777_000),
    );

    $this->actingAs(CarteraUsers::analyst())
        ->get("/cartera/clientes/{$client->id}")
        ->assertSee(Money::format(1_500_000))
        ->assertSeeText('Pendiente')
        ->assertSeeText('Paga con transferencia')
        ->assertSeeText('Carlos Pérez')
        ->assertSee(Money::format(900_000))
        ->assertSeeText('Incumplido')
        ->assertDontSee(Money::format(777_000));
});

describe('mail replies', function () {
    it('shows the replies and the bounces of the client', function () {
        $client = CarteraWorld::client($this->fakes, overdue: 1_000_000);
        $this->fakes->mailbox->add(
            Make::inboundMail(clientId: $client->id, subject: 'RE: Facturas vencidas', preview: 'El pago queda programado para el viernes', from: 'tesoreria@cliente.example'),
            Make::inboundMail(clientId: $client->id, subject: 'Mail Delivery Failure', isBounce: true, bounceReason: 'Buzón lleno', read: true),
            Make::inboundMail(clientId: Make::nextId(), subject: 'Correo de otro cliente'),
        );

        $this->actingAs(CarteraUsers::analyst())
            ->get("/cartera/clientes/{$client->id}")
            ->assertSeeText('RE: Facturas vencidas')
            ->assertSeeText('El pago queda programado para el viernes')
            ->assertSeeText('De tesoreria@cliente.example')
            ->assertSeeText('Sin leer')
            ->assertSeeText('Rebotó')
            ->assertSeeText('Motivo: Buzón lleno')
            ->assertDontSeeText('Correo de otro cliente');
    });

    it('links to the message only when its address is https', function () {
        $client = CarteraWorld::client($this->fakes, overdue: 1_000_000);
        $this->fakes->mailbox->add(
            Make::inboundMail(clientId: $client->id, subject: 'Correo seguro', url: 'https://outlook.office.com/mail/id/1'),
            Make::inboundMail(clientId: $client->id, subject: 'Correo raro', url: 'javascript:alert(1)'),
        );

        $this->actingAs(CarteraUsers::analyst())
            ->get("/cartera/clientes/{$client->id}")
            ->assertSee('href="https://outlook.office.com/mail/id/1"', false)
            ->assertDontSee('javascript:alert', false);
    });

    it('says so when the client has not answered', function () {
        $client = CarteraWorld::client($this->fakes, overdue: 1_000_000);

        $this->actingAs(CarteraUsers::analyst())
            ->get("/cartera/clientes/{$client->id}")
            ->assertSeeText('El cliente no ha contestado ningún correo.');
    });

    it('explains that replies are not available while no module reads the mailboxes', function () {
        $client = CarteraWorld::client($this->fakes, overdue: 1_000_000);
        unset($this->app[MailboxReader::class]);

        $this->actingAs(CarteraUsers::analyst())
            ->get("/cartera/clientes/{$client->id}")
            ->assertOk()
            ->assertSeeText('Las respuestas de correo aparecerán cuando se active el cobro por correo.');
    });
});

describe('what the user may do from the file', function () {
    it('offers the status form to those who may change a status', function () {
        $client = CarteraWorld::client($this->fakes, overdue: 1_000_000);

        $this->actingAs(CarteraUsers::analyst())
            ->get("/cartera/clientes/{$client->id}")
            ->assertSee('action="'.route('cartera.clientes.estado', $client->id).'"', false)
            ->assertSeeText('Cambiar estado');
    });

    it('offers to remove a log entry to those who may', function () {
        $client = CarteraWorld::client($this->fakes, overdue: 1_000_000);
        $activity = Make::activity(clientId: $client->id, summary: 'Llamada');
        $this->fakes->gestiones->add($activity);

        $this->actingAs(CarteraUsers::analyst())
            ->get("/cartera/clientes/{$client->id}")
            ->assertSee('action="'.route('core.gestiones.destroy', $activity->id).'"', false);
    });

    it('keeps those who may only look from changing anything', function () {
        $client = CarteraWorld::client($this->fakes, overdue: 1_000_000);
        $activity = Make::activity(clientId: $client->id, summary: 'Llamada');
        $this->fakes->gestiones->add($activity);

        $this->actingAs(CarteraUsers::viewer())
            ->get("/cartera/clientes/{$client->id}")
            ->assertOk()
            ->assertDontSee(route('cartera.clientes.estado', $client->id), false)
            ->assertDontSee(route('core.gestiones.destroy', $activity->id), false);
    });
});

it('links to the client file from the dashboard, the list and the board', function () {
    $client = CarteraWorld::client($this->fakes, name: 'Moroso enlazado', overdue: 1_000_000, status: ClientStatus::Overdue);
    $link = 'href="'.route('cartera.clientes.show', $client->id).'"';
    $analyst = CarteraUsers::analyst();

    foreach (['/cartera', '/cartera/clientes', '/cartera/kanban'] as $page) {
        $this->actingAs($analyst)->get($page)->assertSee($link, false);
    }
});
