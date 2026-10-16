<?php

use App\Modules\Core\Contracts\ClientStatusService;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Support\Formatting\Money;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Cartera\Support\CarteraUsers;
use Tests\Modules\Cartera\Support\CarteraWorld;
use Tests\Modules\Cartera\Support\RecordingClientStatusService;

beforeEach(function () {
    $this->fakes = ContractFakes::install($this->app);
    $this->statuses = new RecordingClientStatusService($this->fakes->cartera);
    $this->app->instance(ClientStatusService::class, $this->statuses);
});

/**
 * The <section> tag of a Kanban column, as rendered.
 */
function kanbanColumnTag(string $html, ClientStatus $status): string
{
    preg_match('/<section[^>]*data-column="'.$status->value.'"[^>]*>/s', $html, $matches);

    return $matches[0] ?? '';
}

it('serves the board at /cartera/kanban and the move at /cartera/clientes/{client}/estado', function () {
    expect(route('cartera.kanban', absolute: false))->toBe('/cartera/kanban')
        ->and(route('cartera.clientes.estado', 12, absolute: false))->toBe('/cartera/clientes/12/estado');
});

it('sends guests to the sign-in page', function () {
    $this->get('/cartera/kanban')->assertRedirect('/login');
    $this->postJson('/cartera/clientes/1/estado', ['estado' => 'vencida'])->assertUnauthorized();
});

it('opens for the portfolio manager and the analyst only', function (?Role $role, int $status) {
    $this->actingAs(CarteraUsers::create($role))->get('/cartera/kanban')->assertStatus($status);
})->with([
    'manager' => [Role::PortfolioManager, 200],
    'analyst' => [Role::PortfolioAnalyst, 200],
    'advisor' => [Role::SalesAdvisor, 403],
    'treasury' => [Role::Treasury, 403],
    'no role' => [null, 403],
]);

it('draws the eight statuses as columns, in the order of the collection workflow', function () {
    $this->actingAs(CarteraUsers::analyst())
        ->get('/cartera/kanban')
        ->assertSeeInOrder(['Al día', 'Por vencer', 'Vencida', 'En gestión', 'Compromiso', 'Incumplido', 'Pago recibido', 'Jurídico']);
});

it('puts each client in the column of its status, with the count and the balance of the column', function () {
    CarteraWorld::client($this->fakes, name: 'Primero vencido', overdue: 3_000_000, notDue: 1_000_000, status: ClientStatus::Overdue);
    CarteraWorld::client($this->fakes, name: 'Segundo vencido', overdue: 1_000_000, status: ClientStatus::Overdue);
    CarteraWorld::client($this->fakes, name: 'Cliente en gestión', overdue: 2_000_000, status: ClientStatus::InProgress);

    $response = $this->actingAs(CarteraUsers::analyst())->get('/cartera/kanban');

    $response->assertSeeInOrder(['Primero vencido', 'Segundo vencido'])
        ->assertSeeText('2 clientes · '.Money::format(5_000_000))
        ->assertSeeText('1 cliente · '.Money::format(2_000_000));

    expect(preg_match_all('/<li[^>]*data-card="/', $response->getContent()))->toBe(3);
});

it('starts with "Al día" and "Pago recibido" hidden and every other column in view', function () {
    $html = $this->actingAs(CarteraUsers::analyst())->get('/cartera/kanban')->assertOk()->getContent();

    foreach ([ClientStatus::Current, ClientStatus::PaymentReceived] as $closed) {
        expect(kanbanColumnTag($html, $closed))->toMatch('/\shidden[\s>]/');
    }

    foreach ([ClientStatus::DueSoon, ClientStatus::Overdue, ClientStatus::InProgress, ClientStatus::Committed, ClientStatus::Defaulted, ClientStatus::Legal] as $open) {
        expect(kanbanColumnTag($html, $open))->not->toMatch('/\shidden[\s>]/');
    }

    expect($html)->toContain('Mostrar «Al día» y «Pago recibido»');
});

it('draws only the first cards of a long column and links to the rest', function () {
    config(['cartera.kanban_max_por_columna' => 2]);

    foreach (['Uno', 'Dos', 'Tres'] as $step => $name) {
        CarteraWorld::client($this->fakes, name: "Moroso {$name}", overdue: (3 - $step) * 1_000_000, status: ClientStatus::Overdue);
    }

    $this->actingAs(CarteraUsers::analyst())
        ->get('/cartera/kanban')
        ->assertSeeText('Moroso Uno')
        ->assertSeeText('Moroso Dos')
        ->assertDontSeeText('Moroso Tres')
        ->assertSeeText('3 clientes')
        ->assertSeeText('Y 1 cliente más')
        ->assertSee('href="'.route('cartera.clientes.index', ['estado' => 'vencida']).'"', false);
});

it('shows the largest overdue amount first inside a column', function () {
    CarteraWorld::client($this->fakes, name: 'Moroso chico', overdue: 1_000_000, status: ClientStatus::Overdue);
    CarteraWorld::client($this->fakes, name: 'Moroso grande', overdue: 8_000_000, status: ClientStatus::Overdue);

    $this->actingAs(CarteraUsers::analyst())->get('/cartera/kanban')->assertSeeInOrder(['Moroso grande', 'Moroso chico']);
});

it('shows on each card what the analyst needs to decide the next call', function () {
    CarteraWorld::client($this->fakes, name: 'Moroso sin llamar', overdue: 2_000_000, daysOverdue: 52, status: ClientStatus::Overdue);

    $this->actingAs(CarteraUsers::analyst())
        ->get('/cartera/kanban')
        ->assertSee(Money::format(2_000_000))
        ->assertSeeText('52 días')
        ->assertSeeText('Sin gestión');
});

