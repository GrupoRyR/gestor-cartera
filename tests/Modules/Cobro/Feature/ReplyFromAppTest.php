<?php

use App\Modules\Cobro\Database\Seeders\CobroPermissionSeeder;
use App\Modules\Cobro\Infrastructure\Persistence\InboundMessage;
use App\Modules\Cobro\Infrastructure\Persistence\SentMessage;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\AuditEntry;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Cobro\Support\CobroUsers;
use Tests\Modules\Cobro\Support\GraphFake;
use Tests\Modules\Cobro\Support\VisibleText;

/*
 * "Responder desde la app": createReply on the client message (found
 * again by its internetMessageId if it moved), the text written above the
 * quoted original (PATCH of the draft body), then send. The reply is kept
 * in cobro_sent_messages, logged in the client activity and audited.
 */

beforeEach(function () {
    $this->seed(CobroPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->fakes->gestiones->knowClient(41, 'Ferretería El Tornillo SAS');
    $this->analyst = CobroUsers::create(Role::PortfolioAnalyst, name: 'María Paula Rojas');

    $this->reply = InboundMessage::query()->create([
        'company' => CompanyCode::SGX,
        'mailbox' => 'segurex.info@segurex.com',
        'graph_id' => 'AAMkAD-original',
        'internet_message_id' => '<reply-1@tornillo.example>',
        'conversation_id' => 'conv-1',
        'from_address' => 'tesoreria@tornillo.example',
        'from_name' => 'Laura Martínez',
        'subject' => 'RE: Facturas vencidas',
        'preview' => 'El pago sale el viernes.',
        'client_id' => 41,
        'client_name' => 'Ferretería El Tornillo SAS',
        'received_at' => CarbonImmutable::parse('2026-11-18 10:00', 'America/Bogota'),
    ]);
});

/**
 * The draft Graph creates with createReply: the original quoted in the body.
 *
 * @return array<string, mixed>
 */
function cobroReplyDraft(): array
{
    return [
        'id' => 'draft-1',
        'internetMessageId' => '<draft-1@segurex.com>',
        'conversationId' => 'conv-1',
        'subject' => 'RE: RE: Facturas vencidas',
        'body' => ['contentType' => 'html', 'content' => '<html><head></head><body><div id="quoted">El pago sale el viernes.</div></body></html>'],
        'toRecipients' => [['emailAddress' => ['name' => 'Laura Martínez', 'address' => 'Tesoreria@Tornillo.example']]],
    ];
}

/**
 * Graph finding the message, creating the draft, taking its body and its
 * signature images, and sending it.
 */
function cobroGraphReplying(string $currentId = 'AAMkAD-original'): GraphFake
{
    return GraphFake::install()
        ->on('GET', "users/*/messages/{$currentId}", ['id' => $currentId, 'webLink' => 'https://outlook.office365.com/owa/?ItemID=x'])
        ->on('POST', "users/*/messages/{$currentId}/createReply", cobroReplyDraft(), 201)
        ->on('PATCH', 'users/*/messages/draft-1', cobroReplyDraft())
        ->on('POST', 'users/*/messages/draft-1/attachments', ['id' => 'attachment-1'], 201)
        ->on('POST', 'users/*/messages/draft-1/send', status: 202);
}

function cobroPostReply(string $text): TestResponse
{
    return test()->actingAs(test()->analyst)
        ->from('/cobro/respuestas/'.test()->reply->id)
        ->post('/cobro/respuestas/'.test()->reply->id.'/responder', ['texto' => $text]);
}

it('serves the reply under /cobro/respuestas/{message}/responder', function () {
    expect(route('cobro.respuestas.reply', 9, absolute: false))->toBe('/cobro/respuestas/9/responder');
});

it('replies in the same thread from the company mailbox, above the quoted message', function () {
    $graph = cobroGraphReplying();

    cobroPostReply("Gracias, Laura.\nQuedamos atentos al pago.")
        ->assertRedirect('/cobro/respuestas/'.$this->reply->id)
        ->assertSessionHas('status', 'Le respondiste a tesoreria@tornillo.example.');

    $patch = $graph->requestsTo('PATCH', 'users/segurex.info@segurex.com/messages/draft-1')[0];
    $html = $patch->data()['body']['content'];

    expect($graph->requestsTo('POST', 'users/segurex.info@segurex.com/messages/AAMkAD-original/createReply'))->toHaveCount(1)
        ->and($patch->data()['body']['contentType'])->toBe('HTML')
        ->and($html)->toContain('Gracias, Laura.<br>')
        ->and(strpos($html, 'Quedamos atentos al pago.'))->toBeLessThan(strpos($html, 'id="quoted"'))
        ->and(strpos($html, '<body>'))->toBeLessThan(strpos($html, 'Gracias, Laura.'))
        ->and($graph->requestsTo('POST', 'users/*/messages/draft-1/send'))->toHaveCount(1);

    $sent = SentMessage::query()->sole();

    expect($sent->company)->toBe(CompanyCode::SGX)
        ->and($sent->category)->toBe('respuesta')
        ->and($sent->client_id)->toBe(41)
        ->and($sent->recipients)->toBe(['tesoreria@tornillo.example'])
        ->and($sent->internet_message_id)->toBe('<draft-1@segurex.com>')
        ->and($sent->conversation_id)->toBe('conv-1')
        ->and($this->reply->refresh()->read_at)->not->toBeNull()
        ->and($this->reply->read_by)->toBe($this->analyst->id);
});

it('signs the reply with the company signature, between the text and the quoted message', function () {
    $graph = cobroGraphReplying();

    cobroPostReply('Gracias, Laura.')->assertSessionHas('status');

    $html = $graph->requestsTo('PATCH', 'users/*/messages/draft-1')[0]->data()['body']['content'];

    expect(VisibleText::of($html))->toContain('Gracias, Laura. Cordialmente, María Paula Alvarez Auxiliar de Cartera SEGUREX LATAM S.A.S.')
        ->and(strpos($html, 'Gracias, Laura.'))->toBeLessThan(strpos($html, 'data-firma="SGX"'))
        ->and(strpos($html, 'data-firma="SGX"'))->toBeLessThan(strpos($html, 'id="quoted"'))
        ->and($html)->toContain('src="cid:sgx-redes"')->toContain('src="cid:sgx-logo"');
});

it('adds the signature images to the draft, inline, before sending it', function () {
    $graph = cobroGraphReplying();

    cobroPostReply('Gracias, Laura.')->assertSessionHas('status');

    $attachments = $graph->requestsTo('POST', 'users/segurex.info@segurex.com/messages/draft-1/attachments');
    $order = array_map(fn ($request): string => $request->method().' '.basename((string) parse_url($request->url(), PHP_URL_PATH)), $graph->requests());

    expect(array_map(fn ($request): array => [$request->data()['contentId'], $request->data()['isInline'], $request->data()['name']], $attachments))->toBe([
        ['sgx-redes', true, 'sgx-redes.png'],
        ['sgx-logo', true, 'sgx-logo.png'],
    ])
        ->and($attachments[1]->data()['contentBytes'])->toBe(base64_encode((string) file_get_contents(app_path('Modules/Cobro/resources/firmas/sgx-logo.png'))))
        ->and(array_slice($order, -4))->toBe(['PATCH draft-1', 'POST attachments', 'POST attachments', 'POST send']);
});

it('deletes the draft and sends nothing when Graph refuses a signature image', function () {
    $graph = GraphFake::install()
        ->on('GET', 'users/*/messages/AAMkAD-original', ['id' => 'AAMkAD-original'])
        ->on('POST', 'users/*/messages/AAMkAD-original/createReply', cobroReplyDraft(), 201)
        ->on('PATCH', 'users/*/messages/draft-1', cobroReplyDraft())
        ->on('POST', 'users/*/messages/draft-1/attachments', ['error' => ['code' => 'ErrorQuotaExceeded']], 507)
        ->on('DELETE', 'users/*/messages/draft-1', status: 204);

    cobroPostReply('Gracias, Laura.')
        ->assertSessionHas('error', 'No pudimos enviar tu respuesta. Intenta de nuevo en unos minutos.');

    expect($graph->requestsTo('POST', 'users/*/messages/draft-1/send'))->toBe([])
        ->and($graph->requestsTo('DELETE', 'users/*/messages/draft-1'))->toHaveCount(1)
        ->and(SentMessage::query()->count())->toBe(0);
});

it('logs the reply in the client activity and in the audit log, with who answered', function () {
    cobroGraphReplying();

    cobroPostReply('Gracias.')->assertSessionHasNoErrors();

    $this->fakes->gestiones->assertLogged(ActivityType::EmailSent, 41);
    expect($this->fakes->gestiones->logged()[0]->summary)->toBe('Respuesta enviada a tesoreria@tornillo.example por María Paula Rojas')
        ->and($this->fakes->gestiones->logged()[0]->details)->toBe('RE: RE: Facturas vencidas');

    // The audit log adds the authenticated user (the analyst) as the actor.
    $this->fakes->audit->assertRecorded('cobro.reply.sent', fn (AuditEntry $entry): bool => $entry->subjectType === 'cobro_inbound_message'
        && $entry->subjectId === $this->reply->id
        && $entry->context === ['client_id' => 41, 'to' => ['tesoreria@tornillo.example'], 'internet_message_id' => '<draft-1@segurex.com>']);
});

it('still reports the reply as sent when its client no longer exists', function () {
    cobroGraphReplying();
    $this->reply->update(['client_id' => 999]);

    cobroPostReply('Gracias.')->assertSessionHas('status', 'Le respondiste a tesoreria@tornillo.example.');

    $this->fakes->gestiones->assertNothingLogged();
    $this->fakes->audit->assertRecorded('cobro.reply.sent');
});

it('escapes the text the user wrote', function () {
    $graph = cobroGraphReplying();

    cobroPostReply('<script>alert(1)</script> & cía');

    expect($graph->requestsTo('PATCH', 'users/*/messages/draft-1')[0]->data()['body']['content'])
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt; &amp; cía')
        ->not->toContain('<script>');
});

it('replies to a moved message under its new id', function () {
    $graph = GraphFake::install()
        ->on('GET', 'users/*/messages/AAMkAD-original', GraphFake::fixture('error-item-not-found.json'), 404)
        ->on('GET', 'users/*/messages', ['value' => [['id' => 'AAMkAD-moved']]])
        ->on('POST', 'users/*/messages/AAMkAD-moved/createReply', cobroReplyDraft(), 201)
        ->on('PATCH', 'users/*/messages/draft-1', cobroReplyDraft())
        ->on('POST', 'users/*/messages/draft-1/attachments', ['id' => 'attachment-1'], 201)
        ->on('POST', 'users/*/messages/draft-1/send', status: 202);

    cobroPostReply('Gracias.')->assertSessionHas('status');

    expect($graph->requestsTo('POST', 'users/*/messages/AAMkAD-moved/createReply'))->toHaveCount(1)
        ->and($this->reply->refresh()->graph_id)->toBe('AAMkAD-moved');
});

it('sends nothing when the mailbox no longer has the message', function () {
    GraphFake::install()
        ->on('GET', 'users/*/messages/AAMkAD-original', GraphFake::fixture('error-item-not-found.json'), 404)
        ->on('GET', 'users/*/messages', ['value' => []]);

    cobroPostReply('Gracias.')
        ->assertRedirect('/cobro/respuestas/'.$this->reply->id)
        ->assertSessionHas('error', 'Este correo ya no está en el buzón de la empresa: puede que lo hayan borrado.');

    expect(SentMessage::query()->count())->toBe(0);
    $this->fakes->gestiones->assertNothingLogged();
});

it('deletes the draft and keeps the text when Graph refuses the send', function () {
    $graph = GraphFake::install()
        ->on('GET', 'users/*/messages/AAMkAD-original', ['id' => 'AAMkAD-original'])
        ->on('POST', 'users/*/messages/AAMkAD-original/createReply', cobroReplyDraft(), 201)
        ->on('PATCH', 'users/*/messages/draft-1', cobroReplyDraft())
        ->on('POST', 'users/*/messages/draft-1/attachments', ['id' => 'attachment-1'], 201)
        ->on('POST', 'users/*/messages/draft-1/send', ['error' => ['code' => 'ErrorSendAsDenied']], 403)
        ->on('DELETE', 'users/*/messages/draft-1', status: 204);

    cobroPostReply('Gracias, Laura.')
        ->assertRedirect('/cobro/respuestas/'.$this->reply->id)
        ->assertSessionHas('error', 'No pudimos enviar tu respuesta. Intenta de nuevo en unos minutos.')
        ->assertSessionHasInput('texto', 'Gracias, Laura.');

    expect($graph->requestsTo('DELETE', 'users/*/messages/draft-1'))->toHaveCount(1)
        ->and(SentMessage::query()->count())->toBe(0)
        ->and($this->reply->refresh()->read_at)->toBeNull();
    $this->fakes->gestiones->assertNothingLogged();
    $this->fakes->audit->assertNothingRecorded();
});

it('asks for the text and never answers a bounce', function () {
    $graph = GraphFake::install();

    cobroPostReply('  ')->assertSessionHasErrors(['texto' => 'Escribe tu respuesta.']);

    $this->reply->update(['is_bounce' => true]);
    cobroPostReply('Gracias.')->assertNotFound();

    expect($graph->requests())->toBe([]);
});

it('hides a message of another company and forbids roles without cobro.replies', function () {
    GraphFake::install();
    $this->reply->update(['company' => CompanyCode::SDC]);

    cobroPostReply('Gracias.')->assertNotFound();

    $this->reply->update(['company' => CompanyCode::SGX]);
    $this->actingAs(CobroUsers::create(Role::Admin))
        ->post('/cobro/respuestas/'.$this->reply->id.'/responder', ['texto' => 'Gracias.'])
        ->assertForbidden();
});

it('offers the reply form on the screen of a reply, not of a bounce', function () {
    $this->actingAs($this->analyst)->get('/cobro/respuestas/'.$this->reply->id)->assertOk()
        ->assertSee('action="'.route('cobro.respuestas.reply', $this->reply).'"', false)
        ->assertSeeText('Responder desde la app')
        ->assertSeeText('Enviar respuesta');

    $this->reply->update(['is_bounce' => true]);

    $this->actingAs($this->analyst)->get('/cobro/respuestas/'.$this->reply->id)->assertOk()
        ->assertDontSeeText('Responder desde la app');
});

it('links each reply of the inbox to its reply form', function () {
    $this->actingAs($this->analyst)->get('/cobro/respuestas')->assertOk()
        ->assertSee('href="'.route('cobro.respuestas.show', $this->reply).'#responder"', false);
});
