<?php

use App\Modules\Cobro\Domain\Runs\ItemStatus;
use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Cobro\Domain\Runs\RunStatus;
use App\Modules\Cobro\Infrastructure\Persistence\InboundMessage;
use App\Modules\Cobro\Infrastructure\Persistence\Run;
use App\Modules\Cobro\Infrastructure\Persistence\RunItem;
use App\Modules\Cobro\Infrastructure\Persistence\SentMessage;
use App\Modules\Cobro\Infrastructure\Results\DatabaseMailRunResults;
use App\Modules\Core\Contracts\Data\MailRunResults;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\MailRunKind;
use App\Modules\Core\Contracts\MailRunResultsProvider;
use Carbon\CarbonImmutable;

/**
 * A completed run scheduled at $scheduledFor (Bogotá), stored in the app
 * timezone as RunPlanner stores it, with one item per status. Sent and
 * bounced items get the sent message their replies link to.
 *
 * @param  list<ItemStatus>  $statuses
 * @return list<RunItem>
 */
function mailResultsRun(CompanyCode $company, RunKind $kind, string $scheduledFor, array $statuses): array
{
    $moment = CarbonImmutable::parse($scheduledFor, 'America/Bogota')->setTimezone((string) config('app.timezone'));

    $run = Run::query()->create([
        'company' => $company,
        'kind' => $kind,
        'status' => RunStatus::Completed,
        'scheduled_for' => $moment,
        'started_at' => $moment,
        'finished_at' => $moment->addMinutes(3),
    ]);

    $items = [];

    foreach ($statuses as $index => $status) {
        $clientId = 1000 + $run->id * 100 + $index;
        $mailed = $status === ItemStatus::Sent || $status === ItemStatus::Bounced;
        $messageId = "<run-{$run->id}-{$index}@gestor-cartera.test>";

        $sent = $mailed ? SentMessage::query()->create([
            'company' => $company,
            'mailbox' => 'segurex.info@segurex.com',
            'client_id' => $clientId,
            'category' => $kind->value,
            'subject' => 'Facturas vencidas',
            'recipients' => ['tesoreria@cliente.example'],
            'internet_message_id' => $messageId,
            'sent_at' => $moment,
        ]) : null;

        $items[] = RunItem::query()->create([
            'run_id' => $run->id,
            'client_id' => $clientId,
            'client_code' => 'C'.$index,
            'client_name' => 'Cliente '.$index,
            'recipients' => ['tesoreria@cliente.example'],
            'amount' => '1500000.00',
            'invoice_count' => 1,
            'status' => $status,
            'sent_message_id' => $sent?->id,
            'internet_message_id' => $sent === null ? null : $messageId,
            'sent_at' => $sent === null ? null : $moment,
        ]);
    }

    return $items;
}

/**
 * A reply (or bounce) read from the inbox, linked to the item's send.
 */
function mailResultsReply(RunItem $item, bool $bounce = false): InboundMessage
{
    static $sequence = 0;
    $sequence++;

    return InboundMessage::query()->create([
        'company' => CompanyCode::SGX,
        'mailbox' => 'segurex.info@segurex.com',
        'graph_id' => "graph-{$sequence}",
        'from_address' => $bounce ? 'postmaster@cliente.example' : 'tesoreria@cliente.example',
        'subject' => $bounce ? 'Undeliverable: Facturas vencidas' : 'RE: Facturas vencidas',
        'preview' => $bounce ? 'Delivery has failed.' : 'Pagamos el viernes.',
        'received_at' => CarbonImmutable::parse('2026-10-22 08:00:00', 'America/Bogota'),
        'is_bounce' => $bounce,
        'sent_message_id' => $item->sent_message_id,
    ]);
}

function mailResults(CompanyCode $company, MailRunKind $kind, string $from, string $to): MailRunResults
{
    return app(MailRunResultsProvider::class)->results(
        $company,
        $kind,
        CarbonImmutable::parse($from, 'America/Bogota'),
        CarbonImmutable::parse($to, 'America/Bogota'),
    );
}

beforeEach(function () {
    $this->items = mailResultsRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-21 17:00:00', [
        ItemStatus::Sent, ItemStatus::Sent, ItemStatus::Sent, ItemStatus::Bounced, ItemStatus::NoEmail,
        ItemStatus::Excluded, ItemStatus::Skipped, ItemStatus::Failed, ItemStatus::Pending, ItemStatus::Sending,
    ]);
});

it('resolves the contract to the Cobro implementation', function () {
    expect(app(MailRunResultsProvider::class))->toBeInstanceOf(DatabaseMailRunResults::class);
});

it('counts the clients mailed, bounced and without e-mail of the run', function () {
    $results = mailResults(CompanyCode::SGX, MailRunKind::WeeklyCollection, '2026-10-01', '2026-10-31');

    expect($results->company)->toBe(CompanyCode::SGX)
        ->and($results->kind)->toBe(MailRunKind::WeeklyCollection)
        ->and($results->from->toDateString())->toBe('2026-10-01')
        ->and($results->to->toDateString())->toBe('2026-10-31')
        ->and($results->runs)->toBe(1)
        ->and($results->sent)->toBe(4)
        ->and($results->bounced)->toBe(1)
        ->and($results->withoutEmail)->toBe(1)
        ->and($results->replies)->toBe(0)
        ->and($results->delivered())->toBe(3);
});

