<?php

use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Infrastructure\Persistence\CommitmentRecord;
use Carbon\CarbonImmutable;
use Tests\Modules\Asesores\Support\AdvisorWorld;
use Tests\Modules\Asesores\Support\Page;
use Tests\Modules\Core\Support\CommitmentRecords;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse(AdvisorWorld::TODAY, 'America/Bogota'));
    $this->world = AdvisorWorld::create();
    $this->agro = $this->world->clients['agro'];
    $this->fileUrl = route('asesores.clientes.show', $this->agro->id);

    $this->pending = CommitmentRecords::commitment($this->agro, $this->world->ana, '2026-10-30', '2000000.00');
    $this->broken = CommitmentRecords::commitment($this->agro, $this->world->ana, '2026-10-10', '1000000.00', ['status' => CommitmentStatus::Broken, 'broken_at' => '2026-10-11 00:15:00']);
    $this->fulfilled = CommitmentRecords::commitment($this->agro, $this->world->ana, '2026-10-01', '500000.00', ['status' => CommitmentStatus::Fulfilled, 'fulfilled_at' => '2026-10-02 09:00:00']);
    $this->bodega = CommitmentRecords::commitment($this->world->clients['bodega'], $this->world->bruno, '2026-10-30', '900000.00');
});

/**
 * @return array<string, string>
 */
function asesoresEdit(array $change = []): array
{
    return ['fecha' => '2026-11-05', 'valor' => '2.500.000', 'notas' => 'El cliente pidió una semana más', ...$change];
}

// --- the commitment page ----------------------------------------------------

it('lets the advisor see a pending commitment with the three ways to act on it', function () {
    $html = Page::html($this->actingAs($this->world->ana)->get(route('asesores.compromisos.show', $this->pending->id))->assertOk());

    expect($html->count('//form[contains(@action,"/compromisos/'.$this->pending->id.'")][input[@name="_method"][@value="PUT"]]'))->toBe(1)
        ->and($html->count('//form[contains(@action,"/cumplir")]'))->toBe(1)
        ->and($html->count('//form[contains(@action,"/anular")]'))->toBe(1);
});

it('lets the advisor close a broken commitment but not change it', function () {
    $html = Page::html($this->actingAs($this->world->ana)->get(route('asesores.compromisos.show', $this->broken->id))->assertOk());

    expect($html->count('//form[input[@name="_method"][@value="PUT"]]'))->toBe(0)
        ->and($html->count('//form[contains(@action,"/cumplir")]'))->toBe(1)
        ->and($html->count('//form[contains(@action,"/anular")]'))->toBe(1);
});

it('shows a closed commitment without forms', function () {
    $response = $this->actingAs($this->world->ana)->get(route('asesores.compromisos.show', $this->fulfilled->id))->assertOk();

    expect(Page::html($response)->count('//main//form'))->toBe(0);
    $response->assertSeeText('Este compromiso ya está cerrado');
});

it('lets a sales manager read the commitment of a client of their team, without forms', function () {
    $teamClient = $this->world->clients['comercial'];
    $commitment = CommitmentRecords::commitment($teamClient, $this->world->gloria, '2026-10-30');

    $response = $this->actingAs($this->world->gloria)->get(route('asesores.compromisos.show', $commitment->id))->assertOk();

    expect(Page::html($response)->count('//main//form'))->toBe(0);
    $response->assertSeeText('solo el asesor del cliente lo modifica');
});

it('does not show a commitment to who cannot see its client', function () {
    $this->get(route('asesores.compromisos.show', $this->pending->id))->assertRedirect(route('core.login'));
    $this->actingAs($this->world->bruno)->get(route('asesores.compromisos.show', $this->pending->id))->assertNotFound();
    $this->actingAs($this->world->ana)->get(route('asesores.compromisos.show', $this->bodega->id))->assertNotFound();
    $this->actingAs($this->world->ana)->get(route('asesores.compromisos.show', 987654))->assertNotFound();
});

