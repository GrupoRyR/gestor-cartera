<?php

use App\Modules\Cobro\Infrastructure\Mail\BounceLinked;
use App\Modules\Cobro\Infrastructure\Persistence\InboundMessage;
use App\Modules\Cobro\Infrastructure\Persistence\MailboxCursor;
use App\Modules\Cobro\Infrastructure\Persistence\SentMessage;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\Make;
use Tests\Modules\Cobro\Support\GraphFake;

const COBRO_DELTA = 'users/segurex.info@segurex.com/mailFolders/inbox/messages/delta';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 17:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);

    $this->tornillo = Make::clientDetail(Make::client(name: 'Ferretería El Tornillo SAS'));
    $this->andinas = Make::clientDetail(Make::client(name: 'Distribuciones Andinas SAS'));
    $this->fakes->cartera->addClient($this->tornillo)->addClient($this->andinas);

    $draft = GraphFake::fixture('draft-created.json');

    $this->weekly = SentMessage::query()->create([
        'company' => CompanyCode::SGX,
        'mailbox' => 'segurex.info@segurex.com',
        'client_id' => $this->tornillo->summary->id,
        'category' => 'cobro_semanal',
        'subject' => 'Facturas vencidas de Ferretería El Tornillo SAS',
        'recipients' => ['tesoreria@cliente.example'],
        'internet_message_id' => $draft['internetMessageId'],
        'conversation_id' => $draft['conversationId'],
        'sent_at' => CarbonImmutable::parse('2026-10-21 17:00:03', 'America/Bogota'),
    ]);

    $this->bounced = SentMessage::query()->create([
        'company' => CompanyCode::SGX,
        'mailbox' => 'segurex.info@segurex.com',
        'client_id' => $this->andinas->summary->id,
        'category' => 'cobro_semanal',
        'subject' => 'Facturas vencidas de Distribuciones Andinas SAS',
        'recipients' => ['pagos@andinas-inexistente.example'],
        'internet_message_id' => '<SA1PR11MB8471ANDINAS0002@SA1PR11MB8471.namprd11.prod.outlook.com>',
        'conversation_id' => 'AAQkAGconversacion-original-andinas=',
        'sent_at' => CarbonImmutable::parse('2026-10-21 17:00:05', 'America/Bogota'),
    ]);
});

/**
 * Graph answering the two recorded delta pages of the SGX inbox; the SDC
 * mailbox is left out.
 */
