<?php

use App\Modules\Cobro\Infrastructure\Persistence\InboundMessage;
use App\Modules\Cobro\Infrastructure\Persistence\SentMessage;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Tests\Fakes\ContractFakes;

/*
 * The SGX mailbox is also the department's work mailbox, so the sync now
 * stores only messages linked to a send. The migration 2026_10_05_000010
 * deletes, once, the rows stored before that rule that are linked to none.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 09:00:00', 'America/Bogota'));
    ContractFakes::install($this->app);
});

function cobroRunUnlinkedInboundDeletion(): void
{
    (require app_path('Modules/Cobro/database/migrations/2026_10_05_000010_delete_unlinked_cobro_inbound_messages.php'))->up();
}

function cobroMigrationSend(CompanyCode $company, string $mailbox): SentMessage
{
    static $sequence = 0;
    $sequence++;

    return SentMessage::query()->create([
        'company' => $company,
        'mailbox' => $mailbox,
        'client_id' => 10 + $sequence,
        'category' => 'cobro_semanal',
        'subject' => 'Facturas vencidas',
        'recipients' => ['tesoreria@cliente.example'],
        'internet_message_id' => "<envio-{$sequence}@outlook.com>",
        'conversation_id' => "conversacion-envio-{$sequence}",
        'sent_at' => CarbonImmutable::now()->subDays(3),
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function cobroMigrationInbound(CompanyCode $company, string $mailbox, ?SentMessage $sent, array $attributes = []): InboundMessage
{
    static $sequence = 0;
    $sequence++;

    return InboundMessage::query()->create(array_merge([
        'company' => $company,
        'mailbox' => $mailbox,
        'graph_id' => "graph-migracion-{$sequence}",
        'conversation_id' => $sent?->conversation_id ?? "otra-conversacion-{$sequence}",
        'from_address' => 'alguien@otro.example',
        'subject' => "Mensaje {$sequence}",
        'preview' => 'Texto',
        'received_at' => CarbonImmutable::now()->subDays(1),
        'sent_message_id' => $sent?->id,
        'client_id' => $sent?->client_id,
    ], $attributes));
}

it('deletes the stored inbox messages linked to no send, in every mailbox', function () {
    $sgx = 'segurex.info@segurex.com';
    $sdc = 'derivados.info@derivadoscol.com';
    $readAt = CarbonImmutable::parse('2026-11-20 10:30:00', 'America/Bogota');
    $sgxSend = cobroMigrationSend(CompanyCode::SGX, $sgx);
    $sdcSend = cobroMigrationSend(CompanyCode::SDC, $sdc);

    $keep = [
        cobroMigrationInbound(CompanyCode::SGX, $sgx, $sgxSend, ['read_at' => $readAt]),
        cobroMigrationInbound(CompanyCode::SGX, $sgx, $sgxSend, ['is_bounce' => true, 'bounce_reason' => '550 5.1.1 User unknown']),
        cobroMigrationInbound(CompanyCode::SDC, $sdc, $sdcSend),
        cobroMigrationInbound(CompanyCode::SDC, $sdc, $sdcSend, ['is_bounce' => true, 'read_at' => $readAt]),
    ];
    cobroMigrationInbound(CompanyCode::SGX, $sgx, null);
    cobroMigrationInbound(CompanyCode::SGX, $sgx, null, ['read_at' => $readAt]);
    cobroMigrationInbound(CompanyCode::SGX, $sgx, null, ['is_bounce' => true]);
    cobroMigrationInbound(CompanyCode::SDC, $sdc, null);

    Log::spy();

    cobroRunUnlinkedInboundDeletion();

    expect(InboundMessage::query()->orderBy('id')->pluck('id')->all())->toBe(array_map(fn (InboundMessage $inbound): int => $inbound->id, $keep))
        ->and(InboundMessage::query()->whereNull('sent_message_id')->exists())->toBeFalse();

    foreach ($keep as $inbound) {
        expect(InboundMessage::query()->findOrFail($inbound->id)->read_at?->toIso8601String())->toBe($inbound->read_at?->toIso8601String());
    }

    Log::shouldHaveReceived('info')->with('Cobro: deleted the stored inbox messages linked to no send.', ['mailbox' => $sgx, 'deleted' => 3])->once();
    Log::shouldHaveReceived('info')->with('Cobro: deleted the stored inbox messages linked to no send.', ['mailbox' => $sdc, 'deleted' => 1])->once();
});

it('changes nothing when every stored message is linked to a send', function () {
    $send = cobroMigrationSend(CompanyCode::SGX, 'segurex.info@segurex.com');
    $reply = cobroMigrationInbound(CompanyCode::SGX, 'segurex.info@segurex.com', $send);

    Log::spy();

    cobroRunUnlinkedInboundDeletion();

    expect(InboundMessage::query()->pluck('id')->all())->toBe([$reply->id]);

    Log::shouldNotHaveReceived('info');
});
