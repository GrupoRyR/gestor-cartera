<?php

use App\Modules\Cobro\Infrastructure\Mail\GraphMailSender;
use App\Modules\Cobro\Infrastructure\Persistence\SentMessage;
use App\Modules\Core\Contracts\Data\MailAttachment;
use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Exceptions\MailNotSent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\Modules\Cobro\Support\GraphFake;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 17:00:03', 'America/Bogota'));
});

/**
 * A weekly notice to one client contact.
 */
function cobroOutgoing(array $overrides = []): OutgoingMail
{
    return new OutgoingMail(...array_merge([
        'company' => CompanyCode::SGX,
        'to' => ['Tesoreria@Cliente.example'],
        'subject' => 'Facturas vencidas de Ferretería El Tornillo SAS',
        'html' => '<p>Hola</p>',
        'clientId' => 41,
        'category' => 'cobro_semanal',
    ], $overrides));
}

/**
 * Graph accepting the draft and the send.
 */
function cobroGraphAccepting(): GraphFake
{
    return GraphFake::install()
        ->on('POST', 'users/*/messages/*/send', status: 202)
        ->on('POST', 'users/*/messages', GraphFake::fixture('draft-created.json'), 201);
}

it('creates a draft in the company mailbox, sends it and returns its ids', function () {
    $graph = cobroGraphAccepting();
    $draft = GraphFake::fixture('draft-created.json');

    $sent = app(GraphMailSender::class)->send(cobroOutgoing());

    expect($sent->mailbox)->toBe('segurex.info@segurex.com')
        ->and($sent->internetMessageId)->toBe($draft['internetMessageId'])
        ->and($sent->conversationId)->toBe($draft['conversationId'])
        ->and($sent->sentAt->equalTo(CarbonImmutable::now()))->toBeTrue();

    [$create, $send] = $graph->requests();

    expect($create->method())->toBe('POST')
        ->and($create->url())->toBe('https://graph.microsoft.com/v1.0/users/segurex.info%40segurex.com/messages')
        ->and($create->hasHeader('Authorization', 'Bearer '.GraphFake::fixture('token.json')['access_token']))->toBeTrue()
        ->and($create->data())->toBe([
            'subject' => 'Facturas vencidas de Ferretería El Tornillo SAS',
            'body' => ['contentType' => 'HTML', 'content' => '<p>Hola</p>'],
            'toRecipients' => [['emailAddress' => ['address' => 'Tesoreria@Cliente.example']]],
        ])
        ->and($send->url())->toBe('https://graph.microsoft.com/v1.0/users/segurex.info%40segurex.com/messages/'.rawurlencode($draft['id']).'/send')
        ->and($send->body())->toBe('');
});

it('asks for an app-only token with the client credentials', function () {
    cobroGraphAccepting();

    app(GraphMailSender::class)->send(cobroOutgoing());

    $token = Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'login.microsoftonline.com'))->sole()[0];

    expect($token->url())->toBe('https://login.microsoftonline.com/7c1d3e1f-0000-4000-8000-000000000001/oauth2/v2.0/token')
        ->and($token->data())->toBe([
            'client_id' => '7c1d3e1f-0000-4000-8000-000000000002',
            'client_secret' => 'recorded-fixture-secret',
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]);
});

it('reuses the cached token for later mails', function () {
    $graph = cobroGraphAccepting();

    app(GraphMailSender::class)->send(cobroOutgoing());
    app(GraphMailSender::class)->send(cobroOutgoing());

    expect($graph->tokenRequests())->toBe(1)
        ->and($graph->requestsTo('POST', 'users/*/messages/*/send'))->toHaveCount(2);
});

it('keeps the cached token encrypted', function () {
    cobroGraphAccepting();
    $token = GraphFake::fixture('token.json')['access_token'];

    app(GraphMailSender::class)->send(cobroOutgoing());

    $cached = Cache::get('cobro.graph.token');

    expect($cached)->toBeString()->not->toBe($token)->not->toContain($token)
        ->and(Crypt::decryptString($cached))->toBe($token);
});

