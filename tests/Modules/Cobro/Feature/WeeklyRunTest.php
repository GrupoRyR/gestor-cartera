<?php

use App\Modules\Cobro\Domain\Runs\ItemStatus;
use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Cobro\Domain\Runs\RunStatus;
use App\Modules\Cobro\Infrastructure\Mail\BounceLinked;
use App\Modules\Cobro\Infrastructure\Persistence\InboundMessage;
use App\Modules\Cobro\Infrastructure\Persistence\Run;
use App\Modules\Cobro\Infrastructure\Persistence\RunItem;
use App\Modules\Cobro\Infrastructure\Persistence\SentMessage;
use App\Modules\Cobro\Infrastructure\Runs\SendRunItem;
use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Exceptions\MailNotSent;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Cobro\Support\CobroPortfolio;

beforeEach(function () {
    // Wednesday, the scheduled moment.
    $this->travelTo(CarbonImmutable::parse('2026-10-21 17:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->portfolio = CobroPortfolio::seed($this->fakes);
});

function cobroItem(int $clientId): RunItem
{
    return RunItem::query()->where('client_id', $clientId)->sole();
}

it('plans one item per overdue client of the company and mails the pending ones', function () {
    $this->artisan('cobro:cobro-semanal')
        ->expectsOutputToContain('Segurex Latam: Cobro semanal en cola para 1 clientes; 1 sin correo y 1 excluidos.')
        ->assertSuccessful();

    $run = Run::query()->sole();

    expect($run->company)->toBe(CompanyCode::SGX)
        ->and($run->kind)->toBe(RunKind::Weekly)
        ->and($run->status)->toBe(RunStatus::Completed)
        ->and($run->scheduled_for->equalTo(CarbonImmutable::parse('2026-10-21 17:00', 'America/Bogota')))->toBeTrue()
        ->and($run->cutoff_date?->format('Y-m-d'))->toBe('2026-10-20')
        ->and($run->triggered_by)->toBeNull()
        ->and(RunItem::query()->pluck('client_id')->all())->toEqualCanonicalizing([
            $this->portfolio->tornillo->summary->id,
            $this->portfolio->andinas->summary->id,
            $this->portfolio->excluido->summary->id,
        ]);

    $tornillo = cobroItem($this->portfolio->tornillo->summary->id);

    expect($tornillo->status)->toBe(ItemStatus::Sent)
        ->and($tornillo->recipients)->toBe(['tesoreria@tornillo.example', 'pagos@tornillo.example'])
        ->and($tornillo->amount)->toBe('3500000.00')
        ->and($tornillo->invoice_count)->toBe(2)
        ->and($tornillo->internet_message_id)->toBe('<fake-1@gestor-cartera.test>')
        ->and($tornillo->sent_at?->equalTo(CarbonImmutable::now()))->toBeTrue()
        ->and(cobroItem($this->portfolio->andinas->summary->id)->status)->toBe(ItemStatus::NoEmail)
        ->and(cobroItem($this->portfolio->excluido->summary->id)->status)->toBe(ItemStatus::Excluded)
        ->and(cobroItem($this->portfolio->excluido->summary->id)->exclusion_reason)->toBe('Acuerdo de pago vigente');
});

it('mails the overdue invoices only, from the company mailbox, linked to the client', function () {
    $this->artisan('cobro:cobro-semanal')->assertSuccessful();

    $this->fakes->mail->assertSent(fn (OutgoingMail $mail): bool => $mail->company === CompanyCode::SGX
        && $mail->to === ['tesoreria@tornillo.example', 'pagos@tornillo.example']
        && $mail->subject === 'Facturas vencidas de Ferretería El Tornillo SAS con Segurex Latam'
        && $mail->clientId === $this->portfolio->tornillo->summary->id
        && $mail->category === 'cobro_semanal', times: 1);

    $html = $this->fakes->mail->sent()[0]->html;

    expect($html)->toContain('FV-1188')->toContain('FV-1201')->not->toContain('FV-1250')
        ->and(count($this->fakes->mail->sent()))->toBe(1);
});

it('logs the sent mail in the client activity', function () {
    $this->artisan('cobro:cobro-semanal')->assertSuccessful();

    $this->fakes->gestiones->assertLogged(ActivityType::EmailSent, $this->portfolio->tornillo->summary->id);

    expect($this->fakes->gestiones->logged()[0]->summary)->toBe('Correo de cobro semanal enviado a tesoreria@tornillo.example, pagos@tornillo.example');
});

it('never runs twice for the same day', function () {
    $this->artisan('cobro:cobro-semanal')->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-10-21 18:30:00', 'America/Bogota'));

    $this->artisan('cobro:cobro-semanal')
        ->expectsOutputToContain('Segurex Latam: el envío de Cobro semanal de esta fecha ya se preparó; no se envía de nuevo.')
        ->assertSuccessful();

    expect(Run::query()->count())->toBe(1)
        ->and($this->fakes->mail->sent())->toHaveCount(1);
});

it('records a failed send and still closes the run', function () {
    $this->fakes->mail->failNextWith(new MailNotSent('Graph did not send the cobro_semanal mail: HTTP 429.'));

    $this->artisan('cobro:cobro-semanal')->assertSuccessful();

    $item = cobroItem($this->portfolio->tornillo->summary->id);

    expect($item->status)->toBe(ItemStatus::Failed)
        ->and($item->error)->toBe('Graph did not send the cobro_semanal mail: HTTP 429.')
        ->and(Run::query()->sole()->status)->toBe(RunStatus::Completed);

    $this->fakes->gestiones->assertNothingLogged();
});

it('skips a client with nothing overdue at send time', function () {
    $run = Run::query()->create([
        'company' => CompanyCode::SGX, 'kind' => RunKind::Weekly, 'status' => RunStatus::Sending,
        'scheduled_for' => now(), 'started_at' => now(),
    ]);
    $item = RunItem::query()->create([
        'run_id' => $run->id, 'client_id' => $this->portfolio->alDia->summary->id, 'client_code' => 'C1',
        'client_name' => 'Comercial Al Día SAS', 'recipients' => ['a@b.example'], 'amount' => '1.00',
        'invoice_count' => 1, 'status' => ItemStatus::Pending,
    ]);

    SendRunItem::dispatch($item->id);

    expect($item->refresh()->status)->toBe(ItemStatus::Skipped)
        ->and($run->refresh()->status)->toBe(RunStatus::Completed);
    $this->fakes->mail->assertNothingSent();
});

it('never mails an item that is not pending', function () {
    $run = Run::query()->create([
        'company' => CompanyCode::SGX, 'kind' => RunKind::Weekly, 'status' => RunStatus::Completed,
        'scheduled_for' => now(), 'started_at' => now(),
    ]);
    $item = RunItem::query()->create([
        'run_id' => $run->id, 'client_id' => $this->portfolio->tornillo->summary->id, 'client_code' => 'C1',
        'client_name' => 'Ferretería', 'recipients' => ['a@b.example'], 'amount' => '1.00',
        'invoice_count' => 1, 'status' => ItemStatus::Sent,
    ]);

    SendRunItem::dispatch($item->id);

    expect($item->refresh()->status)->toBe(ItemStatus::Sent);
    $this->fakes->mail->assertNothingSent();
});

it('marks the item bounced when the mailbox reads its bounce', function () {
    $this->artisan('cobro:cobro-semanal')->assertSuccessful();

    $sent = SentMessage::query()->create([
        'company' => CompanyCode::SGX, 'mailbox' => 'segurex.info@segurex.com', 'client_id' => $this->portfolio->tornillo->summary->id,
        'category' => 'cobro_semanal', 'subject' => 'Facturas', 'recipients' => ['tesoreria@tornillo.example'],
        'internet_message_id' => '<fake-1@gestor-cartera.test>', 'sent_at' => now(),
    ]);
    $bounce = InboundMessage::query()->create([
        'company' => CompanyCode::SGX, 'mailbox' => 'segurex.info@segurex.com', 'graph_id' => 'ndr-1',
        'subject' => 'Undeliverable: Facturas', 'preview' => '', 'received_at' => now()->addMinute(),
        'is_bounce' => true, 'bounce_reason' => '550 5.1.1 User unknown', 'bounced_recipient' => 'pagos@tornillo.example',
        'sent_message_id' => $sent->id, 'client_id' => $this->portfolio->tornillo->summary->id,
    ]);

    event(new BounceLinked($bounce));

    $item = cobroItem($this->portfolio->tornillo->summary->id);

    expect($item->status)->toBe(ItemStatus::Bounced)
        ->and($item->bounce_reason)->toBe('550 5.1.1 User unknown')
        ->and($item->bounced_recipient)->toBe('pagos@tornillo.example')
        ->and($item->bounced_at?->equalTo(now()->addMinute()))->toBeTrue();
});

it('runs only for the companies of the weekly collection', function () {
    $this->artisan('cobro:cobro-semanal --empresa=SDC')
        ->expectsOutputToContain('La empresa SDC no tiene este envío. Empresas válidas: SGX.')
        ->assertFailed();

    config(['cobro.weekly.companies' => ['SGX', 'SDC']]);

    $this->artisan('cobro:cobro-semanal --empresa=sdc')->assertSuccessful();

    expect(Run::query()->sole()->company)->toBe(CompanyCode::SDC);
    $this->fakes->mail->assertSentTo('pagos@quimicos.example');
});

it('is scheduled on Wednesdays at 17:00, Bogotá time', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'cobro:cobro-semanal'));

    expect($event)->toBeInstanceOf(ScheduledEvent::class)
        ->and($event->expression)->toBe('0 17 * * 3')
        ->and($event->timezone)->toBe('America/Bogota')
        ->and($event->withoutOverlapping)->toBeTrue();
});