it('names the company of each card only in the group view', function () {
    CarteraWorld::client($this->fakes, name: 'Moroso de SDC', company: CompanyCode::SDC, overdue: 1_000_000, status: ClientStatus::Overdue);
    $user = CarteraUsers::analyst();

    $card = function (string $html): string {
        preg_match('/<li[^>]*data-card=.*?<\/li>/s', $html, $matches);

        return $matches[0] ?? '';
    };

    $this->fakes->currentCompany->switchTo(CompanyCode::SDC);
    $single = $card($this->actingAs($user)->get('/cartera/kanban')->getContent());

    $this->fakes->currentCompany->switchTo(null);
    $group = $card($this->actingAs($user)->get('/cartera/kanban')->getContent());

    expect($group)->toContain('>SDC<')->and($single)->not->toContain('>SDC<')->and($single)->toContain('Moroso de SDC');
});

it('offers the move button and dragging to those who may change a status', function () {
    CarteraWorld::client($this->fakes, name: 'Moroso', overdue: 1_000_000, status: ClientStatus::Overdue);

    $html = $this->actingAs(CarteraUsers::analyst())->get('/cartera/kanban')->getContent();

    expect($html)->toContain('data-move-button')
        ->and($html)->toContain('draggable="true"')
        ->and($html)->toContain('data-modal-open="mover-cliente"')
        ->and($html)->toContain('id="mover-cliente"');
});

it('keeps the move button and dragging from those who may only look', function () {
    CarteraWorld::client($this->fakes, name: 'Moroso', overdue: 1_000_000, status: ClientStatus::Overdue);
    $html = $this->actingAs(CarteraUsers::viewer())->get('/cartera/kanban')->assertOk()->getContent();

    expect($html)->toContain('Moroso')
        ->and($html)->not->toContain('data-modal-open="mover-cliente"')
        ->and($html)->not->toContain('draggable="true"')
        ->and($html)->not->toContain('id="mover-cliente"');
});

describe('moving a client', function () {
    it('changes the status through Core and answers with the new one', function () {
        $client = CarteraWorld::client($this->fakes, name: 'Moroso', overdue: 1_000_000, status: ClientStatus::Overdue);

        $this->actingAs(CarteraUsers::analyst())
            ->postJson("/cartera/clientes/{$client->id}/estado", ['estado' => 'compromiso'])
            ->assertOk()
            ->assertJson(['id' => $client->id, 'status' => 'compromiso', 'label' => 'Compromiso']);

        $this->statuses->assertChanged($client->id, ClientStatus::Committed);
    });

    it('passes the optional note along and goes back with a message from a form', function () {
        $client = CarteraWorld::client($this->fakes, name: 'Moroso', overdue: 1_000_000, status: ClientStatus::Overdue);

        $this->actingAs(CarteraUsers::analyst())
            ->from('/cartera/kanban')
            ->post("/cartera/clientes/{$client->id}/estado", ['estado' => 'juridico', 'nota' => 'Pasa a cobro jurídico'])
            ->assertRedirect('/cartera/kanban')
            ->assertSessionHas('status', 'Moroso pasó a «Jurídico».');

        $this->statuses->assertChanged($client->id, ClientStatus::Legal, 'Pasa a cobro jurídico');
    });

    it('refuses a status that does not exist', function (mixed $status) {
        $client = CarteraWorld::client($this->fakes, name: 'Moroso', overdue: 1_000_000);

        $this->actingAs(CarteraUsers::analyst())
            ->postJson("/cartera/clientes/{$client->id}/estado", ['estado' => $status])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('estado');

        $this->statuses->assertNothingChanged();
    })->with([
        'unknown status' => ['archivado'],
        'empty' => [''],
        'missing' => [null],
        'a list' => [['vencida']],
    ]);

    it('answers 404 for a client Core cannot find', function () {
        $this->actingAs(CarteraUsers::analyst())
            ->postJson('/cartera/clientes/999999/estado', ['estado' => 'vencida'])
            ->assertNotFound();
    });

    it('answers 403 when Core refuses the user', function () {
        $client = CarteraWorld::client($this->fakes, name: 'Moroso', overdue: 1_000_000);
        $this->statuses->deny();

        $this->actingAs(CarteraUsers::analyst())
            ->postJson("/cartera/clientes/{$client->id}/estado", ['estado' => 'vencida'])
            ->assertForbidden();
    });

    it('answers 403 to a user without the permission to change statuses', function () {
        $client = CarteraWorld::client($this->fakes, name: 'Moroso', overdue: 1_000_000);
        $this->actingAs(CarteraUsers::viewer())
            ->postJson("/cartera/clientes/{$client->id}/estado", ['estado' => 'vencida'])
            ->assertForbidden();

        $this->statuses->assertNothingChanged();
    });

    it('is closed to other roles', function () {
        $client = CarteraWorld::client($this->fakes, name: 'Moroso', overdue: 1_000_000);

        $this->actingAs(CarteraUsers::create(Role::SalesAdvisor))
            ->postJson("/cartera/clientes/{$client->id}/estado", ['estado' => 'vencida'])
            ->assertForbidden();
    });
});

it('lists the board in the menu', function () {
    $this->actingAs(CarteraUsers::analyst())
        ->get('/cartera/kanban')
        ->assertSee('href="'.route('cartera.kanban').'"', false);
});
