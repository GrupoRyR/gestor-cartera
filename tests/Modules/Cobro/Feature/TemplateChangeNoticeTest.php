<?php

use App\Modules\Cobro\Database\Seeders\CobroPermissionSeeder;
use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Cobro\Domain\Templates\TemplateContent;
use App\Modules\Cobro\Infrastructure\Persistence\Template;
use App\Modules\Cobro\Infrastructure\Templates\TemplateStore;
use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Contracts\Exceptions\MailNotSent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Cobro\Support\CobroUsers;
use Tests\Modules\Cobro\Support\VisibleText;

beforeEach(function () {
    $this->seed(CobroPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-21 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->analyst = CobroUsers::create(Role::PortfolioAnalyst, email: 'maria.rojas@segurex.com');
    config(['cobro.template_alert_recipients' => ['ti@segurex.com', 'control@segurex.com']]);

    // The texts the editor would send back: the defaults, with $changes.
    $this->form = function (CompanyCode $company, array $changes = []): array {
        $default = TemplateStore::default(RunKind::Weekly, $company);

        return [...['asunto' => $default->subject, 'inicio' => $default->intro, 'cierre' => $default->closing], ...$changes];
    };
});

it('mails the old and new text of the changed field to the alert recipients', function () {
    $this->actingAs($this->analyst)
        ->put('/cobro/plantillas/SDC', ($this->form)(CompanyCode::SDC, ['asunto' => 'Pago pendiente de {cliente}']))
        ->assertRedirect('/cobro/plantillas?empresa=SDC');

    $this->fakes->mail->assertSent(fn (OutgoingMail $mail): bool => $mail->company === CompanyCode::SDC
        && $mail->to === ['ti@segurex.com', 'control@segurex.com']
        && $mail->category === 'alerta'
        && $mail->subject === 'Cambió la plantilla del cobro semanal de Soluciones y Derivados', times: 1);

    $mail = $this->fakes->mail->sent()[0];
    $text = VisibleText::of($mail->html);

    expect($text)->toContain('Empresa: Soluciones y Derivados')
        ->toContain('Quién: María Paula Rojas (maria.rojas@segurex.com)')
        ->toContain('Cuándo: 21/10/2026 09:00')
        ->toContain('Cambio: edición de los textos')
        ->toContain('Asunto')
        ->toContain('− Cobro de cartera - Soluciones y Derivados de Colombia S.A.S')
        ->toContain('+ Pago pendiente de {cliente}')
        ->not->toContain('Texto antes de la tabla de facturas')
        ->not->toContain('Espero que se encuentre bien')
        ->and($mail->html)->toContain(route('cobro.plantillas.edit', ['empresa' => 'SDC']));
});

it('shows links and HTML typed in the template as plain text', function () {
    $this->actingAs($this->analyst)->put('/cobro/plantillas/SGX', ($this->form)(CompanyCode::SGX, [
        'cierre' => "Nueva cuenta para pagos: Bancolombia 123-456\nPaga en https://pagos-falsos.example/cuenta <b>x</b>",
    ]));

    $html = $this->fakes->mail->sent()[0]->html;

    expect($this->fakes->mail->sent()[0]->company)->toBe(CompanyCode::SGX)
        ->and($html)->toContain('&lt;b&gt;x&lt;/b&gt;')
        ->not->toContain('<b>x</b>')
        ->toContain('https://pagos-falsos.example/cuenta')
        ->not->toContain('href="https://pagos-falsos')
        ->and(VisibleText::of($html))->toContain('Texto después de la tabla de facturas')
        ->toContain('+ Nueva cuenta para pagos: Bancolombia 123-456');
});

it('sends nothing when a save changes no text', function () {
    $store = app(TemplateStore::class);
    $store->save(CompanyCode::SGX, RunKind::Weekly, new TemplateContent('A', 'B', 'C'), $this->analyst->id);
    $store->save(CompanyCode::SGX, RunKind::Weekly, new TemplateContent('A', 'B', 'C'), $this->analyst->id);

    $this->fakes->mail->assertSent(times: 1);
});

it('mails the differences with the defaults when a customized template is restored', function () {
    app(TemplateStore::class)->save(CompanyCode::SGX, RunKind::Weekly, new TemplateContent('Asunto propio', 'Inicio propio', 'Cierre propio'), $this->analyst->id);

    $this->actingAs($this->analyst)->post('/cobro/plantillas/SGX/restaurar')->assertRedirect('/cobro/plantillas?empresa=SGX');

    $this->fakes->mail->assertSent(times: 2);
    $text = VisibleText::of($this->fakes->mail->sent()[1]->html);

    expect(Template::query()->count())->toBe(0)
        ->and($text)->toContain('Cambio: volvió a los textos por defecto')
        ->toContain('Quién: María Paula Rojas (maria.rojas@segurex.com)')
        ->toContain('− Asunto propio')
        ->toContain('+ Cobro de cartera - Segurex LATAM SAS')
        ->toContain('− Inicio propio')
        ->toContain('− Cierre propio');
});

it('sends nothing when the restored template already had the default texts', function () {
    $this->actingAs($this->analyst)->put('/cobro/plantillas/SGX', ($this->form)(CompanyCode::SGX));
    expect(Template::query()->count())->toBe(1);

    $this->actingAs($this->analyst)->post('/cobro/plantillas/SGX/restaurar');
    $this->actingAs($this->analyst)->post('/cobro/plantillas/SDC/restaurar');

    expect(Template::query()->count())->toBe(0);
    $this->fakes->mail->assertNothingSent();
});

it('logs a warning and still saves when no recipients are configured', function () {
    config(['cobro.template_alert_recipients' => []]);
    Log::spy();

    $this->actingAs($this->analyst)
        ->put('/cobro/plantillas/SGX', ($this->form)(CompanyCode::SGX, ['asunto' => 'Otro asunto']))
        ->assertSessionHas('status', 'Guardaste la plantilla de Segurex Latam.');

    expect(Template::query()->sole()->subject)->toBe('Otro asunto');
    $this->fakes->mail->assertNothingSent();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'no notice recipients'))->once();
});

it('logs an error and still saves when the notice cannot be sent', function () {
    $this->fakes->mail->failNextWith(new MailNotSent('HTTP 403.'));
    Log::spy();

    $this->actingAs($this->analyst)
        ->put('/cobro/plantillas/SGX', ($this->form)(CompanyCode::SGX, ['asunto' => 'Otro asunto']))
        ->assertRedirect('/cobro/plantillas?empresa=SGX')
        ->assertSessionHas('status', 'Guardaste la plantilla de Segurex Latam.');

    expect(Template::query()->sole()->subject)->toBe('Otro asunto');
    $this->fakes->mail->assertNothingSent();
    Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'template change notice could not be sent'))->once();
});
