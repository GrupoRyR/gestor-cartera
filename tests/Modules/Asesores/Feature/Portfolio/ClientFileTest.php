<?php

use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Infrastructure\Persistence\ContactRecord;
use App\Support\Formatting\Money;
use Carbon\CarbonImmutable;
use Tests\Modules\Asesores\Support\AdvisorWorld;
use Tests\Modules\Asesores\Support\Page;
use Tests\Modules\Core\Support\ActivityRecords;
use Tests\Modules\Core\Support\CarteraRecords;
use Tests\Modules\Core\Support\CommitmentRecords;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse(AdvisorWorld::TODAY, 'America/Bogota'));
    $this->world = AdvisorWorld::create();
    $this->agro = $this->world->clients['agro'];
});

it('shows the position of the client from its open documents', function () {
    $this->agro->update(['credit_limit' => '6000000.00']);

    $response = $this->actingAs($this->world->ana)->get(route('asesores.clientes.show', $this->agro->id))->assertOk();

    expect(Page::html($response)->text('//h1'))->toBe('Agro Arango')
        ->and(Page::stat($response, 'Saldo total'))->toBe(Money::format(7_000_000))
        ->and(Page::stat($response, 'Sin vencer'))->toBe(Money::format(2_000_000))
        ->and(Page::stat($response, 'Vencido'))->toBe(Money::format(5_000_000))
        ->and(Page::stat($response, 'Cupo de crédito'))->toBe(Money::format(6_000_000));
    $response->assertSeeText('2 documentos abiertos')
        ->assertSeeText('Excedido en '.Money::format(1_000_000))
        ->assertSeeText('Este cliente superó su cupo de crédito');
});

it('does not raise the credit alert while the client is within its limit', function () {
    $this->agro->update(['credit_limit' => '10000000.00']);

    $this->actingAs($this->world->ana)->get(route('asesores.clientes.show', $this->agro->id))
        ->assertOk()
        ->assertSeeText('Disponible: '.Money::format(3_000_000))
        ->assertDontSeeText('superó su cupo');
});

it('lists the open documents with their due date and age', function () {
    $response = $this->actingAs($this->world->ana)->get(route('asesores.clientes.show', $this->agro->id));
    $caption = 'Facturas y otros documentos abiertos del cliente';

    expect(Page::tableColumn($response, $caption, 4))->toBe(['100 días de mora', 'Vence en 10 días'])
        ->and(Page::tableColumn($response, $caption, 6))->toBe([Money::format(5_000_000), Money::format(2_000_000)])
        ->and(Page::tableColumn($response, $caption, 7))->toBe(['Más de 90 días', 'Sin vencer']);
});

it('names the SAP document types it knows and shows the others as they come', function () {
    $client = $this->world->clients['ferreteria'];
    CarteraRecords::invoice($this->world->sgx, $client, '-250000.00', 0, ['document_type' => '14', 'document_number' => '555001']);
    CarteraRecords::invoice($this->world->sgx, $client, '100000.00', 0, ['document_type' => 'ZZ', 'document_number' => '555002']);

    $response = $this->actingAs($this->world->ana)->get(route('asesores.clientes.show', $client->id))->assertOk();

    expect(Page::tableColumn($response, 'Facturas y otros documentos abiertos del cliente', 1))
        ->toContain('555001 Nota crédito', '555002 ZZ')
        ->and(Page::stat($response, 'Saldo total'))->toBe(Money::format(1_350_000));
});