it('links every commitment of the file to its page', function () {
    $response = $this->actingAs($this->world->ana)->get($this->fileUrl);

    expect(Page::html($response)->count('//ul[@data-part="commitments"]//a[contains(@href,"/compromisos/")]'))->toBe(3);
});

// --- changing it ------------------------------------------------------------

it('changes the date, amount and notes of a pending commitment', function () {
    $this->actingAs($this->world->ana)->put(route('asesores.compromisos.update', $this->pending->id), asesoresEdit())
        ->assertRedirect($this->fileUrl)
        ->assertSessionHas('status', 'Actualizamos el compromiso.');

    $saved = CommitmentRecords::stored($this->pending);

    expect($saved->amount)->toBe('2500000.00')
        ->and($saved->due_date->toDateString())->toBe('2026-11-05')
        ->and($saved->notes)->toBe('El cliente pidió una semana más');
});

it('writes the change in the log of the client', function () {
    $this->actingAs($this->world->ana)->put(route('asesores.compromisos.update', $this->pending->id), asesoresEdit());

    $entries = CommitmentRecords::logOf($this->agro, ActivityType::Commitment);

    expect($entries)->not->toBeEmpty()
        ->and(end($entries)->summary)->toContain('Compromiso actualizado');
});

it('does not change a commitment with a bad form', function (array $change, string $field) {
    $this->actingAs($this->world->ana)->from(route('asesores.compromisos.show', $this->pending->id))
        ->put(route('asesores.compromisos.update', $this->pending->id), asesoresEdit($change))
        ->assertSessionHasErrors($field);

    expect(CommitmentRecords::stored($this->pending)->amount)->toBe('2000000.00');
})->with([
    'a past date' => [['fecha' => '2026-10-01'], 'fecha'],
    'no amount' => [['valor' => ''], 'valor'],
    'zero' => [['valor' => '0'], 'valor'],
]);

it('does not change a commitment that is no longer pending', function (string $which) {
    $commitment = $this->{$which};

    $this->actingAs($this->world->ana)->from($this->fileUrl)
        ->put(route('asesores.compromisos.update', $commitment->id), asesoresEdit())
        ->assertRedirect($this->fileUrl)
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'ya no se puede cambiar'));

    expect(CommitmentRecords::stored($commitment)->amount)->toBe($commitment->amount);
})->with(['broken', 'fulfilled']);

// --- fulfilling it ----------------------------------------------------------

it('marks a pending commitment as fulfilled, with an optional note', function () {
    $this->actingAs($this->world->ana)->post(route('asesores.compromisos.fulfill', $this->pending->id), ['nota' => 'Pagó por transferencia'])
        ->assertRedirect($this->fileUrl)
        ->assertSessionHas('status', 'Marcamos el compromiso como cumplido.');

    $saved = CommitmentRecords::stored($this->pending);

    expect($saved->status)->toBe(CommitmentStatus::Fulfilled)->and($saved->fulfilled_at)->not->toBeNull();
});

it('also fulfils a broken commitment: a late payment counts', function () {
    $this->actingAs($this->world->ana)->post(route('asesores.compromisos.fulfill', $this->broken->id))->assertRedirect($this->fileUrl);

    expect(CommitmentRecords::stored($this->broken)->status)->toBe(CommitmentStatus::Fulfilled);
});

it('says so when the commitment was already closed, instead of failing', function () {
    $this->actingAs($this->world->ana)->from($this->fileUrl)->post(route('asesores.compromisos.fulfill', $this->fulfilled->id))
        ->assertRedirect($this->fileUrl)
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'ya no se puede cambiar'));
});

// --- cancelling it ----------------------------------------------------------