it('counts the clients who answered, not the messages, and ignores bounces', function () {
    mailResultsReply($this->items[0]);
    mailResultsReply($this->items[0]);
    mailResultsReply($this->items[1], bounce: true);
    mailResultsReply($this->items[3], bounce: true);
    mailResultsReply($this->items[3]);

    expect(mailResults(CompanyCode::SGX, MailRunKind::WeeklyCollection, '2026-10-01', '2026-10-31')->replies)->toBe(2);
});

it('ignores the replies to runs outside the period and the unlinked replies', function () {
    [$earlier] = mailResultsRun(CompanyCode::SGX, RunKind::Weekly, '2026-09-30 17:00:00', [ItemStatus::Sent]);
    mailResultsReply($earlier);

    InboundMessage::query()->create([
        'company' => CompanyCode::SGX,
        'mailbox' => 'segurex.info@segurex.com',
        'graph_id' => 'graph-unlinked',
        'subject' => 'Consulta',
        'preview' => 'Hola',
        'received_at' => CarbonImmutable::parse('2026-10-22 08:00:00', 'America/Bogota'),
        'is_bounce' => false,
    ]);

    $october = mailResults(CompanyCode::SGX, MailRunKind::WeeklyCollection, '2026-10-01', '2026-10-31');
    $september = mailResults(CompanyCode::SGX, MailRunKind::WeeklyCollection, '2026-09-01', '2026-09-30');

    expect($october->replies)->toBe(0)
        ->and($october->runs)->toBe(1)
        ->and($september->replies)->toBe(1)
        ->and($september->sent)->toBe(1);
});

it('keeps each company and each kind apart', function () {
    mailResultsRun(CompanyCode::SDC, RunKind::Weekly, '2026-10-21 17:00:00', [ItemStatus::Sent, ItemStatus::Sent]);
    mailResultsRun(CompanyCode::SGX, RunKind::Circularization, '2026-10-01 09:00:00', [ItemStatus::Sent, ItemStatus::NoEmail, ItemStatus::NoEmail]);

    $sdc = mailResults(CompanyCode::SDC, MailRunKind::WeeklyCollection, '2026-10-01', '2026-10-31');
    $circularization = mailResults(CompanyCode::SGX, MailRunKind::Circularization, '2026-10-01', '2026-10-31');
    $weekly = mailResults(CompanyCode::SGX, MailRunKind::WeeklyCollection, '2026-10-01', '2026-10-31');

    expect([$sdc->runs, $sdc->sent, $sdc->withoutEmail])->toBe([1, 2, 0])
        ->and([$circularization->runs, $circularization->sent, $circularization->withoutEmail])->toBe([1, 1, 2])
        ->and([$weekly->runs, $weekly->sent, $weekly->withoutEmail])->toBe([1, 4, 1]);
});

it('sums every run scheduled in the period, both ends included', function () {
    mailResultsRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-01 00:00:00', [ItemStatus::Sent]);
    mailResultsRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-31 23:59:00', [ItemStatus::Bounced, ItemStatus::NoEmail]);
    mailResultsRun(CompanyCode::SGX, RunKind::Weekly, '2026-11-01 09:00:00', [ItemStatus::Sent]);

    $october = mailResults(CompanyCode::SGX, MailRunKind::WeeklyCollection, '2026-10-01', '2026-10-31');
    $november = mailResults(CompanyCode::SGX, MailRunKind::WeeklyCollection, '2026-11-01', '2026-11-30');

    expect([$october->runs, $october->sent, $october->bounced, $october->withoutEmail])->toBe([3, 6, 2, 2])
        ->and([$november->runs, $november->sent])->toBe([1, 1]);
});

it('compares the scheduled date as a Bogotá calendar day whatever the app timezone', function () {
    config(['app.timezone' => 'UTC']);

    // 21:00 in Bogotá on October 31 is already November 1 in UTC.
    mailResultsRun(CompanyCode::SDC, RunKind::Weekly, '2026-10-31 21:00:00', [ItemStatus::Sent]);
    // 09:00 in Bogotá on November 1 is 14:00 UTC the same day.
    mailResultsRun(CompanyCode::SDC, RunKind::Weekly, '2026-11-01 09:00:00', [ItemStatus::Sent, ItemStatus::Sent]);

    $october = mailResults(CompanyCode::SDC, MailRunKind::WeeklyCollection, '2026-10-01', '2026-10-31');
    $november = mailResults(CompanyCode::SDC, MailRunKind::WeeklyCollection, '2026-11-01', '2026-11-30');

    expect([$october->runs, $october->sent])->toBe([1, 1])
        ->and([$november->runs, $november->sent])->toBe([1, 2]);
});

it('answers zeros when no run matches', function () {
    $results = mailResults(CompanyCode::SDC, MailRunKind::Circularization, '2026-10-01', '2026-10-31');

    expect($results)->toEqual(MailRunResults::empty(
        CompanyCode::SDC,
        MailRunKind::Circularization,
        CarbonImmutable::parse('2026-10-01', 'America/Bogota'),
        CarbonImmutable::parse('2026-10-31', 'America/Bogota'),
    ))->and($results->delivered())->toBe(0);
});

it('rejects a period that ends before it starts', function () {
    expect(fn () => mailResults(CompanyCode::SGX, MailRunKind::WeeklyCollection, '2026-10-31', '2026-10-01'))
        ->toThrow(InvalidArgumentException::class);
});
