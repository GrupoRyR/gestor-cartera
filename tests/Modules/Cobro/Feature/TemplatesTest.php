<?php

use App\Modules\Cobro\Database\Seeders\CobroPermissionSeeder;
use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Cobro\Domain\Templates\TemplateContent;
use App\Modules\Cobro\Infrastructure\Persistence\Template;
use App\Modules\Cobro\Infrastructure\Templates\TemplateStore;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use Carbon\CarbonImmutable;
use Tests\Fakes\AuditEntry;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\FakeCurrentCompany;
use Tests\Modules\Cobro\Support\CobroUsers;
use Tests\Modules\Cobro\Support\VisibleText;

beforeEach(function () {
    $this->seed(CobroPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-21 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->analyst = CobroUsers::create(Role::PortfolioAnalyst);
});

it('opens the editor with the default texts of each company', function () {
    $this->actingAs($this->analyst)->get('/cobro/plantillas')
        ->assertOk()
        ->assertSeeText('Plantilla del cobro semanal')
        ->assertSeeText('Usa los textos por defecto')
        ->assertSee('Cobro de cartera - Segurex LATAM SAS', false)
        ->assertSee('Estimado cliente **{cliente}**', false)
        ->assertSeeText('{numeros_facturas}');

    $this->actingAs($this->analyst)->get('/cobro/plantillas?empresa=SDC')
        ->assertOk()
        ->assertSee('Cobro de cartera - Soluciones y Derivados de Colombia S.A.S', false)
        ->assertSee('Auxiliar de Crédito y Cobranza', false);
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
        'old' => 'Cobro de cartera - Segurex LATAM SAS',
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
        ->and(VisibleText::of((string) $response->getContent()))->toContain('Inicio para CLIENTE DE EJEMPLO SAS')
        ->toContain('41 ASESOR DE EJEMPLO CN0001 CLIENTE DE EJEMPLO SAS 900123456-1 1188')
        ->toContain('1250')
        ->toContain('$ 4.480.000');
});
