<?php

use App\Models\User;
use App\Modules\Cobro\Database\Seeders\CobroPermissionSeeder;
use App\Modules\Cobro\Infrastructure\Persistence\Run;
use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Contracts\Exceptions\MailNotSent;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\NavRegistry;
use Carbon\CarbonImmutable;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Cobro\Support\CobroPortfolio;
use Tests\Modules\Cobro\Support\CobroUsers;

beforeEach(function () {
    $this->seed(CobroPermissionSeeder::class);
    // Monday: the next run is on Wednesday.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->portfolio = CobroPortfolio::seed($this->fakes);
    $this->analyst = CobroUsers::create(Role::PortfolioAnalyst, email: 'maria.rojas@segurex.com');
});

it('serves the weekly preview under /cobro/semanal', function () {
    expect(route('cobro.semanal.show', absolute: false))->toBe('/cobro/semanal')
        ->and(route('cobro.semanal.correo', ['empresa' => 'SGX', 'cliente' => 7], absolute: false))->toBe('/cobro/semanal/SGX/correo/7')
        ->and(route('cobro.semanal.prueba', ['empresa' => 'SGX'], absolute: false))->toBe('/cobro/semanal/SGX/prueba');
});

it('opens the preview for the cartera roles and the administrator', function (Role $role) {
    $this->actingAs(CobroUsers::create($role))->get('/cobro/semanal')->assertOk()->assertSeeText('Cobro semanal');
})->with([...CobroUsers::managers(), 'administrador' => [Role::Admin]]);

it('forbids the preview to every other role', function (?Role $role) {
    $this->actingAs(CobroUsers::create($role))->get('/cobro/semanal')->assertForbidden();
})->with(CobroUsers::notViewers());

it('shows who gets the next mail, without e-mail or excluded, and when', function () {
    $this->actingAs($this->analyst)->get('/cobro/semanal')->assertOk()
        ->assertSeeText('21/10/2026 a las 17:00 (hora de Colombia)')
        ->assertSeeText('Ferretería El Tornillo SAS')
        ->assertSeeText('tesoreria@tornillo.example, pagos@tornillo.example')
        ->assertSeeText('Distribuciones Andinas SAS')
        ->assertSeeText('Sin correo')
        ->assertSeeText('Comercializadora Excluida SAS')
        ->assertSeeText('Acuerdo de pago vigente')
        ->assertDontSeeText('Comercial Al Día SAS')
        ->assertDontSeeText('Químicos del Norte SAS');

    expect(Run::query()->count())->toBe(0);
    $this->fakes->mail->assertNothingSent();
});

it('filters the clients by status', function () {
    // The rows of the table; the test form still lists every client.
    $this->actingAs($this->analyst)->get('/cobro/semanal?estado=sin_correo')->assertOk()
        ->assertSee('data-client="'.$this->portfolio->andinas->summary->id.'"', false)
        ->assertDontSee('data-client="'.$this->portfolio->tornillo->summary->id.'"', false);
});

it('shows the mail of one client as it would leave', function () {
    $response = $this->actingAs($this->analyst)
        ->get('/cobro/semanal/SGX/correo/'.$this->portfolio->tornillo->summary->id)
        ->assertOk();

    expect((string) $response->getContent())->toContain('FV-1188')->toContain('Ferretería El Tornillo SAS')->not->toContain('FV-1250');
});

it('answers 404 for the mail of a client of another company or without overdue invoices', function () {
    $this->actingAs($this->analyst)->get('/cobro/semanal/SGX/correo/'.$this->portfolio->quimicos->summary->id)->assertNotFound();
    $this->actingAs($this->analyst)->get('/cobro/semanal/SGX/correo/'.$this->portfolio->alDia->summary->id)->assertNotFound();
    $this->actingAs($this->analyst)->get('/cobro/semanal/SDC/correo/'.$this->portfolio->quimicos->summary->id)->assertNotFound();
});

it('sends a test of a client mail to the signed-in user only', function () {
    $this->actingAs($this->analyst)
        ->from('/cobro/semanal')
        ->post('/cobro/semanal/SGX/prueba', ['cliente' => $this->portfolio->tornillo->summary->id])
        ->assertRedirect('/cobro/semanal')
        ->assertSessionHas('status', 'Te enviamos la prueba a maria.rojas@segurex.com.');

    $this->fakes->mail->assertSent(fn (OutgoingMail $mail): bool => $mail->to === ['maria.rojas@segurex.com']
        && $mail->subject === '[Prueba] Facturas vencidas de Ferretería El Tornillo SAS con Segurex Latam'
        && $mail->category === 'prueba'
        && $mail->clientId === null
        && str_contains($mail->html, 'data-banner="prueba"'), times: 1);

    $this->fakes->gestiones->assertNothingLogged();
});

it('explains when the test could not be sent', function () {
    $this->fakes->mail->failNextWith(new MailNotSent('HTTP 403.'));

    $this->actingAs($this->analyst)
        ->post('/cobro/semanal/SGX/prueba', ['cliente' => $this->portfolio->tornillo->summary->id])
        ->assertSessionHas('error', 'No se pudo enviar la prueba. Revisa la conexión con Microsoft 365 o inténtalo más tarde.');
});

it('refuses a test for a client without overdue invoices', function () {
    $this->actingAs($this->analyst)
        ->post('/cobro/semanal/SGX/prueba', ['cliente' => $this->portfolio->alDia->summary->id])
        ->assertSessionHas('error', 'Ese cliente ya no tiene facturas para este correo.');

    $this->fakes->mail->assertNothingSent();
});

it('forbids the test send without cobro.manage', function () {
    $this->actingAs(CobroUsers::create(Role::Admin))
        ->post('/cobro/semanal/SGX/prueba', ['cliente' => $this->portfolio->tornillo->summary->id])
        ->assertForbidden();

    $this->fakes->mail->assertNothingSent();
});

it('says so when the weekly mail is not sent for any of the user companies', function () {
    config(['cobro.weekly.companies' => []]);

    $this->actingAs($this->analyst)->get('/cobro/semanal')->assertOk()
        ->assertSeeText('Este envío no aplica a tus empresas');
});

it('adds Cobro semanal to the menu of the cartera roles', function () {
    $keys = fn (User $user): array => array_map(fn (NavItem $item): string => $item->key, app(NavRegistry::class)->visibleTo($user));

    expect($keys($this->analyst))->toContain('cobro.semanal')
        ->and($keys(CobroUsers::create(Role::SalesAdvisor)))->not->toContain('cobro.semanal');
});