it('asks for a new token when the cached one cannot be decrypted', function () {
    $graph = cobroGraphAccepting();
    // A token cached in plain text before encryption, or under an old APP_KEY.
    Cache::put('cobro.graph.token', 'legacy-plain-token', 3600);

    app(GraphMailSender::class)->send(cobroOutgoing());

    expect($graph->tokenRequests())->toBe(1)
        ->and($graph->requests()[0]->hasHeader('Authorization', 'Bearer '.GraphFake::fixture('token.json')['access_token']))->toBeTrue()
        ->and(Crypt::decryptString(Cache::get('cobro.graph.token')))->toBe(GraphFake::fixture('token.json')['access_token']);
});

it('sends from the SDC mailbox for SDC mail', function () {
    $graph = cobroGraphAccepting();

    $sent = app(GraphMailSender::class)->send(cobroOutgoing(['company' => CompanyCode::SDC]));

    expect($sent->mailbox)->toBe('derivados.info@derivadoscol.com')
        ->and($graph->requests()[0]->url())->toStartWith('https://graph.microsoft.com/v1.0/users/derivados.info%40derivadoscol.com/');
});

it('adds copies, reply-to and base64 attachments to the draft', function () {
    $graph = cobroGraphAccepting();

    app(GraphMailSender::class)->send(cobroOutgoing([
        'cc' => ['cartera@segurex.com'],
        'replyTo' => 'cartera@segurex.com',
        'attachments' => [new MailAttachment('estado.pdf', 'application/pdf', '%PDF-1.4 bytes')],
    ]));

    $data = $graph->requests()[0]->data();

    expect($data['ccRecipients'])->toBe([['emailAddress' => ['address' => 'cartera@segurex.com']]])
        ->and($data['replyTo'])->toBe([['emailAddress' => ['address' => 'cartera@segurex.com']]])
        ->and($data['attachments'])->toBe([[
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'name' => 'estado.pdf',
            'contentType' => 'application/pdf',
            'contentBytes' => base64_encode('%PDF-1.4 bytes'),
        ]]);
});

it('keeps the ids of every accepted mail to link replies and bounces', function () {
    cobroGraphAccepting();
    $draft = GraphFake::fixture('draft-created.json');

    app(GraphMailSender::class)->send(cobroOutgoing(['cc' => ['Cartera@Segurex.com']]));

    $record = SentMessage::query()->sole();

    expect($record->company)->toBe(CompanyCode::SGX)
        ->and($record->mailbox)->toBe('segurex.info@segurex.com')
        ->and($record->client_id)->toBe(41)
        ->and($record->category)->toBe('cobro_semanal')
        ->and($record->recipients)->toBe(['tesoreria@cliente.example', 'cartera@segurex.com'])
        ->and($record->internet_message_id)->toBe($draft['internetMessageId'])
        ->and($record->conversation_id)->toBe($draft['conversationId']);
});

it('reads the Message-ID when the draft comes back without it', function () {
    $draft = GraphFake::fixture('draft-created.json');
    $withoutId = array_diff_key($draft, ['internetMessageId' => true]);

    $graph = GraphFake::install()
        ->on('POST', 'users/*/messages/*/send', status: 202)
        ->on('POST', 'users/*/messages', $withoutId, 201)
        ->on('GET', 'users/*/messages/*', ['internetMessageId' => '<late@outlook.com>', 'conversationId' => $draft['conversationId']]);

    $sent = app(GraphMailSender::class)->send(cobroOutgoing());

    expect($sent->internetMessageId)->toBe('<late@outlook.com>')
        ->and($graph->requestsTo('GET', 'users/*/messages/*')[0]->url())->toContain('%24select=internetMessageId%2CconversationId');
});

