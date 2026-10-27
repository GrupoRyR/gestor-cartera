<?php

use App\Modules\Cobro\Infrastructure\Graph\GraphClient;
use App\Modules\Cobro\Infrastructure\Graph\GraphRequestFailed;
use App\Modules\Cobro\Infrastructure\Mail\BounceLinked;
use App\Modules\Cobro\Infrastructure\Persistence\InboundMessage;
use App\Modules\Cobro\Infrastructure\Persistence\MailboxCursor;
use App\Modules\Cobro\Infrastructure\Persistence\SentMessage;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
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

const COBRO_MESSAGES = 'users/segurex.info@segurex.com/messages/*';

/**
 * Graph answering the two recorded delta pages of the SGX inbox, and the
 * headers of the recorded Exchange report; the SDC mailbox is left out.
 */
function cobroInboxGraph(): GraphFake
{
    $graph = GraphFake::install()->onEach(
        'GET',
        COBRO_DELTA,
        [GraphFake::fixture('inbox-delta-page-1.json'), 200],
        [GraphFake::fixture('inbox-delta-page-2.json'), 200],
        [['value' => [], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/'.COBRO_DELTA.'?$deltatoken=siguiente'], 200],
    )->on('GET', COBRO_MESSAGES, GraphFake::fixture('message-headers-ndr.json'));
    config(['cobro.mailboxes.SDC' => '']);

    return $graph;
}

/**
 * Graph answering a delta page with only $message and, for its headers,
 * $headers with $status.
 *
 * @param  array<string, mixed>  $message
 * @param  array<string, mixed>|null  $headers
 */
function cobroOneMessageGraph(array $message, ?array $headers = null, int $status = 200): GraphFake
{
    $graph = GraphFake::install()
        ->on('GET', COBRO_DELTA, ['value' => [$message], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/'.COBRO_DELTA.'?$deltatoken=siguiente'])
        ->on('GET', COBRO_MESSAGES, $headers, $status);
    config(['cobro.mailboxes.SDC' => '']);

    return $graph;
}

/**
 * The recorded Exchange report of inbox-delta-page-1.json with $changes.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function cobroReport(array $changes = []): array
{
    $report = (array) GraphFake::fixture('inbox-delta-page-1.json')['value'];

    return array_replace((array) $report[1], $changes);
}

/**
 * The recorded first delta page as Graph answers it without the $expand:
 * no message class, and the delta link that ends the round.
 *
 * @return array<string, mixed>
 */
function cobroPageWithoutClass(): array
{
    $messages = array_map(function (mixed $message): mixed {
        unset($message['singleValueExtendedProperties']);

        return $message;
    }, (array) GraphFake::fixture('inbox-delta-page-1.json')['value']);

    return ['value' => $messages, '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/'.COBRO_DELTA.'?$deltatoken=sin-clase'];
}

/**
 * The query of a Graph request.
 *
 * @return array<array-key, mixed>
 */
function cobroQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

function cobroInbound(string $subject): InboundMessage
{
    return InboundMessage::query()->where('subject', $subject)->sole();
}

it('stores every new reply and bounce of the inbox once', function () {
    cobroInboxGraph();

    $this->artisan('cobro:sincronizar-buzones')
        ->expectsOutputToContain('Segurex Latam: 5 mensajes nuevos.')
        ->assertSuccessful();

    expect(InboundMessage::query()->count())->toBe(5);

    $this->artisan('cobro:sincronizar-buzones')->assertSuccessful();

    expect(InboundMessage::query()->count())->toBe(5);
});

it('starts with the recent created messages and their message class, and follows the next link', function () {
    $graph = cobroInboxGraph();

    $this->artisan('cobro:sincronizar-buzones')->assertSuccessful();

    [$first, $second] = $graph->requestsTo('GET', COBRO_DELTA);
    parse_str((string) parse_url($first->url(), PHP_URL_QUERY), $query);

    expect($query)->toBe([
        '$select' => 'id,internetMessageId,conversationId,subject,from,receivedDateTime,bodyPreview,body',
        '$expand' => "singleValueExtendedProperties(\$filter=id eq 'String 0x001A')",
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

    expect($graph->requestsTo('GET', COBRO_DELTA)[2]->url())->toBe($cursor->delta_link);
});

it('asks Graph for the headers of the delivery failure reports only', function () {
    $graph = cobroInboxGraph();

    $this->artisan('cobro:sincronizar-buzones')->assertSuccessful();

    $headers = $graph->requestsTo('GET', COBRO_MESSAGES);
    parse_str((string) parse_url($headers[0]->url(), PHP_URL_QUERY), $query);

    expect($headers)->toHaveCount(1)
        ->and(rawurldecode((string) parse_url($headers[0]->url(), PHP_URL_PATH)))->toBe('/v1.0/users/segurex.info@segurex.com/messages/'.cobroReport()['id'])
        ->and($query)->toBe(['$select' => 'internetMessageHeaders']);
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

it('keeps a client reply titled as a report a reply, even when it quotes the send', function () {
    Event::fake([BounceLinked::class]);
    cobroInboxGraph();

    $this->artisan('cobro:sincronizar-buzones')->assertSuccessful();

    $forged = cobroInbound('Undeliverable: RE: Facturas vencidas de Ferretería El Tornillo SAS');

    expect($forged->is_bounce)->toBeFalse()
        ->and($forged->bounce_reason)->toBeNull()
        ->and($forged->bounced_recipient)->toBeNull()
        ->and($forged->sent_message_id)->toBe($this->weekly->id)
        ->and($forged->client_id)->toBe($this->tornillo->summary->id);

    Event::assertDispatched(BounceLinked::class, fn (BounceLinked $event): bool => ! $event->bounce->is($forged));
});

it('keeps a report with our Exchange sender a reply when it came from outside', function () {
    Event::fake([BounceLinked::class]);
    cobroOneMessageGraph(cobroReport(), GraphFake::fixture('message-headers-forged.json'));

    $this->artisan('cobro:sincronizar-buzones')->assertSuccessful();

    $forged = InboundMessage::query()->sole();

    expect($forged->is_bounce)->toBeFalse()
        ->and($forged->bounce_reason)->toBeNull()
        ->and($forged->sent_message_id)->toBeNull();

    Event::assertNotDispatched(BounceLinked::class);
});

it('keeps an Exchange report from another domain a reply', function () {
    Event::fake([BounceLinked::class]);
    cobroOneMessageGraph(
        cobroReport(['from' => ['emailAddress' => ['name' => 'Microsoft Outlook', 'address' => 'MicrosoftExchange329e71ec88ae4615bbc36ab6ce41109e@attacker.example']]]),
        GraphFake::fixture('message-headers-ndr.json'),
    );

    $this->artisan('cobro:sincronizar-buzones')->assertSuccessful();

    expect(InboundMessage::query()->sole()->is_bounce)->toBeFalse();

    Event::assertNotDispatched(BounceLinked::class);
});

it('links another server\'s report by the Message-ID it quotes', function () {
    Event::fake([BounceLinked::class]);
    cobroOneMessageGraph(
        cobroReport(['from' => ['emailAddress' => ['name' => 'Mail Delivery Subsystem', 'address' => 'mailer-daemon@googlemail.com']]]),
        GraphFake::fixture('message-headers-forged.json'),
    );

    $this->artisan('cobro:sincronizar-buzones')->assertSuccessful();

    $bounce = InboundMessage::query()->sole();

    expect($bounce->is_bounce)->toBeTrue()
        ->and($bounce->sent_message_id)->toBe($this->bounced->id)
        ->and($bounce->bounced_recipient)->toBe('pagos@andinas-inexistente.example');

    Event::assertDispatchedTimes(BounceLinked::class, 1);
});

it('keeps another server\'s report a reply when it quotes no Message-ID of a send', function () {
    Event::fake([BounceLinked::class]);
    cobroOneMessageGraph(
        cobroReport([
            'from' => ['emailAddress' => ['name' => 'Mail Delivery Subsystem', 'address' => 'mailer-daemon@googlemail.com']],
            // The conversation of the send, without its Message-ID.
            'conversationId' => 'AAQkAGconversacion-original-andinas=',
            'body' => ['contentType' => 'text', 'content' => "Your message to pagos@andinas-inexistente.example couldn't be delivered.\r\n550 5.1.1 User unknown\r\nMessage-ID: <otro-envio@mail.gmail.com>"],
        ]),
        GraphFake::fixture('message-headers-forged.json'),
    );

    $this->artisan('cobro:sincronizar-buzones')->assertSuccessful();

    $reply = InboundMessage::query()->sole();

    expect($reply->is_bounce)->toBeFalse()
        ->and($reply->bounce_reason)->toBeNull()
        ->and($reply->sent_message_id)->toBe($this->bounced->id)
        ->and($reply->client_id)->toBe($this->andinas->summary->id);

    Event::assertNotDispatched(BounceLinked::class);
});

it('treats the sender as unauthenticated when the headers cannot be read', function () {
    Event::fake([BounceLinked::class]);
    Log::spy();
    $report = cobroReport();
    cobroOneMessageGraph($report, GraphFake::fixture('error-access-denied.json'), 403);

    $this->artisan('cobro:sincronizar-buzones')->assertSuccessful();

    expect(InboundMessage::query()->sole()->is_bounce)->toBeFalse();

    Event::assertNotDispatched(BounceLinked::class);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'headers')
        && $context === ['mailbox' => 'segurex.info@segurex.com', 'graph_id' => $report['id'], 'status' => 403])->once();
});

it('still links another server\'s report when its headers cannot be read', function () {
    Event::fake([BounceLinked::class]);
    cobroOneMessageGraph(
        cobroReport(['from' => ['emailAddress' => ['name' => 'Mail Delivery Subsystem', 'address' => 'mailer-daemon@googlemail.com']]]),
        status: GraphFake::NO_ANSWER,
    );

    $this->artisan('cobro:sincronizar-buzones')->assertSuccessful();

    expect(InboundMessage::query()->sole()->is_bounce)->toBeTrue();

    Event::assertDispatchedTimes(BounceLinked::class, 1);
});

it('keeps a message without a message class a reply and warns once', function () {
    Event::fake([BounceLinked::class]);
    Log::spy();
    $report = cobroReport();
    unset($report['singleValueExtendedProperties']);
    $graph = cobroOneMessageGraph($report);

    $this->artisan('cobro:sincronizar-buzones')->assertSuccessful();
    $this->artisan('cobro:sincronizar-buzones')->assertSuccessful();

    expect(InboundMessage::query()->sole()->is_bounce)->toBeFalse()
        ->and($graph->requestsTo('GET', COBRO_MESSAGES))->toBe([]);

    Event::assertNotDispatched(BounceLinked::class);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'message class')
        && $context === ['mailbox' => 'segurex.info@segurex.com', 'graph_id' => $report['id']])->once();
});

it('restarts every inbox round once, so the message class is read from then on', function () {
    MailboxCursor::query()->create([
        'mailbox' => 'segurex.info@segurex.com',
        'next_link' => 'https://graph.microsoft.com/v1.0/'.COBRO_DELTA.'?$skiptoken=antiguo',
        'delta_link' => 'https://graph.microsoft.com/v1.0/'.COBRO_DELTA.'?$deltatoken=antiguo',
        'last_synced_at' => CarbonImmutable::now(),
    ]);
    MailboxCursor::query()->create([
        'mailbox' => 'derivados.info@derivadoscol.com',
        'delta_link' => 'https://graph.microsoft.com/v1.0/users/derivados/delta?$deltatoken=antiguo',
    ]);

    $migration = require base_path('app/Modules/Cobro/database/migrations/2026_10_05_000007_reset_mailbox_cursors_for_bounce_class.php');
    $migration->up();

    expect(MailboxCursor::query()->whereNotNull('delta_link')->orWhereNotNull('next_link')->count())->toBe(0)
        ->and(MailboxCursor::query()->count())->toBe(2)
        ->and(MailboxCursor::query()->where('mailbox', 'segurex.info@segurex.com')->sole()->last_synced_at)->not->toBeNull();
});

it('reads the first page once more without the message class when Graph refuses the query', function (int $status) {
    Event::fake([BounceLinked::class]);
    Log::spy();
    $graph = GraphFake::install()->onEach(
        'GET',
        COBRO_DELTA,
        [['error' => ['code' => 'BadRequest', 'message' => 'Parsing OData Select and Expand failed.']], $status],
        [cobroPageWithoutClass(), 200],
    );
    config(['cobro.mailboxes.SDC' => '']);

    $this->artisan('cobro:sincronizar-buzones')
        ->expectsOutputToContain('Segurex Latam: 4 mensajes nuevos.')
        ->assertSuccessful();

    [$first, $second] = $graph->requestsTo('GET', COBRO_DELTA);
    $query = cobroQuery($first);
    unset($query['$expand']);
    $cursor = MailboxCursor::query()->sole();

    expect($graph->requestsTo('GET', COBRO_DELTA))->toHaveCount(2)
        ->and(cobroQuery($first))->toHaveKey('$expand', "singleValueExtendedProperties(\$filter=id eq 'String 0x001A')")
        ->and(cobroQuery($second))->toBe($query)
        ->and(InboundMessage::query()->count())->toBe(4)
        ->and(InboundMessage::query()->where('is_bounce', true)->count())->toBe(0)
        ->and($graph->requestsTo('GET', COBRO_MESSAGES))->toBe([])
        ->and($cursor->delta_link)->toBe(cobroPageWithoutClass()['@odata.deltaLink'])
        ->and($cursor->next_link)->toBeNull()
        ->and($cursor->last_error)->toBeNull();

    Event::assertNotDispatched(BounceLinked::class);
    Log::shouldHaveReceived('warning')->once();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'message class')
        && $context === ['mailbox' => 'segurex.info@segurex.com', 'status' => $status])->once();
})->with(['bad request' => 400, 'not implemented' => 501]);

it('never retries the first page on any other Graph error', function (int $status) {
    $graph = GraphFake::install()->onEach(
        'GET',
        COBRO_DELTA,
        [['error' => ['code' => 'Refused']], $status],
        [cobroPageWithoutClass(), 200],
    );
    config(['cobro.mailboxes.SDC' => '']);

    $this->artisan('cobro:sincronizar-buzones')
        ->expectsOutputToContain('Segurex Latam: no se pudo leer el buzón.')
        ->assertFailed();

    expect($graph->requestsTo('GET', COBRO_DELTA))->toHaveCount(1)
        ->and(InboundMessage::query()->count())->toBe(0)
        ->and(MailboxCursor::query()->sole()->last_error)->toContain("HTTP {$status}");
})->with([401, 403, 429, 500, 503]);

it('never retries a saved link that Graph refuses', function (string $link) {
    $graph = GraphFake::install()->onEach(
        'GET',
        COBRO_DELTA,
        [['error' => ['code' => 'BadRequest']], 400],
        [cobroPageWithoutClass(), 200],
    );
    config(['cobro.mailboxes.SDC' => '']);
    MailboxCursor::query()->create(['mailbox' => 'segurex.info@segurex.com', $link => 'https://graph.microsoft.com/v1.0/'.COBRO_DELTA.'?$skiptoken=guardado']);

    $this->artisan('cobro:sincronizar-buzones')->assertFailed();

    expect($graph->requestsTo('GET', COBRO_DELTA))->toHaveCount(1)
        ->and($graph->requests()[0]->url())->toBe('https://graph.microsoft.com/v1.0/'.COBRO_DELTA.'?$skiptoken=guardado')
        ->and(MailboxCursor::query()->sole()->last_error)->toContain('HTTP 400');
})->with(['next link' => 'next_link', 'delta link' => 'delta_link']);

it('counts the tenant domain as ours for our Exchange\'s reports only when it is configured', function (array $tenantDomains, bool $bounce) {
    Event::fake([BounceLinked::class]);
    cobroOneMessageGraph(
        cobroReport([
            'from' => ['emailAddress' => ['name' => 'Microsoft Outlook', 'address' => 'postmaster@gruporyr.onmicrosoft.com']],
            // The conversation of the send, without its Message-ID.
            'conversationId' => 'AAQkAGconversacion-original-andinas=',
            'body' => ['contentType' => 'text', 'content' => "Your message to pagos@andinas-inexistente.example couldn't be delivered.\r\n550 5.1.10 RESOLVER.ADR.RecipientNotFound"],
        ]),
        GraphFake::fixture('message-headers-ndr.json'),
    );
    config(['cobro.tenant_domains' => $tenantDomains]);

    $this->artisan('cobro:sincronizar-buzones')->assertSuccessful();

    $inbound = InboundMessage::query()->sole();

    expect($inbound->is_bounce)->toBe($bounce)
        ->and($inbound->sent_message_id)->toBe($this->bounced->id)
        ->and($inbound->bounced_recipient)->toBe($bounce ? 'pagos@andinas-inexistente.example' : null);

    Event::assertDispatchedTimes(BounceLinked::class, $bounce ? 1 : 0);
})->with([
    'configured' => [['gruporyr.onmicrosoft.com'], true],
    'not configured' => [[], false],
]);

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

    expect($logged)->toHaveCount(4)
        ->and(array_map(fn ($activity) => $activity->type, $logged))->each->toBe(ActivityType::EmailReceived)
        ->and(array_map(fn ($activity) => $activity->summary, $logged))->toBe([
            'Respuesta por correo de tesoreria@cliente.example',
            'Rebote del correo de cobro a pagos@andinas-inexistente.example',
            'Respuesta por correo de tesoreria@cliente.example',
            'Respuesta por correo de tesoreria@cliente.example',
        ]);
});