it('shows the contacts, the commitments and the log of the client', function () {
    ContactRecord::factory()->primary()->create([
        'client_id' => $this->agro->id, 'name' => 'Laura Martínez', 'email' => 'laura@agro.example', 'phone' => '+57 311 555 0101', 'position' => 'Tesorería',
    ]);
    CommitmentRecords::commitment($this->agro, $this->world->ana, '2026-10-30', '2000000.00', ['notes' => 'Paga con el recaudo de la cosecha']);
    CommitmentRecords::commitment($this->agro, $this->world->ana, '2026-10-20', '1000000.00');
    CommitmentRecords::commitment($this->agro, $this->world->ana, '2026-10-01', '500000.00', ['status' => CommitmentStatus::Fulfilled, 'fulfilled_at' => '2026-10-02 10:00:00']);
    ActivityRecords::entry($this->agro, 'Llamada a tesorería', '2026-10-14 09:30', $this->world->ana, ['details' => 'Prometen pagar la próxima semana']);

    $response = $this->actingAs($this->world->ana)->get(route('asesores.clientes.show', $this->agro->id))->assertOk();
    $html = Page::html($response);

    $response->assertSeeText('Laura Martínez')->assertSeeText('Tesorería')->assertSeeText('laura@agro.example')
        ->assertSeeText('Paga con el recaudo de la cosecha')
        ->assertSeeText('Llamada a tesorería')->assertSeeText('Prometen pagar la próxima semana')
        ->assertSeeText('Ana Álvarez · 14/10/2026 09:30');

    // The ones to act on first (soonest due on top), then the closed one.
    expect(array_map(fn ($item) => preg_match('/\$\s?[\d.]+/u', $item->textContent, $m) ? $m[0] : '', $html->all('//ul[@data-part="commitments"]/li')))
        ->toBe([Money::format(1_000_000), Money::format(2_000_000), Money::format(500_000)]);
    expect($html->all('//a[starts-with(@href,"mailto:")]'))->toHaveCount(1);
});

it('says so when there is nothing to show yet', function () {
    $response = $this->actingAs($this->world->ana)
        ->get(route('asesores.clientes.show', $this->world->clients['ferreteria']->id))
        ->assertOk();

    $response->assertSeeText('Aún no hay compromisos de pago de este cliente.')
        ->assertSeeText('Todavía no hay gestiones registradas para este cliente.')
        ->assertSeeText('Aún no tenemos contactos de este cliente.');
});

it('is linked from the client list and from the priority list', function () {
    $list = $this->actingAs($this->world->ana)->get(route('asesores.clientes.index'));
    $dashboard = $this->actingAs($this->world->ana)->get(route('asesores.inicio'));
    $url = route('asesores.clientes.show', $this->agro->id);

    expect(Page::html($list)->count("//a[@href='{$url}']"))->toBe(1)
        ->and(Page::html($dashboard)->count("//a[@href='{$url}']"))->toBe(1);
});

it('does not exist for an advisor when the client belongs to somebody else', function (string $client) {
    $id = $this->world->clients[$client]->id;

    $this->actingAs($this->world->ana)->get(route('asesores.clientes.show', $id))->assertNotFound();
})->with([
    'a client of another advisor' => ['bodega'],
    'a client of nobody' => ['distribuciones'],
    'a client of the team of a manager' => ['comercial'],
    'a client of the other company with the same SAP code' => ['equipos'],
]);

it('answers an id nobody has exactly as it answers somebody else’s client', function () {
    $other = $this->actingAs($this->world->ana)->get(route('asesores.clientes.show', $this->world->clients['bodega']->id));
    $nobody = $this->actingAs($this->world->ana)->get(route('asesores.clientes.show', 987654));

    expect($other->status())->toBe(404)->and($nobody->status())->toBe(404);
    $other->assertDontSeeText('Bodega Bedoya')->assertDontSeeText('Diana Duque');
});

it('shows a sales manager the clients of the team, in the company they work in', function () {
    $this->actingAs($this->world->gloria)->get(route('asesores.clientes.show', $this->world->clients['comercial']->id))->assertOk();
    // Granos Giraldo is SDC and Gloria works in SGX now.
    $this->actingAs($this->world->gloria)->get(route('asesores.clientes.show', $this->world->clients['granos']->id))->assertNotFound();
    $this->actingAs($this->world->gloria)->withSession(['core.company' => 'group'])
        ->get(route('asesores.clientes.show', $this->world->clients['granos']->id))->assertOk();
    // Never the clients of advisors outside the team.
    $this->actingAs($this->world->gloria)->withSession(['core.company' => 'group'])
        ->get(route('asesores.clientes.show', $this->agro->id))->assertNotFound();
});

it('is closed to guests and to the roles that have their own screens', function () {
    $this->get(route('asesores.clientes.show', $this->agro->id))->assertRedirect(route('core.login'));
    $this->actingAs($this->world->mariaPaula)->get(route('asesores.clientes.show', $this->agro->id))->assertForbidden();
});

it('only takes a number in the URL', function () {
    $this->actingAs($this->world->ana)->get('/asesores/clientes/agro')->assertNotFound();
});