it('throws MailNotSent and deletes the draft when Graph refuses the send', function () {
    $graph = GraphFake::install()
        ->on('POST', 'users/*/messages/*/send', GraphFake::fixture('error-invalid-recipients.json'), 400)
        ->on('POST', 'users/*/messages', GraphFake::fixture('draft-created.json'), 201)
        ->on('DELETE', 'users/*/messages/*', status: 204);

    expect(fn () => app(GraphMailSender::class)->send(cobroOutgoing()))
        ->toThrow(MailNotSent::class, 'failed with HTTP 400 (ErrorInvalidRecipients)');

    expect($graph->requestsTo('DELETE', 'users/*/messages/*'))->toHaveCount(1)
        ->and(SentMessage::query()->count())->toBe(0);
});

it('counts an unanswered send as sent when the draft already left', function () {
    $graph = GraphFake::install()
        ->on('POST', 'users/*/messages/*/send', status: GraphFake::NO_ANSWER)
        ->on('POST', 'users/*/messages', GraphFake::fixture('draft-created.json'), 201)
        ->on('GET', 'users/*/messages/*', GraphFake::fixture('error-item-not-found.json'), 404);

    $sent = app(GraphMailSender::class)->send(cobroOutgoing());

    expect($sent->internetMessageId)->toBe(GraphFake::fixture('draft-created.json')['internetMessageId'])
        ->and(SentMessage::query()->count())->toBe(1)
        ->and($graph->requestsTo('DELETE', '*'))->toBe([]);
});

it('deletes the draft and throws MailNotSent when an unanswered send left it a draft', function () {
    $graph = GraphFake::install()
        ->on('POST', 'users/*/messages/*/send', status: GraphFake::NO_ANSWER)
        ->on('POST', 'users/*/messages', GraphFake::fixture('draft-created.json'), 201)
        ->on('GET', 'users/*/messages/*', ['isDraft' => true])
        ->on('DELETE', 'users/*/messages/*', status: 204);

    expect(fn () => app(GraphMailSender::class)->send(cobroOutgoing()))->toThrow(MailNotSent::class, 'did not answer');

    expect($graph->requestsTo('DELETE', 'users/*/messages/*'))->toHaveCount(1)
        ->and(SentMessage::query()->count())->toBe(0);
});

it('keeps the draft when nobody can tell whether an unanswered send left', function () {
    $graph = GraphFake::install()
        ->on('POST', 'users/*/messages/*/send', status: GraphFake::NO_ANSWER)
        ->on('POST', 'users/*/messages', GraphFake::fixture('draft-created.json'), 201)
        ->on('GET', 'users/*/messages/*', status: GraphFake::NO_ANSWER);

    expect(fn () => app(GraphMailSender::class)->send(cobroOutgoing()))->toThrow(MailNotSent::class);

    expect($graph->requestsTo('DELETE', '*'))->toBe([]);
});

it('throws MailNotSent without deleting anything when the mailbox is out of reach', function () {
    $graph = GraphFake::install()
        ->on('POST', 'users/*/messages', GraphFake::fixture('error-access-denied.json'), 403);

    expect(fn () => app(GraphMailSender::class)->send(cobroOutgoing()))
        ->toThrow(MailNotSent::class, 'failed with HTTP 403 (ErrorAccessDenied)');

    expect($graph->requestsTo('DELETE', '*'))->toBe([])
        ->and(SentMessage::query()->count())->toBe(0);
});

it('throws MailNotSent without the secret when the token is refused', function () {
    $graph = GraphFake::install('token-invalid-client.json', 401);

    try {
        app(GraphMailSender::class)->send(cobroOutgoing());
        $this->fail('MailNotSent was not thrown.');
    } catch (MailNotSent $e) {
        expect($e->getMessage())->toContain('token request failed with HTTP 401 (invalid_client)')
            ->not->toContain('recorded-fixture-secret');
    }

    expect($graph->requests())->toBe([]);
});

it('throws MailNotSent before any request when Graph is not configured', function () {
    $graph = GraphFake::install();
    config(['cobro.graph.client_secret' => null]);

    expect(fn () => app(GraphMailSender::class)->send(cobroOutgoing()))
        ->toThrow(MailNotSent::class, 'Microsoft Graph is not configured');

    expect($graph->tokenRequests())->toBe(0)
        ->and($graph->requests())->toBe([]);
});
