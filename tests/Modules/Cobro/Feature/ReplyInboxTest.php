<?php

use App\Modules\Cobro\Database\Seeders\CobroPermissionSeeder;
use App\Modules\Cobro\Infrastructure\Mail\DatabaseMailboxReader;
use App\Modules\Cobro\Infrastructure\Persistence\InboundMessage;
use App\Modules\Cobro\Infrastructure\Persistence\SentMessage;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Contracts\MailboxReader;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\NavRegistry;
use Carbon\CarbonImmutable;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\Make;
use Tests\Modules\Cobro\Support\CobroUsers;

beforeEach(function () {
    $this->seed(CobroPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-23 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->analyst = CobroUsers::create(Role::PortfolioAnalyst);

    $sent = SentMessage::query()->create([
        'company' => CompanyCode::SGX, 'mailbox' => 'segurex.info@segurex.com', 'client_id' => 41,
        'category' => 'cobro_semanal', 'subject' => 'Facturas vencidas de Ferretería El Tornillo SAS',
        'recipients' => ['tesoreria@tornillo.example'], 'internet_message_id' => '<a@b>',
        'sent_at' => CarbonImmutable::parse('2026-10-21 17:00:00', 'America/Bogota'),
    ]);

    $this->reply = cobroInboundMessage([
        'subject' => 'RE: Facturas vencidas', 'from_name' => 'Laura Martínez', 'from_address' => 'tesoreria@tornillo.example',
        'preview' => 'El pago sale el viernes.', 'body' => "El pago sale el viernes.\n\nLaura", 'client_id' => 41,
        'client_name' => 'Ferretería El Tornillo SAS', 'sent_message_id' => $sent->id,
        'received_at' => CarbonImmutable::parse('2026-10-22 09:12', 'America/Bogota'),
    ]);
    $this->bounce = cobroInboundMessage([
        'subject' => 'Undeliverable: Facturas vencidas', 'from_address' => 'postmaster@andinas.example', 'is_bounce' => true,
        'bounce_reason' => '550 5.1.1 User unknown', 'bounced_recipient' => 'pagos@andinas.example',
        'received_at' => CarbonImmutable::parse('2026-10-21 17:01', 'America/Bogota'),
    ]);
    $this->read = cobroInboundMessage([
        'subject' => 'Gracias', 'from_address' => 'otro@cliente.example', 'read_at' => CarbonImmutable::now(), 'read_by' => $this->analyst->id,
        'received_at' => CarbonImmutable::parse('2026-10-20 10:00', 'America/Bogota'),
    ]);
    $this->sdc = cobroInboundMessage([
        'company' => CompanyCode::SDC, 'mailbox' => 'derivados.info@derivadoscol.com', 'subject' => 'Mensaje de SDC',
        'from_address' => 'pagos@quimicos.example',
    ]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function cobroInboundMessage(array $attributes): InboundMessage
{
    static $sequence = 0;
    $sequence++;

    return InboundMessage::query()->create(array_merge([
        'company' => CompanyCode::SGX,
        'mailbox' => 'segurex.info@segurex.com',
        'graph_id' => "inbox-{$sequence}",
        'preview' => '',
        'received_at' => CarbonImmutable::now(),
    ], $attributes));
}

it('serves the inbox under /cobro/respuestas', function () {
    expect(route('cobro.respuestas.index', absolute: false))->toBe('/cobro/respuestas')
        ->and(route('cobro.respuestas.show', 9, absolute: false))->toBe('/cobro/respuestas/9')
        ->and(route('cobro.respuestas.mark', absolute: false))->toBe('/cobro/respuestas/marcar');
});

it('forbids the inbox to roles without cobro.replies', function (?Role $role) {
    $user = CobroUsers::create($role);

    $this->actingAs($user)->get('/cobro/respuestas')->assertForbidden();
    $this->actingAs($user)->get("/cobro/respuestas/{$this->reply->id}")->assertForbidden();
    $this->actingAs($user)->post('/cobro/respuestas/marcar', ['mensajes' => [$this->reply->id], 'accion' => 'leida'])->assertForbidden();
})->with(CobroUsers::notManagers());

it('lists the pending replies and bounces of the active company, newest first', function () {
    $this->actingAs($this->analyst)->get('/cobro/respuestas')->assertOk()
        ->assertSeeText('Respuestas de clientes')
        ->assertSeeInOrder(['RE: Facturas vencidas', 'Undeliverable: Facturas vencidas'])
        ->assertSeeText('Ferretería El Tornillo SAS')
        ->assertSeeText('550 5.1.1 User unknown')
        ->assertSeeText('Rebote')
        ->assertDontSeeText('Gracias')
        ->assertDontSeeText('Mensaje de SDC');
});

it('lists the read messages and all of them', function () {
    $this->actingAs($this->analyst)->get('/cobro/respuestas?estado=leidas')->assertOk()
        ->assertSeeText('Gracias')
        ->assertDontSeeText('RE: Facturas vencidas');

    $this->actingAs($this->analyst)->get('/cobro/respuestas?estado=todas')->assertOk()
        ->assertSeeText('Gracias')
        ->assertSeeText('RE: Facturas vencidas');
});

it('shows one reply with its text, client and the mail it answers', function () {
    $this->actingAs($this->analyst)->get("/cobro/respuestas/{$this->reply->id}")->assertOk()
        ->assertSeeText('RE: Facturas vencidas')
        ->assertSeeText('tesoreria@tornillo.example')
        ->assertSeeText('Ferretería El Tornillo SAS')
        ->assertSeeText('Facturas vencidas de Ferretería El Tornillo SAS, enviado el 21/10/2026')
        ->assertSee("El pago sale el viernes.\n\nLaura", false)
        ->assertSeeText('Marcar como leída');

    expect($this->reply->refresh()->read_at)->toBeNull();
});

it('shows the bounce reason of a bounce', function () {
    $this->actingAs($this->analyst)->get("/cobro/respuestas/{$this->bounce->id}")->assertOk()
        ->assertSeeText('Motivo del rebote')
        ->assertSeeText('550 5.1.1 User unknown')
        ->assertSeeText('pagos@andinas.example');
});

it('answers 404 for a message of another company', function () {
    $this->actingAs($this->analyst)->get("/cobro/respuestas/{$this->sdc->id}")->assertNotFound();
});

it('marks the checked messages as read and back to pending', function () {
    $this->actingAs($this->analyst)
        ->from('/cobro/respuestas')
        ->post('/cobro/respuestas/marcar', ['mensajes' => [$this->reply->id, $this->bounce->id, $this->sdc->id], 'accion' => 'leida'])
        ->assertRedirect('/cobro/respuestas')
        ->assertSessionHas('status', 'Marcaste 2 mensajes como leídos.');

    expect($this->reply->refresh()->read_at?->equalTo(CarbonImmutable::now()))->toBeTrue()
        ->and($this->reply->read_by)->toBe($this->analyst->id)
        ->and($this->sdc->refresh()->read_at)->toBeNull();

    $this->actingAs($this->analyst)
        ->post('/cobro/respuestas/marcar', ['mensajes' => [$this->reply->id], 'accion' => 'pendiente'])
        ->assertSessionHas('status', 'Marcaste 1 mensaje como pendiente.');

    expect($this->reply->refresh()->read_at)->toBeNull()
        ->and($this->reply->read_by)->toBeNull();
});

it('validates the marked messages and the action', function () {
    $this->actingAs($this->analyst)->post('/cobro/respuestas/marcar', ['accion' => 'leida'])->assertSessionHasErrors('mensajes');
    $this->actingAs($this->analyst)->post('/cobro/respuestas/marcar', ['mensajes' => [1], 'accion' => 'borrar'])->assertSessionHasErrors('accion');
});

it('feeds the client file through MailboxReader with a link to the message', function () {
    $this->fakes->cartera->addClient(Make::clientDetail(Make::client(id: 41)));

    $mails = app(DatabaseMailboxReader::class)->forClient(41);

    expect($mails)->toHaveCount(1)
        ->and($mails[0]->url)->toBe(route('cobro.respuestas.show', $this->reply->id));
});

it('adds Respuestas de clientes to the menu with the unread count', function () {
    $this->app->instance(MailboxReader::class, app(DatabaseMailboxReader::class));
    $item = collect(app(NavRegistry::class)->visibleTo($this->analyst))->firstWhere('key', 'cobro.respuestas');

    expect($item)->toBeInstanceOf(NavItem::class)
        ->and($item->badgeCount())->toBe(2)
        ->and(collect(app(NavRegistry::class)->visibleTo(CobroUsers::create(Role::Admin)))->pluck('key'))->not->toContain('cobro.respuestas');
});

it('hides the badge when nothing is pending', function () {
    InboundMessage::query()->update(['read_at' => CarbonImmutable::now()]);
    $this->app->instance(MailboxReader::class, app(DatabaseMailboxReader::class));

    $item = collect(app(NavRegistry::class)->all())->firstWhere('key', 'cobro.respuestas');

    expect($item?->badgeCount())->toBeNull();
});
