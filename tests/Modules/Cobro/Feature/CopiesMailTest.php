<?php

use App\Modules\Cobro\Database\Seeders\CobroPermissionSeeder;
use App\Modules\Cobro\Domain\Copies\CopySettings;
use App\Modules\Cobro\Domain\Mail\BounceDetector;
use App\Modules\Cobro\Domain\Runs\ItemStatus;
use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Cobro\Domain\Runs\RunStatus;
use App\Modules\Cobro\Infrastructure\Copies\CopyStore;
use App\Modules\Cobro\Infrastructure\Mail\BounceLinked;
use App\Modules\Cobro\Infrastructure\Persistence\InboundMessage;
use App\Modules\Cobro\Infrastructure\Persistence\Run;
use App\Modules\Cobro\Infrastructure\Persistence\RunItem;
use App\Modules\Cobro\Infrastructure\Persistence\SentMessage;
use App\Modules\Cobro\Infrastructure\Runs\RunDefinitions;
use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\Make;
use Tests\Modules\Cobro\Support\CobroPortfolio;
use Tests\Modules\Cobro\Support\CobroUsers;
use Tests\Modules\Cobro\Support\GraphFake;

beforeEach(function () {
    $this->seed(CobroPermissionSeeder::class);
    // Wednesday, the scheduled moment.
    $this->travelTo(CarbonImmutable::parse('2026-10-21 17:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->portfolio = CobroPortfolio::seed($this->fakes);

    // A client of the DISTRIBUCION group (written with an accent) with an
    // advisor; its contact is also a fixed copy, and one of its group
    // managers is too.
    $advisor = Make::advisor(code: 41, name: 'JENNY ROCIO BUITRAGO IBAÑEZ', email: 'Jenny.Buitrago@segurex.com');
    $this->distribuidor = Make::clientDetail(
        Make::client(name: 'Distribuidora Central SAS', balance: 900_000, overdue: 900_000, maxDaysOverdue: 20, advisor: $advisor),
        group: 'Distribución',
        contacts: [Make::contact(email: 'pagos@central.example', isPrimary: true)],
    );
    $this->fakes->cartera->addAdvisor($advisor)->addClient(
        $this->distribuidor,
        Make::invoice(clientId: $this->distribuidor->summary->id, documentNumber: 'FV-2001', amount: 900_000, daysOverdue: 20),
    );

    config(['cobro.copias.SGX' => [
        'fixed' => ['miguel.martinez@segurex.com', 'PAGOS@central.example'],
        'advisor' => true,
        'managers' => [
            ['group' => 'DISTRIBUCION', 'emails' => ['jessika.quintero@segurex.com', 'Miguel.Martinez@segurex.com']],
            ['group' => 'CONS. RESIDENCIAL', 'emails' => ['andrea.hincapie@segurex.com']],
        ],
    ]]);
});

/**
 * The mail the fake sender got for $clientId.
 */
function cobroMailFor(int $clientId): OutgoingMail
{
    return collect(test()->fakes->mail->sent())->sole(fn (OutgoingMail $mail): bool => $mail->clientId === $clientId);
}

it('copies the fixed addresses, the advisor and the group manager, without duplicates or the To addresses', function () {
    $this->artisan('cobro:cobro-semanal')->assertSuccessful();

    expect(cobroMailFor($this->distribuidor->summary->id)->cc)
        ->toBe(['miguel.martinez@segurex.com', 'Jenny.Buitrago@segurex.com', 'jessika.quintero@segurex.com'])
        // Ferretería El Tornillo has no advisor and its group has no manager.
        ->and(cobroMailFor($this->portfolio->tornillo->summary->id)->cc)->toBe(['miguel.martinez@segurex.com', 'PAGOS@central.example']);
});

it('copies only the SDC fixed addresses of the configuration', function () {
    config(['cobro.weekly.companies' => ['SGX', 'SDC']]);

    $this->artisan('cobro:cobro-semanal --empresa=SDC')->assertSuccessful();

    expect(cobroMailFor($this->portfolio->quimicos->summary->id)->cc)
        ->toBe(['miguel.martinez@derivadoscol.com', 'diana.mora@derivadoscol.com', 'servicioalcliente@derivadoscol.com'])
        ->and(config('cobro.copias.SDC.advisor'))->toBeFalse()
        ->and(config('cobro.copias.SDC.managers'))->toBe([]);
});

it('never copies an advisor SAP lists as inactive', function () {
    $inactive = Make::advisor(code: 52, name: 'CARLOS PEREZ', email: 'carlos.perez@segurex.com', active: false);
    $client = Make::clientDetail(
        Make::client(name: 'Cliente De Asesor Retirado SAS', balance: 300_000, overdue: 300_000, maxDaysOverdue: 8, advisor: $inactive),
        group: null,
        contacts: [Make::contact(email: 'pagos@retirado.example', isPrimary: true)],
    );
    $this->fakes->cartera->addAdvisor($inactive)->addClient(
        $client,
        Make::invoice(clientId: $client->summary->id, documentNumber: 'FV-3001', amount: 300_000, daysOverdue: 8),
    );

    expect(app(RunDefinitions::class)->for(RunKind::Weekly)->notice($client->summary->id)?->advisorEmail)->toBeNull();

    $this->actingAs(CobroUsers::create(Role::PortfolioAnalyst))->get('/cobro/semanal')->assertOk()
        ->assertDontSeeText('carlos.perez@segurex.com');

    $this->artisan('cobro:cobro-semanal')->assertSuccessful();

    expect(cobroMailFor($client->summary->id)->cc)->toBe(['miguel.martinez@segurex.com', 'PAGOS@central.example']);
});

it('copies what the company saved instead of the configuration', function () {
    app(CopyStore::class)->save(CompanyCode::SGX, new CopySettings(
        fixed: ['cartera@segurex.com'],
        advisor: false,
        managers: [['group' => 'distribucion', 'emails' => ['nueva.gerente@segurex.com']]],
    ), null);

    $this->artisan('cobro:cobro-semanal')->assertSuccessful();

    expect(cobroMailFor($this->distribuidor->summary->id)->cc)->toBe(['cartera@segurex.com', 'nueva.gerente@segurex.com']);
});

it('copies nobody on the test send', function () {
    $analyst = CobroUsers::create(Role::PortfolioAnalyst, email: 'maria.rojas@segurex.com');

    $this->actingAs($analyst)->post('/cobro/semanal/SGX/prueba', ['cliente' => $this->distribuidor->summary->id])
        ->assertSessionHas('status');

    $this->fakes->mail->assertSent(fn (OutgoingMail $mail): bool => $mail->to === ['maria.rojas@segurex.com'] && $mail->cc === [], times: 1);
});

it('copies the same recipients on the circularization', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', 'America/Bogota'));
    // The circularization waits for the cartera of the month close.
    $this->fakes->cartera->cutoffDate = CarbonImmutable::parse('2026-10-31', 'America/Bogota');

    $this->artisan('cobro:circularizacion --empresa=SGX')->assertSuccessful();

    expect(cobroMailFor($this->distribuidor->summary->id)->cc)
        ->toBe(['miguel.martinez@segurex.com', 'Jenny.Buitrago@segurex.com', 'jessika.quintero@segurex.com']);
});

it('shows the copies of each client in the weekly and the circularization previews', function () {
    $analyst = CobroUsers::create(Role::PortfolioAnalyst);

    $this->actingAs($analyst)->get('/cobro/semanal')->assertOk()
        ->assertSeeText('Con copia a: miguel.martinez@segurex.com, Jenny.Buitrago@segurex.com, jessika.quintero@segurex.com');

    $this->actingAs($analyst)->get('/cobro/circularizacion')->assertOk()
        ->assertSeeText('Con copia a: miguel.martinez@segurex.com, Jenny.Buitrago@segurex.com, jessika.quintero@segurex.com');
});

/**
 * A sent weekly mail of Distribuciones Andinas, to $to with $cc, as the
 * recorded bounce in inbox-delta-page-1.json answers it (its failed
 * address is pagos@andinas-inexistente.example), and its run item.
 *
 * @param  list<string>  $to
 * @param  list<string>  $cc
 */
function cobroAndinasSend(array $to, array $cc): RunItem
{
    $clientId = test()->portfolio->andinas->summary->id;
    $sent = SentMessage::query()->create([
        'company' => CompanyCode::SGX,
        'mailbox' => 'segurex.info@segurex.com',
        'client_id' => $clientId,
        'category' => 'cobro_semanal',
        'subject' => 'Facturas vencidas de Distribuciones Andinas SAS',
        'recipients' => [...$to, ...$cc],
        'internet_message_id' => '<SA1PR11MB8471ANDINAS0002@SA1PR11MB8471.namprd11.prod.outlook.com>',
        'conversation_id' => 'AAQkAGconversacion-original-andinas=',
        'sent_at' => CarbonImmutable::parse('2026-10-21 17:00:05', 'America/Bogota'),
    ]);
    $run = Run::query()->create([
        'company' => CompanyCode::SGX, 'kind' => RunKind::Weekly, 'status' => RunStatus::Completed,
        'scheduled_for' => now(), 'started_at' => now(),
    ]);

    return RunItem::query()->create([
        'run_id' => $run->id, 'client_id' => $clientId, 'client_code' => 'C1', 'client_name' => 'Distribuciones Andinas SAS',
        'recipients' => $to, 'amount' => '800000.00', 'invoice_count' => 1, 'status' => ItemStatus::Sent,
        'sent_message_id' => $sent->id, 'internet_message_id' => $sent->internet_message_id, 'sent_at' => $sent->sent_at,
    ]);
}

/**
 * Reads the two recorded delta pages of the SGX inbox.
 */
function cobroSyncRecordedInbox(): void
{
    $delta = 'users/segurex.info@segurex.com/mailFolders/inbox/messages/delta';
    GraphFake::install()->onEach(
        'GET',
        $delta,
        [GraphFake::fixture('inbox-delta-page-1.json'), 200],
        [GraphFake::fixture('inbox-delta-page-2.json'), 200],
        [['value' => [], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/'.$delta.'?$deltatoken=siguiente'], 200],
    );
    config(['cobro.mailboxes.SDC' => '']);

    test()->artisan('cobro:sincronizar-buzones')->assertSuccessful();
}

it('marks the item bounced when one of its To addresses bounced', function () {
    $item = cobroAndinasSend(['tesoreria@andinas.example', 'Pagos@Andinas-Inexistente.example'], ['miguel.martinez@segurex.com']);

    cobroSyncRecordedInbox();

    expect($item->refresh()->status)->toBe(ItemStatus::Bounced)
        ->and($item->bounced_recipient)->toBe('pagos@andinas-inexistente.example');
});

it('keeps the item sent when only a carbon copy bounced', function () {
    Log::spy();
    $item = cobroAndinasSend(['tesoreria@andinas.example'], ['pagos@andinas-inexistente.example']);

    cobroSyncRecordedInbox();

    $bounce = InboundMessage::query()->where('is_bounce', true)->sole();

    expect($bounce->bounced_recipient)->toBe('pagos@andinas-inexistente.example')
        ->and($bounce->sent_message_id)->toBe($item->sent_message_id)
        ->and($item->refresh()->status)->toBe(ItemStatus::Sent)
        ->and($item->bounced_recipient)->toBeNull();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'carbon copy')
        && $context === ['run_item' => $item->id, 'address' => 'pagos@andinas-inexistente.example'])->once();
});

it('marks the item bounced when the report names a copy before the To address that failed', function () {
    $item = cobroAndinasSend(['pagos@andinas-inexistente.example'], ['miguel.martinez@segurex.com']);
    $sent = SentMessage::query()->findOrFail($item->sent_message_id);
    $body = "Delivery has failed to these recipients or groups:\r\n"
        ."miguel.martinez@segurex.com, pagos@andinas-inexistente.example\r\n"
        ."Remote server returned '550 5.1.10 RESOLVER.ADR.RecipientNotFound'";
    $read = app(BounceDetector::class)->read($body, recipients: $sent->recipients, ignore: [$sent->mailbox]);

    $bounce = InboundMessage::query()->create([
        'company' => CompanyCode::SGX, 'mailbox' => $sent->mailbox, 'graph_id' => 'ndr-cc-first',
        'subject' => 'Undeliverable: '.$sent->subject, 'preview' => '', 'received_at' => now()->addMinute(),
        'is_bounce' => true, 'bounce_reason' => $read->reason, 'bounced_recipient' => $read->recipient,
        'sent_message_id' => $sent->id, 'client_id' => $item->client_id,
    ]);
    event(new BounceLinked($bounce));

    expect($read->recipient)->toBe('pagos@andinas-inexistente.example')
        ->and($item->refresh()->status)->toBe(ItemStatus::Bounced)
        ->and($item->bounced_recipient)->toBe('pagos@andinas-inexistente.example');
});
