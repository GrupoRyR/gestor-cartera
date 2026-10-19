<?php

use App\Modules\Cobro\Infrastructure\Mail\DatabaseMailboxReader;
use App\Modules\Cobro\Infrastructure\Persistence\InboundMessage;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\FakeCurrentCompany;
use Tests\Fakes\Make;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 17:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->reader = app(DatabaseMailboxReader::class);
});

/**
 * A stored inbound message.
 *
 * @param  array<string, mixed>  $attributes
 */
function cobroStoredInbound(array $attributes = []): InboundMessage
{
    static $sequence = 0;
    $sequence++;

    return InboundMessage::query()->create(array_merge([
        'company' => CompanyCode::SGX,
        'mailbox' => 'segurex.info@segurex.com',
        'graph_id' => "graph-{$sequence}",
        'conversation_id' => "conversation-{$sequence}",
        'from_address' => 'tesoreria@cliente.example',
        'subject' => "Respuesta {$sequence}",
        'preview' => 'Quedamos atentos.',
        'received_at' => CarbonImmutable::now()->subHours(10 - $sequence),
    ], $attributes));
}

it('lists the replies and bounces of a client, newest first', function () {
    $client = Make::clientDetail();
    $this->fakes->cartera->addClient($client);
    $id = $client->summary->id;

    $older = cobroStoredInbound(['client_id' => $id, 'received_at' => CarbonImmutable::now()->subDays(2)]);
    $bounce = cobroStoredInbound(['client_id' => $id, 'is_bounce' => true, 'bounce_reason' => '550 5.1.1 User unknown', 'read_at' => CarbonImmutable::now()]);
    cobroStoredInbound(['client_id' => $id + 1000]);

    $mails = $this->reader->forClient($id);

    expect(array_map(fn ($mail) => $mail->id, $mails))->toBe([(string) $bounce->id, (string) $older->id])
        ->and($mails[0]->isBounce)->toBeTrue()
        ->and($mails[0]->bounceReason)->toBe('550 5.1.1 User unknown')
        ->and($mails[0]->read)->toBeTrue()
        ->and($mails[0]->clientId)->toBe($id)
        ->and($mails[0]->company)->toBe(CompanyCode::SGX)
        ->and($mails[0]->from)->toBe('tesoreria@cliente.example')
        ->and($mails[1]->read)->toBeFalse()
        ->and($this->reader->forClient($id, 1))->toHaveCount(1);
});

it('reads nothing for a client out of the user scope', function () {
    $own = Make::clientDetail(Make::client(advisor: Make::advisor(code: 10)));
    $other = Make::clientDetail(Make::client(advisor: Make::advisor(code: 20)));
    $this->fakes->cartera->addClient($own)->addClient($other)->restrictToAdvisor(10);

    cobroStoredInbound(['client_id' => $own->summary->id]);
    cobroStoredInbound(['client_id' => $other->summary->id]);

    expect($this->reader->forClient($own->summary->id))->toHaveCount(1)
        ->and($this->reader->forClient($other->summary->id))->toBe([])
        ->and($this->reader->forClient(999_999))->toBe([]);
});

it('rejects a limit below one', function () {
    $this->reader->forClient(1, 0);
})->throws(InvalidArgumentException::class);

it('counts the unread messages of the active company, linked or not', function () {
    cobroStoredInbound();
    cobroStoredInbound(['client_id' => 5]);
    cobroStoredInbound(['read_at' => CarbonImmutable::now()]);
    cobroStoredInbound(['company' => CompanyCode::SDC, 'mailbox' => 'derivados.info@derivadoscol.com']);

    expect($this->reader->unreadCount())->toBe(2)
        ->and($this->reader->unreadCount(CompanyCode::SDC))->toBe(1);

    $this->fakes->currentCompany->switchTo(null);

    expect($this->reader->unreadCount())->toBe(3);
});

it('counts nothing for guests', function () {
    cobroStoredInbound();
    $this->app->instance(CurrentCompany::class, FakeCurrentCompany::guest());

    expect(app(DatabaseMailboxReader::class)->unreadCount())->toBe(0);
});