it('cancels a commitment with the reason', function () {
    $this->actingAs($this->world->ana)->post(route('asesores.compromisos.cancel', $this->pending->id), ['motivo' => 'Se registró en el cliente equivocado'])
        ->assertRedirect($this->fileUrl)
        ->assertSessionHas('status', 'Anulamos el compromiso.');

    $saved = CommitmentRecords::stored($this->pending);

    expect($saved->status)->toBe(CommitmentStatus::Cancelled)
        ->and($saved->cancellation_reason)->toBe('Se registró en el cliente equivocado');
});

it('cancels a broken commitment too', function () {
    $this->actingAs($this->world->ana)->post(route('asesores.compromisos.cancel', $this->broken->id), ['motivo' => 'Acuerdo nuevo'])->assertRedirect($this->fileUrl);

    expect(CommitmentRecords::stored($this->broken)->status)->toBe(CommitmentStatus::Cancelled);
});

it('does not cancel without a reason', function (string $reason) {
    $this->actingAs($this->world->ana)->from($this->fileUrl)->post(route('asesores.compromisos.cancel', $this->pending->id), ['motivo' => $reason])
        ->assertSessionHasErrors(['motivo' => 'El campo motivo es obligatorio.']);

    expect(CommitmentRecords::stored($this->pending)->status)->toBe(CommitmentStatus::Pending);
})->with(['empty' => [''], 'blank' => ['   ']]);

it('does not cancel a commitment that is already closed', function () {
    $this->actingAs($this->world->ana)->from($this->fileUrl)->post(route('asesores.compromisos.cancel', $this->fulfilled->id), ['motivo' => 'Por error'])
        ->assertSessionHas('error');

    expect(CommitmentRecords::stored($this->fulfilled)->status)->toBe(CommitmentStatus::Fulfilled);
});

// --- who may -----------------------------------------------------------------

it('keeps every write on a commitment away from other advisors, as if it did not exist', function (string $action, string $method) {
    $url = route("asesores.compromisos.{$action}", $this->pending->id);
    $body = ['fecha' => '2026-11-05', 'valor' => '1000', 'motivo' => 'x', 'nota' => 'x'];

    $this->actingAs($this->world->bruno)->{$method}($url, $body)->assertNotFound();

    expect(CommitmentRecords::stored($this->pending)->status)->toBe(CommitmentStatus::Pending)
        ->and(CommitmentRecords::stored($this->pending)->amount)->toBe('2000000.00');
})->with([
    'update' => ['update', 'put'],
    'fulfil' => ['fulfill', 'post'],
    'cancel' => ['cancel', 'post'],
]);

it('keeps sales managers and analysts from writing, and sends guests to sign in', function (string $action, string $method) {
    $url = route("asesores.compromisos.{$action}", $this->pending->id);
    $body = ['fecha' => '2026-11-05', 'valor' => '1000', 'motivo' => 'x', 'nota' => 'x'];

    $this->{$method}($url, $body)->assertRedirect(route('core.login'));
    $this->actingAs($this->world->mariaPaula)->{$method}($url, $body)->assertForbidden();
    $this->actingAs($this->world->gloria)->{$method}($url, $body)->assertForbidden();

    expect(CommitmentRecords::stored($this->pending)->status)->toBe(CommitmentStatus::Pending);
})->with([
    'update' => ['update', 'put'],
    'fulfil' => ['fulfill', 'post'],
    'cancel' => ['cancel', 'post'],
]);

it('lets a sales manager read a commitment of the team but never write it', function () {
    $commitment = CommitmentRecords::commitment($this->world->clients['comercial'], $this->world->ana, '2026-10-30');

    $this->actingAs($this->world->gloria)->post(route('asesores.compromisos.fulfill', $commitment->id))->assertForbidden();
    $this->actingAs($this->world->gloria)->put(route('asesores.compromisos.update', $commitment->id), asesoresEdit())->assertForbidden();

    expect(CommitmentRecord::query()->findOrFail($commitment->id)->status)->toBe(CommitmentStatus::Pending);
});
