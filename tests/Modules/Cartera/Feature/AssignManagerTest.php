<?php

use App\Modules\Core\Contracts\ClientAssignmentService;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\Make;
use Tests\Modules\Cartera\Support\CarteraUsers;
use Tests\Modules\Cartera\Support\CarteraWorld;
use Tests\Modules\Cartera\Support\RecordingClientAssignmentService;

beforeEach(function () {
    $this->fakes = ContractFakes::install($this->app);
    $this->paula = Make::userRef(id: 501, name: 'Paula Analista');
    $this->miguel = Make::userRef(id: 502, name: 'Miguel Gerente');
    $this->assignments = (new RecordingClientAssignmentService($this->fakes->cartera))->withAssignees($this->paula, $this->miguel);
    $this->app->instance(ClientAssignmentService::class, $this->assignments);
});

it('serves the assignment under /cartera/clientes', function () {
    expect(route('cartera.clientes.asignar', absolute: false))->toBe('/cartera/clientes/gestor');
});

it('sends guests to the sign-in page', function () {
    $this->post('/cartera/clientes/gestor', ['clientes' => [1], 'nuevo_gestor' => '501'])->assertRedirect('/login');

    $this->assignments->assertNothingAssigned();
});

describe('the client list', function () {
    it('offers the bulk assignment to whoever may assign, with a labelled box per client', function () {
        $client = CarteraWorld::client($this->fakes, name: 'Ferretería El Tornillo SAS', overdue: 1_000_000);

        $this->actingAs(CarteraUsers::manager())
            ->get('/cartera/clientes')
            ->assertOk()
            ->assertSee('action="'.route('cartera.clientes.asignar').'"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('name="clientes[]" value="'.$client->id.'"', false)
            ->assertSeeText('Elegir Ferretería El Tornillo SAS')
            ->assertSee('<option value="sin_asignar" >Sin asignar</option>', false)
            ->assertSee('<option value="501" >Paula Analista</option>', false)
            ->assertSee('<option value="502" >Miguel Gerente</option>', false)
            ->assertSeeText('Aplicar al cliente filtrado');
    });

    it('carries the filters on screen, so "todos los filtrados" means the list the user sees', function () {
        CarteraWorld::client($this->fakes, name: 'Ferretería El Tornillo SAS', overdue: 1_000_000, status: ClientStatus::Overdue);

        $this->actingAs(CarteraUsers::manager())
            ->get('/cartera/clientes?estado=vencida&gestor=sin_asignar')
            ->assertSee('<input type="hidden" name="estado" value="vencida">', false)
            ->assertSee('<input type="hidden" name="gestor" value="sin_asignar">', false);
    });

    it('asks to confirm "todos los filtrados" with the number of clients', function () {
        foreach (['Uno', 'Dos', 'Tres'] as $name) {
            CarteraWorld::client($this->fakes, name: $name, overdue: 1_000_000);
        }

        $this->actingAs(CarteraUsers::manager())
            ->get('/cartera/clientes')
            ->assertSee('data-confirm-all="Vas a asignar el gestor a los 3 clientes filtrados. ¿Seguir?"', false);
    });

    it('turns off "todos los filtrados" and says why when the list is above the cap', function () {
        config(['cartera.assignment.max_bulk' => 2]);

        foreach (['Uno', 'Dos', 'Tres'] as $name) {
            CarteraWorld::client($this->fakes, name: $name, overdue: 1_000_000);
        }

        $response = $this->actingAs(CarteraUsers::manager())->get('/cartera/clientes')->assertOk();

        $response->assertSeeText('Son 3 clientes y el máximo es 2; filtra un poco más para usar esta opción.');
        expect($response->getContent())->toMatch('/<input[^>]*name="todos_filtrados"[^>]*disabled/');
    });

    it('leaves "todos los filtrados" on when the list is within the cap', function () {
        CarteraWorld::client($this->fakes, overdue: 1_000_000);

        $response = $this->actingAs(CarteraUsers::manager())->get('/cartera/clientes')->assertOk();

        $response->assertDontSeeText('el máximo es');
        expect($response->getContent())->not->toMatch('/<input[^>]*name="todos_filtrados"[^>]*disabled/');
    });

    it('is not offered to those who may only look', function () {
        CarteraWorld::client($this->fakes, overdue: 1_000_000);

        $this->actingAs(CarteraUsers::viewer())
            ->get('/cartera/clientes')
            ->assertOk()
            ->assertDontSee('action="'.route('cartera.clientes.asignar').'"', false)
            ->assertDontSee('name="clientes[]"', false);
    });
});

describe('the bulk assignment', function () {
    it('assigns the chosen clients and says how many changed', function () {
        $one = CarteraWorld::client($this->fakes, name: 'Uno', overdue: 1_000_000);
        $two = CarteraWorld::client($this->fakes, name: 'Dos', overdue: 1_000_000);

        $this->actingAs(CarteraUsers::analyst())
            ->from('/cartera/clientes?gestor=todos')
            ->post('/cartera/clientes/gestor', ['clientes' => [(string) $one->id, (string) $two->id], 'nuevo_gestor' => '501'])
            ->assertRedirect('/cartera/clientes?gestor=todos')
            ->assertSessionHas('status', 'Asignamos el gestor a 2 clientes.');

        expect($this->assignments->assignments())->toBe([['clientIds' => [$one->id, $two->id], 'managerUserId' => 501]]);
    });

    it('says when one client changed, and when none did', function () {
        $assigned = CarteraWorld::client($this->fakes, name: 'Ya de Paula', overdue: 1_000_000, manager: $this->paula);
        $free = CarteraWorld::client($this->fakes, name: 'Libre', overdue: 1_000_000);
        $analyst = CarteraUsers::analyst();

        $this->actingAs($analyst)
            ->post('/cartera/clientes/gestor', ['clientes' => [$assigned->id, $free->id], 'nuevo_gestor' => '501'])
            ->assertSessionHas('status', 'Asignamos el gestor a 1 cliente.');

        $this->actingAs($analyst)
            ->post('/cartera/clientes/gestor', ['clientes' => [$assigned->id], 'nuevo_gestor' => '501'])
            ->assertSessionHas('status', 'No cambió ningún cliente.');
    });

    it('leaves the clients without a manager with "Sin asignar"', function () {
        $client = CarteraWorld::client($this->fakes, overdue: 1_000_000, manager: $this->paula);

        $this->actingAs(CarteraUsers::manager())
            ->post('/cartera/clientes/gestor', ['clientes' => [$client->id], 'nuevo_gestor' => 'sin_asignar'])
            ->assertSessionHas('status', 'Asignamos el gestor a 1 cliente.');

        expect($this->assignments->assignments())->toBe([['clientIds' => [$client->id], 'managerUserId' => null]]);
    });

    it('assigns every client that matches the filters of the list, not just the page', function () {
        config(['cartera.por_pagina' => 1]);
        CarteraWorld::client($this->fakes, name: 'Vencido uno', overdue: 1_000_000, status: ClientStatus::Overdue);
        CarteraWorld::client($this->fakes, name: 'Vencido dos', overdue: 2_000_000, status: ClientStatus::Overdue);
        CarteraWorld::client($this->fakes, name: 'Al día', notDue: 1_000_000);
        $analyst = CarteraUsers::analyst();

        $this->actingAs($analyst)
            ->post('/cartera/clientes/gestor', [
                'todos_filtrados' => '1',
                'estado' => 'vencida',
                'gestor' => 'sin_asignar',
                'buscar' => 'vencido',
                'nuevo_gestor' => '502',
            ])
            ->assertSessionHas('status', 'Asignamos el gestor a 2 clientes.');

        expect($this->assignments->assignments())->toBe([])
            ->and($this->assignments->matchingAssignments())->toHaveCount(1);

        ['query' => $query, 'managerUserId' => $managerUserId] = $this->assignments->matchingAssignments()[0];

        expect($managerUserId)->toBe(502)
            ->and($query->statuses)->toBe([ClientStatus::Overdue])
            ->and($query->search)->toBe('vencido')
            ->and($query->unassigned)->toBeTrue()
            ->and($query->managerUserId)->toBeNull();
    });

    it('follows the default of the role for "todos los filtrados" when the form does not name the manager filter', function () {
        $analyst = CarteraUsers::analyst();

        $this->actingAs($analyst)
            ->post('/cartera/clientes/gestor', ['todos_filtrados' => '1', 'nuevo_gestor' => '501'])
            ->assertSessionHas('status', 'No cambió ningún cliente.');

        expect($this->assignments->matchingAssignments()[0]['query']->managerUserId)->toBe($analyst->id);
    });

    it('refuses "todos los filtrados" above the cap and changes nothing', function () {
        config(['cartera.assignment.max_bulk' => 2]);

        foreach (['Uno', 'Dos', 'Tres'] as $name) {
            CarteraWorld::client($this->fakes, name: $name, overdue: 1_000_000);
        }

        $this->actingAs(CarteraUsers::manager())
            ->post('/cartera/clientes/gestor', ['todos_filtrados' => '1', 'nuevo_gestor' => '501'])
            ->assertSessionHas('error', 'Son 3 clientes; asigna de a máximo 2. Filtra un poco más.');

        $this->assignments->assertNothingAssigned();
    });

    it('caps the matching clients at 1000 by default', function () {
        expect(config('cartera.assignment.max_bulk'))->toBe(1000);
    });

    it('explains a manager who may not take every chosen client', function () {
        $client = CarteraWorld::client($this->fakes, overdue: 1_000_000);

        $this->actingAs(CarteraUsers::manager())
            ->post('/cartera/clientes/gestor', ['clientes' => [$client->id], 'nuevo_gestor' => '999'])
            ->assertSessionHas('error', 'Esa persona no puede encargarse de todos los clientes elegidos: ya no gestiona cartera o no trabaja en la empresa de alguno. Elige otra o filtra por empresa.');

        $this->assignments->assertNothingAssigned();
    });

    it('explains a client that is no longer there', function () {
        $client = CarteraWorld::client($this->fakes, overdue: 1_000_000);

        $this->actingAs(CarteraUsers::manager())
            ->post('/cartera/clientes/gestor', ['clientes' => [$client->id, 987654], 'nuevo_gestor' => '501'])
            ->assertSessionHas('error', 'Alguno de los clientes ya no está disponible. Vuelve a cargar la lista.');

        $this->assignments->assertNothingAssigned();
    });

    it('asks for clients and for a manager', function (array $input, string $field) {
        $this->actingAs(CarteraUsers::manager())
            ->from('/cartera/clientes')
            ->post('/cartera/clientes/gestor', $input)
            ->assertRedirect('/cartera/clientes')
            ->assertSessionHasErrors($field);

        $this->assignments->assertNothingAssigned();
    })->with([
        'no client and not all the filtered ones' => [['nuevo_gestor' => '501'], 'clientes'],
        'a client that is not a number' => [['clientes' => ['abc'], 'nuevo_gestor' => '501'], 'clientes.0'],
        'no manager' => [['clientes' => [1]], 'nuevo_gestor'],
        'a manager that is neither a user nor "Sin asignar"' => [['clientes' => [1], 'nuevo_gestor' => 'nadie'], 'nuevo_gestor'],
    ]);

    it('refuses chosen clients together with "todos los filtrados" and changes nothing', function () {
        $client = CarteraWorld::client($this->fakes, overdue: 1_000_000);

        $this->actingAs(CarteraUsers::manager())
            ->from('/cartera/clientes')
            ->post('/cartera/clientes/gestor', ['clientes' => [$client->id], 'todos_filtrados' => '1', 'nuevo_gestor' => '501'])
            ->assertRedirect('/cartera/clientes')
            ->assertSessionHasErrors(['clientes' => 'Elige filas o marca «todos los filtrados», no las dos cosas.']);

        $this->assignments->assertNothingAssigned();
    });

    it('explains why it needs a client', function () {
        $this->actingAs(CarteraUsers::manager())
            ->post('/cartera/clientes/gestor', ['nuevo_gestor' => '501'])
            ->assertSessionHasErrors(['clientes' => 'Elige al menos un cliente o marca «todos los filtrados».']);
    });

    it('is forbidden to those who may only look', function () {
        $client = CarteraWorld::client($this->fakes, overdue: 1_000_000);

        $this->actingAs(CarteraUsers::viewer())
            ->post('/cartera/clientes/gestor', ['clientes' => [$client->id], 'nuevo_gestor' => '501'])
            ->assertForbidden();

        $this->assignments->assertNothingAssigned();
    });

    it('is forbidden when Core refuses the user', function () {
        $client = CarteraWorld::client($this->fakes, overdue: 1_000_000);
        $this->assignments->deny();

        $this->actingAs(CarteraUsers::manager())
            ->post('/cartera/clientes/gestor', ['clientes' => [$client->id], 'nuevo_gestor' => '501'])
            ->assertForbidden();
    });
});
