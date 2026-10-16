<?php

use App\Modules\Asesores\Application\Export\ClientStatement;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\BrandPalette;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Infrastructure\Persistence\AuditLogRecord;
use App\Support\Formatting\Money;
use Carbon\CarbonImmutable;
use Tests\Modules\Asesores\Support\AdvisorWorld;
use Tests\Modules\Core\Support\CommitmentRecords;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse(AdvisorWorld::TODAY, 'America/Bogota'));
    $this->world = AdvisorWorld::create();
    $this->agro = $this->world->clients['agro'];
});

/**
 * The HTML the PDF of $clientId is drawn from, as the signed-in user gets it.
 */
function asesoresStatementHtml(int $clientId): string
{
    $detail = app(CarteraRepository::class)->client($clientId);

    return view(ClientStatement::VIEW, app(ClientStatement::class)->data($detail, CarbonImmutable::now()))->render();
}

it('downloads the account statement of a client as a PDF', function () {
    $response = $this->actingAs($this->world->ana)->get(route('asesores.clientes.estado_cuenta', $this->agro->id))->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('estado-de-cuenta-'.$this->agro->code.'-2026-10-16.pdf')
        ->and(str_starts_with((string) $response->getContent(), '%PDF'))->toBeTrue();
});

it('prints what the client owes, how old it is and the open documents', function () {
    $this->actingAs($this->world->ana);

    $html = asesoresStatementHtml($this->agro->id);

    expect($html)->toContain('Estado de cuenta')
        ->toContain('Agro Arango')
        ->toContain('Segurex Latam')
        ->toContain($this->agro->code)
        ->toContain(Money::format(7_000_000))
        ->toContain(Money::format(5_000_000))
        ->toContain(Money::format(2_000_000))
        ->toContain('15/10/2026')
        ->toContain('Más de 90 días')
        ->toContain('Documentos abiertos');
});

it('is a document for the client: it leaves out the commitments and the log', function () {
    CommitmentRecords::commitment($this->agro, $this->world->ana, '2026-10-30', '987654.00', ['notes' => 'Nota interna del asesor']);
    $this->actingAs($this->world->ana);

    expect(asesoresStatementHtml($this->agro->id))->not->toContain('Nota interna del asesor')->not->toContain(Money::format(987_654))->not->toContain('ompromiso');
});

it('never prints another client', function () {
    $this->actingAs($this->world->ana);

    expect(asesoresStatementHtml($this->agro->id))->not->toContain('Bodega Bedoya')->not->toContain('Ferretería Franco');
});

it('takes the colors and name from the company of the client, not from the active one', function () {
    $this->actingAs($this->world->gloria);
    app(CurrentCompany::class)->switchTo(null);
    $sdc = BrandPalette::official(CompanyCode::SDC)->primary;
    $sgx = BrandPalette::official(CompanyCode::SGX)->primary;

    $html = asesoresStatementHtml($this->world->clients['granos']->id);

    expect($html)->toContain('Soluciones y Derivados')->toContain($sdc)->not->toContain('Segurex Latam');
    expect(strtolower($html))->not->toContain(strtolower($sgx));
});

it('leaves a trace of every download', function () {
    $this->actingAs($this->world->ana)->get(route('asesores.clientes.estado_cuenta', $this->agro->id));

    $audit = AuditLogRecord::query()->where('action', 'asesores.client.statement_downloaded')->sole();

    expect($audit->user_id)->toBe($this->world->ana->id)->and($audit->subject_id)->toBe((string) $this->agro->id);
});

it('does not draw a client of another advisor, as if they did not exist', function (string $client) {
    $this->actingAs($this->world->ana)->get(route('asesores.clientes.estado_cuenta', $this->world->clients[$client]->id))->assertNotFound();

    expect(AuditLogRecord::query()->where('action', 'asesores.client.statement_downloaded')->count())->toBe(0);
})->with(['bodega', 'distribuciones', 'equipos', 'comercial']);

it('does not draw an unknown client either', function () {
    $this->actingAs($this->world->ana)->get(route('asesores.clientes.estado_cuenta', 987654))->assertNotFound();
});

it('lets a sales manager draw the clients of the team only', function () {
    $this->actingAs($this->world->gloria)->get(route('asesores.clientes.estado_cuenta', $this->world->clients['comercial']->id))->assertOk();
    $this->actingAs($this->world->gloria)->get(route('asesores.clientes.estado_cuenta', $this->agro->id))->assertNotFound();
});

it('is closed to guests and to the roles that have their own screens', function () {
    $this->get(route('asesores.clientes.estado_cuenta', $this->agro->id))->assertRedirect(route('core.login'));
    $this->actingAs($this->world->mariaPaula)->get(route('asesores.clientes.estado_cuenta', $this->agro->id))->assertForbidden();
});
