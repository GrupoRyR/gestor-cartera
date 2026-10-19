<?php

use App\Modules\Cobro\Database\Seeders\CobroPermissionSeeder;
use App\Modules\Cobro\Domain\Runs\ItemStatus;
use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Cobro\Domain\Runs\RunStatus;
use App\Modules\Cobro\Infrastructure\Persistence\Run;
use App\Modules\Cobro\Infrastructure\Persistence\RunItem;
use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Cobro\Support\CobroPortfolio;
use Tests\Modules\Cobro\Support\CobroUsers;
use Tests\Modules\Cobro\Support\VisibleText;

beforeEach(function () {
    $this->seed(CobroPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->portfolio = CobroPortfolio::seed($this->fakes);
});

function cobroCircularizationItem(int $clientId): RunItem
{
    return RunItem::query()->where('client_id', $clientId)->sole();
}

it('sends nothing on schedule while the format is a draft', function () {
    $this->artisan('cobro:circularizacion')
        ->expectsOutputToContain('Circularización: el formato aún es un borrador, no se envía nada.')
        ->assertSuccessful();

    expect(Run::query()->count())->toBe(0);
    $this->fakes->mail->assertNothingSent();
});

it('runs the engine on demand: every client with a balance, excluded and without e-mail marked', function () {
    $this->artisan('cobro:circularizacion --aun-en-borrador')
        ->expectsOutputToContain('Segurex Latam: Circularización en cola para 2 clientes; 1 sin correo y 1 excluidos.')
        ->assertSuccessful();

    $run = Run::query()->sole();

    expect($run->kind)->toBe(RunKind::Circularization)
        ->and($run->status)->toBe(RunStatus::Completed)
        ->and($run->scheduled_for->equalTo(CarbonImmutable::parse('2026-11-01 09:00', 'America/Bogota')))->toBeTrue()
        ->and(cobroCircularizationItem($this->portfolio->tornillo->summary->id)->amount)->toBe('4500000.00')
        ->and(cobroCircularizationItem($this->portfolio->tornillo->summary->id)->invoice_count)->toBe(3)
        ->and(cobroCircularizationItem($this->portfolio->alDia->summary->id)->status)->toBe(ItemStatus::Sent)
        ->and(cobroCircularizationItem($this->portfolio->andinas->summary->id)->status)->toBe(ItemStatus::NoEmail)
        ->and(cobroCircularizationItem($this->portfolio->excluido->summary->id)->status)->toBe(ItemStatus::Excluded);
});

it('mails every open invoice, marked as a draft', function () {
    $this->artisan('cobro:circularizacion --aun-en-borrador')->assertSuccessful();

    $this->fakes->mail->assertSent(fn (OutgoingMail $mail): bool => $mail->to === ['tesoreria@tornillo.example', 'pagos@tornillo.example']
        && $mail->subject === '[Borrador] Estado de cartera de Ferretería El Tornillo SAS con Segurex Latam'
        && $mail->category === 'circularizacion'
        && $mail->clientId === $this->portfolio->tornillo->summary->id, times: 1);

    $mail = collect($this->fakes->mail->sent())->first(fn (OutgoingMail $mail): bool => $mail->clientId === $this->portfolio->tornillo->summary->id);
    $text = VisibleText::of($mail->html);

    expect($mail->html)->toContain('data-banner="borrador"')
        ->and($text)->toContain('FV-1188')->toContain('FV-1201')->toContain('FV-1250')
        ->toContain('Por vencer $ 1.000.000')
        ->toContain('Vencido $ 3.500.000')
        ->toContain('Saldo total $ 4.500.000');
});

it('runs once per month', function () {
    $this->artisan('cobro:circularizacion --aun-en-borrador')->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00:00', 'America/Bogota'));

    $this->artisan('cobro:circularizacion --aun-en-borrador')
        ->expectsOutputToContain('ya se preparó')
        ->assertSuccessful();

    expect(Run::query()->count())->toBe(1);
});

it('sends on schedule once the draft flag is off', function () {
    config(['cobro.circularizacion.draft' => false]);

    $this->artisan('cobro:circularizacion')->assertSuccessful();

    expect(Run::query()->count())->toBe(1);
    $this->fakes->mail->assertSent(fn (OutgoingMail $mail): bool => ! str_starts_with($mail->subject, '[Borrador]'));
});

it('previews the circularization and its pause', function () {
    $analyst = CobroUsers::create(Role::PortfolioAnalyst);

    $this->actingAs($analyst)->get('/cobro/circularizacion')->assertOk()
        ->assertSeeText('Circularización')
        ->assertSeeText('Formato en borrador')
        ->assertSeeText('Pausado mientras el formato sea un borrador')
        ->assertSeeText('Comercial Al Día SAS');

    $html = (string) $this->actingAs($analyst)
        ->get('/cobro/circularizacion/SGX/correo/'.$this->portfolio->tornillo->summary->id)
        ->assertOk()
        ->getContent();

    expect($html)->toContain('data-banner="borrador"')->toContain('FV-1250');
});

it('sends a draft test to the user', function () {
    $analyst = CobroUsers::create(Role::PortfolioAnalyst, email: 'maria.rojas@segurex.com');

    $this->actingAs($analyst)
        ->post('/cobro/circularizacion/SGX/prueba', ['cliente' => $this->portfolio->tornillo->summary->id])
        ->assertSessionHas('status', 'Te enviamos la prueba a maria.rojas@segurex.com.');

    $this->fakes->mail->assertSent(fn (OutgoingMail $mail): bool => $mail->subject === '[Prueba] [Borrador] Estado de cartera de Ferretería El Tornillo SAS con Segurex Latam'
        && $mail->to === ['maria.rojas@segurex.com'], times: 1);
});

it('is scheduled monthly at close, Bogotá time', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'cobro:circularizacion'));

    expect($event)->toBeInstanceOf(ScheduledEvent::class)
        ->and($event->expression)->toBe('0 9 1 * *')
        ->and($event->timezone)->toBe('America/Bogota');
});

it('leaves SDC out until the circularization applies to it', function () {
    $this->artisan('cobro:circularizacion --aun-en-borrador --empresa=SDC')->assertFailed();

    expect(Run::query()->where('company', CompanyCode::SDC)->count())->toBe(0);
});