function cobroInboxGraph(): GraphFake
{
    $graph = GraphFake::install()->onEach(
        'GET',
        COBRO_DELTA,
        [GraphFake::fixture('inbox-delta-page-1.json'), 200],
        [GraphFake::fixture('inbox-delta-page-2.json'), 200],
        [['value' => [], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/'.COBRO_DELTA.'?$deltatoken=siguiente'], 200],
    );
    config(['cobro.mailboxes.SDC' => '']);

    return $graph;
}

function cobroInbound(string $subject): InboundMessage
{
    return InboundMessage::query()->where('subject', $subject)->sole();
}

it('stores every new reply and bounce of the inbox once', function () {
    cobroInboxGraph();

    $this->artisan('cobro:sincronizar-buzones')
        ->expectsOutputToContain('Segurex Latam: 4 mensajes nuevos.')
        ->assertSuccessful();

    expect(InboundMessage::query()->count())->toBe(4);

    $this->artisan('cobro:sincronizar-buzones')->assertSuccessful();

    expect(InboundMessage::query()->count())->toBe(4);
});

it('starts with the recent created messages and follows the next link', function () {
    $graph = cobroInboxGraph();

    $this->artisan('cobro:sincronizar-buzones')->assertSuccessful();

    [$first, $second] = $graph->requests();
    parse_str((string) parse_url($first->url(), PHP_URL_QUERY), $query);

    expect($query)->toBe([
        '$select' => 'id,internetMessageId,conversationId,subject,from,receivedDateTime,bodyPreview,body',
        '$filter' => 'receivedDateTime ge 2026-09-23T00:00:00Z',
        'changeType' => 'created',
    ])
        ->and($first->header('Prefer'))->toBe(['odata.maxpagesize=50, outlook.body-content-type="text"'])
        ->and($second->url())->toBe(GraphFake::fixture('inbox-delta-page-1.json')['@odata.nextLink'])
        ->and($second->header('Prefer'))->toBe(['odata.maxpagesize=50, outlook.body-content-type="text"']);
});

it('saves the delta link and starts the next run from it', function () {
    $graph = cobroInboxGraph();

    $this->artisan('cobro:sincronizar-buzones')->assertSuccessful();

    $cursor = MailboxCursor::query()->where('mailbox', 'segurex.info@segurex.com')->sole();

    expect($cursor->delta_link)->toBe(GraphFake::fixture('inbox-delta-page-2.json')['@odata.deltaLink'])
        ->and($cursor->next_link)->toBeNull()
        ->and($cursor->last_error)->toBeNull()
        ->and($cursor->last_synced_at?->equalTo(CarbonImmutable::now()))->toBeTrue();

    $this->artisan('cobro:sincronizar-buzones')->assertSuccessful();

    expect($graph->requests()[2]->url())->toBe($cursor->delta_link);
});

it('links a reply in the same conversation to the send and its client', function () {
    cobroInboxGraph();

    $this->artisan('cobro:sincronizar-buzones')->assertSuccessful();

    $reply = cobroInbound('RE: Facturas vencidas de Ferretería El Tornillo SAS');

    expect($reply->company)->toBe(CompanyCode::SGX)
        ->and($reply->is_bounce)->toBeFalse()
        ->and($reply->sent_message_id)->toBe($this->weekly->id)
        ->and($reply->client_id)->toBe($this->tornillo->summary->id)
        ->and($reply->client_name)->toBe('Ferretería El Tornillo SAS')
        ->and($reply->from_address)->toBe('tesoreria@cliente.example')
        ->and($reply->from_name)->toBe('Laura Martínez')
        ->and($reply->preview)->toStartWith('Buenos días, el pago de la factura FV-1201')
        ->and($reply->body)->toContain('Quedamos atentos.')
        ->and($reply->received_at->equalTo(CarbonImmutable::parse('2026-10-22T14:12:40Z')))->toBeTrue()
        ->and($reply->read_at)->toBeNull();
});

it('links a bounce by the Message-ID it quotes and reads why it failed', function () {
    Event::fake([BounceLinked::class]);
    cobroInboxGraph();

    $this->artisan('cobro:sincronizar-buzones')->assertSuccessful();

    $bounce = cobroInbound('Undeliverable: Facturas vencidas de Distribuciones Andinas SAS');

    expect($bounce->is_bounce)->toBeTrue()
        ->and($bounce->sent_message_id)->toBe($this->bounced->id)
        ->and($bounce->client_id)->toBe($this->andinas->summary->id)
        ->and($bounce->bounced_recipient)->toBe('pagos@andinas-inexistente.example')
        ->and($bounce->bounce_reason)->toBe("Remote server returned '550 5.1.10 RESOLVER.ADR.RecipientNotFound; Recipient pagos@andinas-inexistente.example not found by SMTP address lookup'");

    Event::assertDispatchedTimes(BounceLinked::class, 1);
    Event::assertDispatched(BounceLinked::class, fn (BounceLinked $event): bool => $event->bounce->is($bounce));
});

it('links a new mail from a contact the client was mailed at', function () {
    cobroInboxGraph();

    $this->artisan('cobro:sincronizar-buzones')->assertSuccessful();

    $consult = cobroInbound('Consulta sobre el estado de cuenta');

    expect($consult->sent_message_id)->toBe($this->weekly->id)
        ->and($consult->client_id)->toBe($this->tornillo->summary->id);
});

it('keeps unrelated mail unlinked', function () {
    cobroInboxGraph();

    $this->artisan('cobro:sincronizar-buzones')->assertSuccessful();

    $newsletter = cobroInbound('Boletín de novedades de octubre');

    expect($newsletter->sent_message_id)->toBeNull()
        ->and($newsletter->client_id)->toBeNull()
        ->and($newsletter->is_bounce)->toBeFalse();
});

it('logs linked replies and bounces in the client activity', function () {
    cobroInboxGraph();

    $this->artisan('cobro:sincronizar-buzones')->assertSuccessful();

    $logged = $this->fakes->gestiones->logged();

    expect($logged)->toHaveCount(3)
        ->and(array_map(fn ($activity) => $activity->type, $logged))->each->toBe(ActivityType::EmailReceived)
        ->and(array_map(fn ($activity) => $activity->summary, $logged))->toBe([
            'Respuesta por correo de tesoreria@cliente.example',
            'Rebote del correo de cobro a pagos@andinas-inexistente.example',
            'Respuesta por correo de tesoreria@cliente.example',
        ]);
});

it('continues an unfinished round in the next run', function () {
    $graph = cobroInboxGraph();
    config(['cobro.sync.max_pages' => 1]);

    $this->artisan('cobro:sincronizar-buzones')->assertSuccessful();

    $cursor = MailboxCursor::query()->sole();

    expect(InboundMessage::query()->count())->toBe(3)
        ->and($cursor->next_link)->toBe(GraphFake::fixture('inbox-delta-page-1.json')['@odata.nextLink'])
        ->and($cursor->delta_link)->toBeNull();

    $this->artisan('cobro:sincronizar-buzones')->assertSuccessful();

    expect(InboundMessage::query()->count())->toBe(4)
        ->and($graph->requests()[1]->url())->toBe(GraphFake::fixture('inbox-delta-page-1.json')['@odata.nextLink'])
        ->and(MailboxCursor::query()->sole()->next_link)->toBeNull();
});

it('restarts the round when Graph says the sync state expired', function () {
    GraphFake::install()->on('GET', COBRO_DELTA, GraphFake::fixture('error-sync-state-not-found.json'), 410);
    config(['cobro.mailboxes.SDC' => '']);
    MailboxCursor::query()->create(['mailbox' => 'segurex.info@segurex.com', 'delta_link' => 'https://graph.microsoft.com/v1.0/'.COBRO_DELTA.'?$deltatoken=vencido']);

    $this->artisan('cobro:sincronizar-buzones')
        ->expectsOutputToContain('Segurex Latam: no se pudo leer el buzón.')
        ->assertFailed();

    $cursor = MailboxCursor::query()->sole();

    expect($cursor->delta_link)->toBeNull()
        ->and($cursor->last_error)->toContain('HTTP 410 (SyncStateNotFound)');
});

it('keeps reading the other inboxes when one fails', function () {
    GraphFake::install()
        ->on('GET', 'users/segurex.info@segurex.com/*', GraphFake::fixture('error-access-denied.json'), 403)
        ->on('GET', 'users/derivados.info@derivadoscol.com/*', ['value' => [], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/users/derivados/delta?$deltatoken=1']);

    $this->artisan('cobro:sincronizar-buzones')
        ->expectsOutputToContain('Segurex Latam: no se pudo leer el buzón.')
        ->expectsOutputToContain('Soluciones y Derivados: 0 mensajes nuevos.')
        ->assertFailed();
});

it('only warns when Graph is not configured', function () {
    $graph = cobroInboxGraph();
    config(['cobro.graph.client_secret' => '']);

    $this->artisan('cobro:sincronizar-buzones')
        ->expectsOutputToContain('Microsoft Graph no está configurado')
        ->assertSuccessful();

    expect($graph->requests())->toBe([])
        ->and($graph->tokenRequests())->toBe(0);
});
