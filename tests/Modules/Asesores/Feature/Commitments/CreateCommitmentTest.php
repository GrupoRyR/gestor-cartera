<?php

use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Persistence\CommitmentRecord;
use App\Support\Formatting\Money;
use Carbon\CarbonImmutable;
use Tests\Modules\Asesores\Support\AdvisorWorld;
use Tests\Modules\Asesores\Support\Page;
use Tests\Modules\Core\Support\CoreUsers;
use Tests\Modules\Core\Support\TeamUsers;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse(AdvisorWorld::TODAY, 'America/Bogota'));
    $this->world = AdvisorWorld::create();
    $this->agro = $this->world->clients['agro'];
    $this->fileUrl = route('asesores.clientes.show', $this->agro->id);
    $this->storeUrl = route('asesores.compromisos.store', $this->agro->id);
    $this->valid = ['fecha' => '2026-10-30', 'valor' => '5.000.000', 'notas' => '  Pagan con el recaudo de la cosecha  '];
});

/**
 * How many commitments exist in total.
 */
function commitmentCount(): int
{
    return CommitmentRecord::query()->count();
}

it('saves the commitment of an advisor for their client and goes back to the file', function () {
    $this->actingAs($this->world->ana)->post($this->storeUrl, $this->valid)
        ->assertRedirect($this->fileUrl)
        ->assertSessionHas('status', 'Guardamos el compromiso por '.Money::format(5_000_000).' para el 30/10/2026.');

    $saved = CommitmentRecord::query()->sole();

    expect($saved->client_id)->toBe($this->agro->id)
        ->and($saved->company)->toBe(CompanyCode::SGX)
        ->and($saved->advisor_code)->toBe(12)
        ->and($saved->amount)->toBe('5000000.00')
        ->and($saved->due_date->toDateString())->toBe('2026-10-30')
        ->and($saved->notes)->toBe('Pagan con el recaudo de la cosecha')
        ->and($saved->status)->toBe(CommitmentStatus::Pending)
        ->and($saved->created_by)->toBe($this->world->ana->id);
});

it('leaves its trace on the client: the status and the log', function () {
    $this->actingAs($this->world->ana)->post($this->storeUrl, $this->valid);

    expect(ClientRecord::query()->findOrFail($this->agro->id)->status)->toBe(ClientStatus::Committed);

    $this->actingAs($this->world->ana)->get($this->fileUrl)
        ->assertSeeText('Compromiso de pago por '.Money::format(5_000_000).' para el 30/10/2026')
        ->assertSeeText('Pagan con el recaudo de la cosecha');
});

it('reads the amount the way it is written in Colombia', function (string $typed, string $stored) {
    $this->actingAs($this->world->ana)->post($this->storeUrl, [...$this->valid, 'valor' => $typed])->assertRedirect($this->fileUrl);

    expect(CommitmentRecord::query()->sole()->amount)->toBe($stored);
})->with([
    'plain' => ['5000000', '5000000.00'],
    'thousands' => ['5.000.000', '5000000.00'],
    'with sign' => ['$ 1.250.000', '1250000.00'],
    'with cents' => ['1.250.000,50', '1250000.50'],
]);

it('accepts today as the day of payment, and no notes', function () {
    $this->actingAs($this->world->ana)->post($this->storeUrl, ['fecha' => '2026-10-16', 'valor' => '100000'])->assertRedirect($this->fileUrl);

    $saved = CommitmentRecord::query()->sole();

    expect($saved->due_date->toDateString())->toBe('2026-10-16')->and($saved->notes)->toBeNull();
});

