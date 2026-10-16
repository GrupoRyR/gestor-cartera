<?php

use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Infrastructure\Persistence\ActivityRecord;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
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
    $this->storeUrl = route('asesores.notas.store', $this->agro->id);
});

/**
 * The notes saved in any client log.
 */
function savedNotes(): int
{
    return ActivityRecord::query()->where('type', ActivityType::Note)->count();
}

it('adds a note to the log of the client, written by the advisor', function () {
    $this->actingAs($this->world->ana)->post($this->storeUrl, ['nota' => 'Hablé con tesorería, confirman pago el viernes.'])
        ->assertRedirect($this->fileUrl)
        ->assertSessionHas('status', 'Guardamos la nota en la bitácora del cliente.');

    $saved = ActivityRecord::query()->where('type', ActivityType::Note)->sole();

    expect($saved->client_id)->toBe($this->agro->id)
        ->and($saved->user_id)->toBe($this->world->ana->id)
        ->and($saved->summary)->toBe('Hablé con tesorería, confirman pago el viernes.')
        ->and($saved->details)->toBeNull();
});

it('keeps the whole text of a long note as the details', function () {
    $text = "Llamé a tesorería\nDicen que el pago sale el viernes.\nVolver a llamar el lunes.";

    $this->actingAs($this->world->ana)->post($this->storeUrl, ['nota' => $text]);

    $saved = ActivityRecord::query()->where('type', ActivityType::Note)->sole();

    expect($saved->summary)->toBe('Llamé a tesorería')->and($saved->details)->toBe($text);
});

it('counts as management of the client and shows on the file', function () {
    $this->actingAs($this->world->ana)->post($this->storeUrl, ['nota' => 'Primera llamada del mes']);

    expect(ClientRecord::query()->findOrFail($this->agro->id)->last_activity_at?->toDateString())->toBe('2026-10-16');

    $this->actingAs($this->world->ana)->get($this->fileUrl)->assertSeeText('Primera llamada del mes')->assertSeeText('Ana Álvarez');
});

it('rejects an empty or too long note and opens the dialog again', function (string $text) {
    $url = $this->fileUrl;
    $this->actingAs($this->world->ana)->from($url)->post($this->storeUrl, ['nota' => $text, 'formulario' => 'nueva-nota'])
        ->assertRedirect($url)
        ->assertSessionHasErrors('nota');

    $page = $this->actingAs($this->world->ana)->followingRedirects()->from($url)->post($this->storeUrl, ['nota' => $text, 'formulario' => 'nueva-nota']);

    expect(savedNotes())->toBe(0)
        ->and(Page::html($page)->count('//dialog[@id="nueva-nota"][@data-modal-autoopen]'))->toBe(1);
})->with(['empty' => [''], 'blank' => ['   '], 'too long' => [str_repeat('x', 1001)]]);

it('keeps notes away from the clients of other advisors, as if they did not exist', function (string $client) {
    $this->actingAs($this->world->ana)
        ->post(route('asesores.notas.store', $this->world->clients[$client]->id), ['nota' => 'Intento de nota ajena'])
        ->assertNotFound();

    expect(savedNotes())->toBe(0);
})->with(['bodega', 'distribuciones', 'equipos', 'comercial']);

it('answers an unknown client like somebody else’s', function () {
    $this->actingAs($this->world->ana)->post(route('asesores.notas.store', 987654), ['nota' => 'x'])->assertNotFound();
});

it('does not let sales managers, analysts or guests write notes', function () {
    $this->post($this->storeUrl, ['nota' => 'x'])->assertRedirect(route('core.login'));
    $this->actingAs($this->world->mariaPaula)->post($this->storeUrl, ['nota' => 'x'])->assertForbidden();
    $this->actingAs($this->world->gloria)->post(route('asesores.notas.store', $this->world->clients['comercial']->id), ['nota' => 'x'])->assertForbidden();

    expect(savedNotes())->toBe(0);
});

it('lets an advisor of SDC write notes too: only commitments are limited to SGX', function () {
    $user = CoreUsers::create(Role::SalesAdvisor, CompanyCode::SDC);
    TeamUsers::advisor(CompanyCode::SDC, 61, 'Nora Nieto', $user);
    $client = $this->world->client('Cliente SDC de Nora', CompanyCode::SDC, 61, [['400000.00', 5]]);

    $this->actingAs($user)->post(route('asesores.notas.store', $client->id), ['nota' => 'Cliente nuevo de SDC'])
        ->assertRedirect(route('asesores.clientes.show', $client->id));

    expect(savedNotes())->toBe(1);
});

it('offers the note button only to the advisor of the client', function () {
    $ana = $this->actingAs($this->world->ana)->get($this->fileUrl);
    $gloria = $this->actingAs($this->world->gloria)->get(route('asesores.clientes.show', $this->world->clients['comercial']->id));

    expect(Page::html($ana)->count('//*[@data-modal-open="nueva-nota"]'))->toBe(1)
        ->and(Page::html($ana)->count('//dialog[@id="nueva-nota"]//textarea[@name="nota"]'))->toBe(1)
        ->and(Page::html($gloria)->count('//*[@data-modal-open="nueva-nota"]'))->toBe(0);
});
