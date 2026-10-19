<?php

use App\Modules\Cobro\Database\Seeders\CobroPermissionSeeder;
use App\Modules\Cobro\Domain\Runs\NoticeData;
use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Cobro\Domain\Templates\TemplateContent;
use App\Modules\Cobro\Infrastructure\Mail\NoticeRenderer;
use App\Modules\Cobro\Infrastructure\Persistence\Template;
use App\Modules\Cobro\Infrastructure\Templates\TemplateStore;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\BrandPalette;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use Carbon\CarbonImmutable;
use Tests\Fakes\AuditEntry;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\FakeCurrentCompany;
use Tests\Fakes\Make;
use Tests\Modules\Cobro\Support\CobroUsers;

beforeEach(function () {
    $this->seed(CobroPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-21 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->analyst = CobroUsers::create(Role::PortfolioAnalyst);
});

/**
 * The visible text of an HTML mail, without tags and with collapsed spaces.
 */
function cobroText(string $html): string
{
    return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5))));
}

function cobroNotice(): NoticeData
{
    return new NoticeData(
        clientName: 'Ferretería <El Tornillo> SAS',
        taxId: '900123456',
        invoices: [
            Make::invoice(documentNumber: 'FV-1188', amount: 2_350_000, daysOverdue: 47),
            Make::invoice(documentNumber: 'FV-1201', amount: 1_150_000, daysOverdue: 18),
        ],
        cutoffDate: CarbonImmutable::parse('2026-10-20', 'America/Bogota'),
    );
}

it('renders the weekly mail with the filled texts, the invoices and the total', function () {
    $mail = app(NoticeRenderer::class)->render(
        'cobro::correos.cobro-semanal',
        Make::company(),
        cobroNotice(),
        TemplateStore::default(RunKind::Weekly),
    );

    $text = cobroText($mail->html);

    expect($mail->subject)->toBe('Facturas vencidas de Ferretería <El Tornillo> SAS con Segurex Latam')
        ->and($text)->toContain('Hola, Ferretería <El Tornillo> SAS:')
        ->toContain('Con corte al 20/10/2026, el saldo vencido de tus facturas es $ 3.500.000.')
        ->toContain('Factura FV-1188 '.now()->subDays(47)->format('d/m/Y').' 47 $ 2.350.000')
        ->toContain('Total vencido $ 3.500.000')
        ->toContain('Área de cartera de Segurex Latam')
        ->and($mail->html)->not->toContain('<El Tornillo>')
        ->toContain('&lt;El Tornillo&gt;');
});

it('frames the mail with the company palette', function () {
    $company = Make::company(code: CompanyCode::SDC);
    $palette = BrandPalette::official(CompanyCode::SDC);

    $mail = app(NoticeRenderer::class)->render('cobro::correos.cobro-semanal', $company, cobroNotice(), TemplateStore::default(RunKind::Weekly));

    expect($mail->html)->toContain('background:'.$palette->button)
        ->toContain('background:'.$palette->tint.';color:'.$palette->ink)
        ->toContain($company->name);
});

it('marks test and draft mails in the subject and the body', function () {
    $renderer = app(NoticeRenderer::class);
    $template = new TemplateContent('Cartera de {cliente}', 'Hola', 'Chao');

    $test = $renderer->render('cobro::correos.cobro-semanal', Make::company(), cobroNotice(), $template, test: true);
    $draft = $renderer->render('cobro::correos.cobro-semanal', Make::company(), cobroNotice(), $template, draft: true);

    expect($test->subject)->toBe('[Prueba] Cartera de Ferretería <El Tornillo> SAS')
        ->and($test->html)->toContain('data-banner="prueba"')
        ->and($draft->subject)->toBe('[Borrador] Cartera de Ferretería <El Tornillo> SAS')
        ->and($draft->html)->toContain('data-banner="borrador"');
});

it('splits the texts in paragraphs at blank lines', function () {
    $mail = app(NoticeRenderer::class)->render(
        'cobro::correos.cobro-semanal',
        Make::company(),
        cobroNotice(),
        new TemplateContent('Asunto', "Uno\nsigue\n\n\nDos", 'Fin'),
    );

    expect($mail->html)->toContain('<p style="margin:0 0 16px;">Uno<br />'."\nsigue</p>")
        ->toContain('<p style="margin:0 0 16px;">Dos</p>');
});