it('rejects what it cannot save, keeps what was typed and opens the dialog again', function (array $change, string $field) {
    $response = $this->actingAs($this->world->ana)->from($this->fileUrl)
        ->post($this->storeUrl, [...$this->valid, 'formulario' => 'nuevo-compromiso', ...$change])
        ->assertRedirect($this->fileUrl)
        ->assertSessionHasErrors($field);

    expect(commitmentCount())->toBe(0);

    $page = $this->actingAs($this->world->ana)->followingRedirects()->from($this->fileUrl)
        ->post($this->storeUrl, [...$this->valid, 'formulario' => 'nuevo-compromiso', ...$change]);

    expect(Page::html($page)->count('//dialog[@id="nuevo-compromiso"][@data-modal-autoopen]'))->toBe(1);
})->with([
    'a day in the past' => [['fecha' => '2026-10-15'], 'fecha'],
    'no date' => [['fecha' => ''], 'fecha'],
    'a date that is not one' => [['fecha' => '30/10/2026'], 'fecha'],
    'no amount' => [['valor' => ''], 'valor'],
    'an amount in words' => [['valor' => 'cinco millones'], 'valor'],
    'zero' => [['valor' => '0'], 'valor'],
    'a negative amount' => [['valor' => '-5000'], 'valor'],
    'an absurd amount' => [['valor' => '99.999.999.999.999'], 'valor'],
    'very long notes' => [['notas' => str_repeat('x', 1001)], 'notas'],
]);

it('says it in Spanish and gives an example of what is valid', function () {
    $this->actingAs($this->world->ana)->from($this->fileUrl)->post($this->storeUrl, [...$this->valid, 'valor' => 'nada'])
        ->assertSessionHasErrors(['valor' => 'Escribe un valor en pesos mayor que cero, por ejemplo 5.000.000.']);
});

it('keeps an advisor out of the clients of other advisors, and says nothing about them', function (string $client) {
    $id = $this->world->clients[$client]->id;

    $this->actingAs($this->world->ana)->post(route('asesores.compromisos.store', $id), $this->valid)->assertNotFound();

    expect(commitmentCount())->toBe(0);
})->with([
    'another advisor' => ['bodega'],
    'nobody' => ['distribuciones'],
    'the other company, same SAP code' => ['equipos'],
    'a client of a team' => ['comercial'],
]);

it('answers an unknown client like somebody else’s', function () {
    $this->actingAs($this->world->ana)->post(route('asesores.compromisos.store', 987654), $this->valid)->assertNotFound();
});

it('does not let sales managers, analysts or guests write', function () {
    $this->post($this->storeUrl, $this->valid)->assertRedirect(route('core.login'));
    $this->actingAs($this->world->mariaPaula)->post($this->storeUrl, $this->valid)->assertForbidden();
    // Gloria sees the clients of her team, but she does not write for them.
    $teamClient = $this->world->clients['comercial'];
    $this->actingAs($this->world->gloria)->post(route('asesores.compromisos.store', $teamClient->id), $this->valid)->assertForbidden();

    expect(commitmentCount())->toBe(0);
});

it('does not let an advisor write in SDC, where the analyst does', function () {
    $user = CoreUsers::create(Role::SalesAdvisor, CompanyCode::SDC);
    TeamUsers::advisor(CompanyCode::SDC, 61, 'Nora Nieto', $user);
    $client = $this->world->client('Cliente SDC de Nora', CompanyCode::SDC, 61, [['400000.00', 5]]);

    $this->actingAs($user)->post(route('asesores.compromisos.store', $client->id), $this->valid)->assertForbidden();

    expect(commitmentCount())->toBe(0);
});

it('offers the new commitment only to the advisor of the client', function () {
    $ana = $this->actingAs($this->world->ana)->get($this->fileUrl);
    $gloria = $this->actingAs($this->world->gloria)->get(route('asesores.clientes.show', $this->world->clients['comercial']->id));

    expect(Page::html($ana)->count('//*[@data-modal-open="nuevo-compromiso"]'))->toBe(1)
        ->and(Page::html($ana)->count('//dialog[@id="nuevo-compromiso"]//form'))->toBe(1)
        ->and(Page::html($gloria)->count('//*[@data-modal-open="nuevo-compromiso"]'))->toBe(0)
        ->and(Page::html($gloria)->count('//dialog'))->toBe(0);
});
