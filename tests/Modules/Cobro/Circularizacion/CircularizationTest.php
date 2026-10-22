<?php

use App\Modules\Cobro\Database\Seeders\CobroPermissionSeeder;
use App\Modules\Cobro\Domain\Copies\CopySettings;
use App\Modules\Cobro\Domain\Runs\ItemStatus;
use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Cobro\Domain\Runs\RunStatus;
use App\Modules\Cobro\Infrastructure\Copies\CopyStore;
use App\Modules\Cobro\Infrastructure\Persistence\Run;
use App\Modules\Cobro\Infrastructure\Persistence\RunItem;
use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\Make;
use Tests\Modules\Cobro\Support\CobroPortfolio;
use Tests\Modules\Cobro\Support\CobroUsers;
use Tests\Modules\Cobro\Support\VisibleText;

/*
 * The monthly circularization run: the 1st at 09:00 (Bogotá) the latest
 * snapshot is the one of the closed month (cutoff 2026-10-31).
 */

beforeEach(function () {
    $this->seed(CobroPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->portfolio = CobroPortfolio::seed($this->fakes);
    $this->fakes->cartera->cutoffDate = CarbonImmutable::parse('2026-10-31', 'America/Bogota');
});

function cobroCircularizationItem(int $clientId): RunItem
{
    return RunItem::query()->where('client_id', $clientId)->sole();
}

/**
 * The circularization mail the fake sender got for $clientId.
 */
function cobroCircularizationMail(int $clientId): OutgoingMail
{
    return collect(test()->fakes->mail->sent())->sole(fn (OutgoingMail $mail): bool => $mail->clientId === $clientId);
}

it('sends on schedule for Segurex and Derivados without any flag', function () {
    $this->artisan('cobro:circularizacion')
        ->expectsOutputToContain('Segurex Latam: Circularización en cola para 2 clientes; 1 sin correo y 1 excluidos.')
        ->expectsOutputToContain('Soluciones y Derivados: Circularización en cola para 1 clientes; 0 sin correo y 0 excluidos.')
        ->assertSuccessful();

    $runs = Run::query()->orderBy('id')->get();

    expect($runs->pluck('company')->all())->toBe([CompanyCode::SGX, CompanyCode::SDC])
        ->and($runs->every(fn (Run $run): bool => $run->kind === RunKind::Circularization && $run->status === RunStatus::Completed))->toBeTrue()
        ->and($runs->every(fn (Run $run): bool => $run->scheduled_for->equalTo(CarbonImmutable::parse('2026-11-01 09:00', 'America/Bogota'))))->toBeTrue()
        ->and(cobroCircularizationItem($this->portfolio->tornillo->summary->id)->amount)->toBe('4500000.00')
        ->and(cobroCircularizationItem($this->portfolio->tornillo->summary->id)->invoice_count)->toBe(3)
        ->and(cobroCircularizationItem($this->portfolio->alDia->summary->id)->status)->toBe(ItemStatus::Sent)
        ->and(cobroCircularizationItem($this->portfolio->quimicos->summary->id)->status)->toBe(ItemStatus::Sent);
});

it('mails every client with a balance in its company format, at the close of the month', function () {
    $this->artisan('cobro:circularizacion')->assertSuccessful();

    $tornillo = cobroCircularizationMail($this->portfolio->tornillo->summary->id);
    $quimicos = cobroCircularizationMail($this->portfolio->quimicos->summary->id);

    expect($tornillo->to)->toBe(['tesoreria@tornillo.example', 'pagos@tornillo.example'])
        ->and($tornillo->subject)->toBe('Circularización de Cartera – Corte al 31 de octubre de 2026')
        ->and($tornillo->category)->toBe('circularizacion')
        ->and($tornillo->company)->toBe(CompanyCode::SGX)
        ->and(VisibleText::of($tornillo->html))->toContain('FV-1188')->toContain('FV-1201')->toContain('FV-1250')
        ->toContain('Estimado cliente Ferretería El Tornillo SAS')
        ->toContain('AL CIERRE DEL 31 DE OCTUBRE')
        ->toContain('AVISO LEGAL:')
        ->and($quimicos->to)->toBe(['pagos@quimicos.example'])
        ->and($quimicos->subject)->toBe('Circularización de Cartera – Corte al 31 de octubre del 2026')
        ->and($quimicos->company)->toBe(CompanyCode::SDC)
        ->and($quimicos->html)->toContain('Estimado cliente <strong>Químicos del Norte SAS</strong>')
        ->not->toContain('ACLARAMOS')
        ->not->toContain('AVISO LEGAL');

    foreach ($this->fakes->mail->sent() as $mail) {
        expect($mail->subject)->not->toContain('[Borrador]')
            ->and($mail->html)->not->toContain('data-banner="borrador"');
    }
});

it('mails neither excluded clients nor clients without an e-mail', function () {
    $this->artisan('cobro:circularizacion')->assertSuccessful();

    $mailed = collect($this->fakes->mail->sent())->map(fn (OutgoingMail $mail): ?int => $mail->clientId)->sort()->values()->all();

    expect($mailed)->toBe(collect([
        $this->portfolio->tornillo->summary->id,
        $this->portfolio->alDia->summary->id,
        $this->portfolio->quimicos->summary->id,
    ])->sort()->values()->all())
        ->and(cobroCircularizationItem($this->portfolio->andinas->summary->id)->status)->toBe(ItemStatus::NoEmail)
        ->and(cobroCircularizationItem($this->portfolio->excluido->summary->id)->status)->toBe(ItemStatus::Excluded);
});

it('lists credit notes so the total is what the client owes', function () {
    $client = Make::clientDetail(
        Make::client(name: 'Cliente Con Nota Crédito SAS', balance: 700_000),
        contacts: [Make::contact(email: 'pagos@nota.example', isPrimary: true)],
    );
    $id = $client->summary->id;
    $this->fakes->cartera->addClient(
        $client,
        Make::invoice(clientId: $id, documentNumber: 'FV-5001', amount: 1_000_000, daysOverdue: 3),
        Make::invoice(clientId: $id, documentNumber: 'NC-77', amount: -300_000, daysOverdue: 40),
    );

    $this->artisan('cobro:circularizacion --empresa=SGX')->assertSuccessful();

    expect(cobroCircularizationItem($id)->amount)->toBe('700000.00')
        ->and(cobroCircularizationItem($id)->invoice_count)->toBe(2)
        ->and(VisibleText::of(cobroCircularizationMail($id)->html))->toContain('FV-5001')->toContain('NC-77')->toContain('$ 700.000');
});

it('copies the weekly collection copies of each company by default, never the To addresses', function () {
    config(['cobro.copias.SGX.fixed' => ['miguel.martinez@segurex.com', 'PAGOS@tornillo.example']]);

    $this->artisan('cobro:circularizacion')->assertSuccessful();

    expect(cobroCircularizationMail($this->portfolio->tornillo->summary->id)->cc)->toBe(['miguel.martinez@segurex.com'])
        ->and(cobroCircularizationMail($this->portfolio->quimicos->summary->id)->cc)
        ->toBe(['miguel.martinez@derivadoscol.com', 'diana.mora@derivadoscol.com', 'servicioalcliente@derivadoscol.com']);
});

it('copies what each company saved in the copies screen, the advisor and the group manager included', function () {
    $advisor = Make::advisor(code: 41, name: 'JENNY ROCIO BUITRAGO IBAÑEZ', email: 'Jenny.Buitrago@segurex.com');
    $distribuidor = Make::clientDetail(
        Make::client(name: 'Distribuidora Central SAS', balance: 900_000, advisor: $advisor),
        group: 'Distribución',
        contacts: [Make::contact(email: 'pagos@central.example', isPrimary: true)],
    );
    $this->fakes->cartera->addAdvisor($advisor)->addClient(
        $distribuidor,
        Make::invoice(clientId: $distribuidor->summary->id, documentNumber: 'FV-2001', amount: 900_000, daysOverdue: -20),
    );
    app(CopyStore::class)->save(CompanyCode::SGX, new CopySettings(
        fixed: ['cartera@segurex.com'],
        advisor: true,
        managers: [['group' => 'distribucion', 'emails' => ['nueva.gerente@segurex.com']]],
    ), null);
    app(CopyStore::class)->save(CompanyCode::SDC, new CopySettings(fixed: ['cartera@derivadoscol.com'], advisor: false, managers: []), null);

    $this->artisan('cobro:circularizacion')->assertSuccessful();

    expect(cobroCircularizationMail($distribuidor->summary->id)->cc)->toBe(['cartera@segurex.com', 'Jenny.Buitrago@segurex.com', 'nueva.gerente@segurex.com'])
        ->and(cobroCircularizationMail($this->portfolio->tornillo->summary->id)->cc)->toBe(['cartera@segurex.com'])
        ->and(cobroCircularizationMail($this->portfolio->quimicos->summary->id)->cc)->toBe(['cartera@derivadoscol.com']);
});

it('runs once per month', function () {
    $this->artisan('cobro:circularizacion')->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00:00', 'America/Bogota'));

    $this->artisan('cobro:circularizacion')
        ->expectsOutputToContain('ya se preparó')
        ->assertSuccessful();

    expect(Run::query()->count())->toBe(2)
        ->and($this->fakes->mail->sent())->toHaveCount(3);
});

it('still holds the scheduled run back if the draft flag is turned on again', function () {
    config(['cobro.circularizacion.draft' => true]);

    $this->artisan('cobro:circularizacion')
        ->expectsOutputToContain('Circularización: el envío está en borrador, no se envía nada.')
        ->assertSuccessful();

    expect(Run::query()->count())->toBe(0);
    $this->fakes->mail->assertNothingSent();
});

it('previews the next circularization with its copies and no draft notice', function () {
    $analyst = CobroUsers::create(Role::PortfolioAnalyst);

    $this->actingAs($analyst)->get('/cobro/circularizacion')->assertOk()
        ->assertSeeText('Circularización')
        ->assertSeeText('en el formato de cada empresa')
        ->assertDontSeeText('Envío en borrador')
        ->assertDontSeeText('Pausado mientras el envío esté en borrador')
        ->assertDontSeeText('provisional')
        ->assertSeeText('Comercial Al Día SAS')
        ->assertSeeText('Con copia a: miguel.martinez@segurex.com');

    $html = (string) $this->actingAs($analyst)
        ->get('/cobro/circularizacion/SGX/correo/'.$this->portfolio->tornillo->summary->id)
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('data-banner="borrador"')
        ->toContain('Corte al 31 de octubre de 2026')
        ->toContain('Cod.Cliente')
        ->toContain('FV-1250');
});

it('sends a test to the user only, without copies', function () {
    $analyst = CobroUsers::create(Role::PortfolioAnalyst, email: 'maria.rojas@segurex.com');

    $this->actingAs($analyst)
        ->post('/cobro/circularizacion/SGX/prueba', ['cliente' => $this->portfolio->tornillo->summary->id])
        ->assertSessionHas('status', 'Te enviamos la prueba a maria.rojas@segurex.com.');

    $this->fakes->mail->assertSent(fn (OutgoingMail $mail): bool => $mail->subject === '[Prueba] Circularización de Cartera – Corte al 31 de octubre de 2026'
        && $mail->to === ['maria.rojas@segurex.com']
        && $mail->cc === [], times: 1);
});

it('is scheduled monthly at close, Bogotá time', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'cobro:circularizacion'));

    expect($event)->toBeInstanceOf(ScheduledEvent::class)
        ->and($event->expression)->toBe('0 9 1 * *')
        ->and($event->timezone)->toBe('America/Bogota');
});