it('continues an unfinished round in the next run', function () {
    $graph = cobroInboxGraph();
    config(['cobro.sync.max_pages' => 1]);

    $this->artisan('cobro:sincronizar-buzones')->assertSuccessful();

    $cursor = MailboxCursor::query()->sole();

    expect(InboundMessage::query()->count())->toBe(4)
        ->and($cursor->next_link)->toBe(GraphFake::fixture('inbox-delta-page-1.json')['@odata.nextLink'])
        ->and($cursor->delta_link)->toBeNull();

    $this->artisan('cobro:sincronizar-buzones')->assertSuccessful();

    expect(InboundMessage::query()->count())->toBe(5)
        ->and($graph->requestsTo('GET', COBRO_DELTA)[1]->url())->toBe(GraphFake::fixture('inbox-delta-page-1.json')['@odata.nextLink'])
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

it('never follows a saved link outside Graph and restarts the round', function () {
    $graph = cobroInboxGraph();
    MailboxCursor::query()->create([
        'mailbox' => 'segurex.info@segurex.com',
        'next_link' => 'https://graph.evil.example/v1.0/'.COBRO_DELTA.'?$skiptoken=robado',
        'delta_link' => 'https://graph.microsoft.com/v1.0/'.COBRO_DELTA.'?$deltatoken=valido',
    ]);

    $this->artisan('cobro:sincronizar-buzones')
        ->expectsOutputToContain('Segurex Latam: no se pudo leer el buzón.')
        ->assertFailed();

    $cursor = MailboxCursor::query()->sole();

    expect($graph->requests())->toBe([])
        ->and($graph->tokenRequests())->toBe(0)
        ->and($cursor->next_link)->toBeNull()
        ->and($cursor->delta_link)->toBeNull()
        ->and($cursor->last_error)->toContain('graph.evil.example')->not->toContain('robado');

    $this->artisan('cobro:sincronizar-buzones')->assertSuccessful();

    parse_str((string) parse_url($graph->requests()[0]->url(), PHP_URL_QUERY), $query);

    expect($query)->toHaveKey('$filter');
});

it('refuses absolute URLs that are not Graph v1.0 before asking for a token', function (string $url) {
    $graph = GraphFake::install();

    try {
        app(GraphClient::class)->get($url);
        $this->fail('GraphRequestFailed was not thrown.');
    } catch (GraphRequestFailed $e) {
        expect($e->graphCode)->toBe(GraphClient::REJECTED_URL)
            ->and($e->getMessage())->not->toContain('secreto');
    }

    expect($graph->requests())->toBe([])
        ->and($graph->tokenRequests())->toBe(0);
})->with([
    'plain http' => ['http://graph.microsoft.com/v1.0/users/a/messages?$skiptoken=secreto'],
    'another host' => ['https://graph.microsoft.com.evil.example/v1.0/users/a/messages?$skiptoken=secreto'],
    'user info' => ['https://graph.microsoft.com@evil.example/v1.0/users/a/messages?$skiptoken=secreto'],
    'credentials' => ['https://user:pw@graph.microsoft.com/v1.0/users/a/messages?$skiptoken=secreto'],
    'a port' => ['https://graph.microsoft.com:8443/v1.0/users/a/messages?$skiptoken=secreto'],
    'beta' => ['https://graph.microsoft.com/beta/users/a/messages?$skiptoken=secreto'],
    'no version folder' => ['https://graph.microsoft.com/v1.0x/users/a/messages?$skiptoken=secreto'],
    'backslash' => ['https://evil.example\@graph.microsoft.com/v1.0/users/a/messages?$skiptoken=secreto'],
]);

it('follows a Graph v1.0 link whatever the case of its host', function () {
    $graph = GraphFake::install()->on('GET', 'users/a/messages', ['value' => []]);

    app(GraphClient::class)->get('HTTPS://Graph.Microsoft.COM/v1.0/users/a/messages?$skiptoken=1');

    expect($graph->requests())->toHaveCount(1)
        ->and($graph->requests()[0]->url())->toStartWith('https://graph.microsoft.com/v1.0/users/a/messages?');
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