it('opens the editor with the default texts', function () {
    $this->actingAs($this->analyst)->get('/cobro/plantillas')
        ->assertOk()
        ->assertSeeText('Plantilla del cobro semanal')
        ->assertSeeText('Usa los textos por defecto')
        ->assertSee('Facturas vencidas de {cliente} con {empresa}', false)
        ->assertSeeText('{total_vencido}');
});

it('forbids the editor to roles without cobro.manage', function (?Role $role) {
    $user = CobroUsers::create($role);

    $this->actingAs($user)->get('/cobro/plantillas')->assertForbidden();
    $this->actingAs($user)->put('/cobro/plantillas/SGX', ['asunto' => 'X', 'inicio' => 'X', 'cierre' => 'X'])->assertForbidden();
})->with(CobroUsers::notManagers());

it('saves the texts of a company and audits the change', function () {
    $this->actingAs($this->analyst)
        ->put('/cobro/plantillas/SGX', ['asunto' => ' Cartera pendiente de {cliente} ', 'inicio' => "Hola, {cliente}.\r\n\r\nTe escribimos.", 'cierre' => 'Gracias'])
        ->assertRedirect('/cobro/plantillas?empresa=SGX')
        ->assertSessionHas('status', 'Guardaste la plantilla de Segurex Latam.');

    $saved = Template::query()->sole();

    expect($saved->company)->toBe(CompanyCode::SGX)
        ->and($saved->kind)->toBe(RunKind::Weekly)
        ->and($saved->subject)->toBe('Cartera pendiente de {cliente}')
        ->and($saved->intro)->toBe("Hola, {cliente}.\n\nTe escribimos.")
        ->and($saved->updated_by)->toBe($this->analyst->id)
        ->and(app(TemplateStore::class)->for(CompanyCode::SGX, RunKind::Weekly)->isDefault)->toBeFalse()
        ->and(app(TemplateStore::class)->for(CompanyCode::SDC, RunKind::Weekly)->isDefault)->toBeTrue();

    $this->fakes->audit->assertRecorded('cobro.template.updated', fn (AuditEntry $entry): bool => $entry->changes['subject'] === [
        'old' => 'Facturas vencidas de {cliente} con {empresa}',
        'new' => 'Cartera pendiente de {cliente}',
    ]);

    $this->actingAs($this->analyst)->get('/cobro/plantillas?empresa=SGX')->assertSeeText('Editada por María Paula Rojas');
});

it('rejects unknown placeholders and missing texts', function () {
    $this->actingAs($this->analyst)
        ->put('/cobro/plantillas/SGX', ['asunto' => 'Saldo de {saldo}', 'inicio' => '', 'cierre' => 'Gracias'])
        ->assertSessionHasErrors([
            'asunto' => 'Estos datos no existen: {saldo}. Revisa la lista de datos que puedes usar.',
            'inicio',
        ]);

    expect(Template::query()->count())->toBe(0);
});

it('restores the default texts', function () {
    app(TemplateStore::class)->save(CompanyCode::SGX, RunKind::Weekly, new TemplateContent('A', 'B', 'C'), null);

    $this->actingAs($this->analyst)->post('/cobro/plantillas/SGX/restaurar')
        ->assertSessionHas('status', 'La plantilla de Segurex Latam volvió a los textos por defecto.');

    expect(Template::query()->count())->toBe(0);
    $this->fakes->audit->assertRecorded('cobro.template.reset');
});

it('answers 404 for a company the user may not choose', function () {
    $this->app->instance(CurrentCompany::class, new FakeCurrentCompany(CompanyCode::SGX, [CompanyCode::SGX]));

    $this->actingAs($this->analyst)->put('/cobro/plantillas/SDC', ['asunto' => 'A', 'inicio' => 'B', 'cierre' => 'C'])->assertNotFound();
    $this->actingAs($this->analyst)->get('/cobro/plantillas/SDC/vista-previa')->assertNotFound();
});

it('previews the mail with sample data and the saved texts', function () {
    app(TemplateStore::class)->save(CompanyCode::SGX, RunKind::Weekly, new TemplateContent('Asunto {cliente}', 'Inicio para {cliente}', 'Cierre'), null);

    $response = $this->actingAs($this->analyst)->get('/cobro/plantillas/SGX/vista-previa')->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('text/html; charset=UTF-8')
        ->and(cobroText((string) $response->getContent()))->toContain('Inicio para Cliente de ejemplo SAS')
        ->toContain('FV-1188')
        ->toContain('Total vencido $ 3.500.000');
});
