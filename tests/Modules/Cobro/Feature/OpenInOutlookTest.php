<?php

use App\Modules\Cobro\Database\Seeders\CobroPermissionSeeder;
use App\Modules\Cobro\Infrastructure\Persistence\InboundMessage;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Cobro\Support\CobroUsers;
use Tests\Modules\Cobro\Support\GraphFake;

/*
 * "Abrir en Outlook": the reply screen and the inbox link to a Cobro route
 * that asks Graph for the message webLink and redirects to it. When the
 * stored Graph id no longer exists (the message was moved to another
 * folder), the message is found again by its internetMessageId.
 */

const COBRO_WEB_LINK = 'https://outlook.office365.com/owa/?ItemID=AAMkAD%2Fmoved&exvsurl=1&viewmodel=ReadMessageItem';

beforeEach(function () {
    $this->seed(CobroPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'America/Bogota'));
    ContractFakes::install($this->app);
    $this->analyst = CobroUsers::create(Role::PortfolioAnalyst);

    $this->reply = InboundMessage::query()->create([
        'company' => CompanyCode::SGX,
        'mailbox' => 'segurex.info@segurex.com',
        'graph_id' => 'AAMkAD-original',
        'internet_message_id' => '<reply-1@tornillo.example>',
        'conversation_id' => 'conv-1',
        'from_address' => 'tesoreria@tornillo.example',
        'subject' => 'RE: Facturas vencidas',
        'preview' => 'El pago sale el viernes.',
        'client_id' => 41,
        'client_name' => 'Ferretería El Tornillo SAS',
        'received_at' => CarbonImmutable::parse('2026-11-18 10:00', 'America/Bogota'),
    ]);
});

it('serves the Outlook link under /cobro/respuestas/{message}/outlook', function () {
    expect(route('cobro.respuestas.outlook', 9, absolute: false))->toBe('/cobro/respuestas/9/outlook');
});

it('redirects to the webLink Graph gives for the stored message', function () {
    $graph = GraphFake::install()
        ->on('GET', 'users/segurex.info@segurex.com/messages/AAMkAD-original', ['id' => 'AAMkAD-original', 'webLink' => COBRO_WEB_LINK]);

    $this->actingAs($this->analyst)->get('/cobro/respuestas/'.$this->reply->id.'/outlook')
        ->assertRedirect(COBRO_WEB_LINK);

    expect($graph->requests())->toHaveCount(1)
        ->and(urldecode($graph->requests()[0]->url()))->toContain('$select=id,webLink')
        ->and($this->reply->refresh()->graph_id)->toBe('AAMkAD-original');
});

it('finds a moved message by its internetMessageId and keeps its new id', function () {
    $graph = GraphFake::install()
        ->on('GET', 'users/*/messages/AAMkAD-original', GraphFake::fixture('error-item-not-found.json'), 404)
        ->on('GET', 'users/*/messages', ['value' => [['id' => 'AAMkAD-moved', 'webLink' => COBRO_WEB_LINK]]]);

    $this->actingAs($this->analyst)->get('/cobro/respuestas/'.$this->reply->id.'/outlook')
        ->assertRedirect(COBRO_WEB_LINK);

    expect(urldecode($graph->requestsTo('GET', 'users/*/messages')[0]->url()))->toContain("\$filter=internetMessageId eq '<reply-1@tornillo.example>'")
        ->and($this->reply->refresh()->graph_id)->toBe('AAMkAD-moved');
});

it('says so when the mailbox no longer has the message', function () {
    GraphFake::install()
        ->on('GET', 'users/*/messages/AAMkAD-original', GraphFake::fixture('error-item-not-found.json'), 404)
        ->on('GET', 'users/*/messages', ['value' => []]);

    $this->actingAs($this->analyst)
        ->from('/cobro/respuestas/'.$this->reply->id)
        ->get('/cobro/respuestas/'.$this->reply->id.'/outlook')
        ->assertRedirect('/cobro/respuestas/'.$this->reply->id)
        ->assertSessionHas('error', 'Este correo ya no está en el buzón de la empresa: puede que lo hayan borrado.');
});

it('explains a Graph failure without leaving the app', function () {
    Log::spy();
    GraphFake::install()->on('GET', 'users/*/messages/AAMkAD-original', ['error' => ['code' => 'ServiceUnavailable']], 503);

    $this->actingAs($this->analyst)
        ->get('/cobro/respuestas/'.$this->reply->id.'/outlook')
        ->assertRedirect(route('cobro.respuestas.show', $this->reply))
        ->assertSessionHas('error', 'No pudimos abrir el correo en Outlook. Intenta de nuevo en unos minutos.');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'Outlook'))->once();
});

it('never redirects outside Outlook on the web', function () {
    GraphFake::install()->on('GET', 'users/*/messages/AAMkAD-original', ['id' => 'AAMkAD-original', 'webLink' => 'https://evil.example/owa/?ItemID=x']);

    $this->actingAs($this->analyst)
        ->get('/cobro/respuestas/'.$this->reply->id.'/outlook')
        ->assertRedirect(route('cobro.respuestas.show', $this->reply))
        ->assertSessionHas('error', 'No pudimos abrir el correo en Outlook. Intenta de nuevo en unos minutos.');
});

it('hides a message of another company and forbids roles without cobro.replies', function () {
    GraphFake::install();
    $sdc = InboundMessage::query()->create([
        'company' => CompanyCode::SDC, 'mailbox' => 'derivados.info@derivadoscol.com', 'graph_id' => 'sdc-1',
        'subject' => 'Mensaje de SDC', 'preview' => '', 'received_at' => CarbonImmutable::now(),
    ]);

    // The active company is Segurex.
    $this->actingAs($this->analyst)->get('/cobro/respuestas/'.$sdc->id.'/outlook')->assertNotFound();
    $this->actingAs(CobroUsers::create(Role::Admin))->get('/cobro/respuestas/'.$this->reply->id.'/outlook')->assertForbidden();
});

it('offers the Outlook link on the reply screen and in the inbox', function () {
    $link = route('cobro.respuestas.outlook', $this->reply);

    $this->actingAs($this->analyst)->get('/cobro/respuestas/'.$this->reply->id)->assertOk()
        ->assertSee('href="'.$link.'"', false)
        ->assertSeeText('Abrir en Outlook');

    $this->actingAs($this->analyst)->get('/cobro/respuestas')->assertOk()
        ->assertSee('href="'.$link.'"', false);
});
