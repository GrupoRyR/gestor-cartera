<?php

use App\Modules\Core\Contracts\Data\IncomingBatch;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\LoadSource;
use App\Modules\Core\Contracts\Events\BatchAccepted;
use App\Modules\Core\Contracts\IngestaSink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\FakeIngestaSink;
use Tests\Modules\Asesores\Support\AdvisorWorld;

/*
| When the data on screen was last refreshed: the moment Ingesta received the
| cartera batch behind the cutoff, shown next to it in Bogotá time.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse(AdvisorWorld::TODAY, 'America/Bogota'));
    $this->world = AdvisorWorld::create();
    $this->app->instance(IngestaSink::class, $this->sink = new FakeIngestaSink);

    // Receives a cartera batch at $receivedAt (Bogotá unless the string says otherwise).
    $this->receive = function (CompanyCode $company, string $cutoff, string $receivedAt, string $timezone = 'America/Bogota'): void {
        $this->travelTo(CarbonImmutable::parse($receivedAt, $timezone));

        Event::fake([BatchAccepted::class]);
        $this->sink->receive(new IncomingBatch(
            Dataset::Receivables,
            $company,
            CarbonImmutable::parse($cutoff, 'America/Bogota'),
            LoadSource::Robot,
            "lote-{$company->value}-{$receivedAt}",
            [],
        ));
    };

    $this->now = fn (string $moment = AdvisorWorld::TODAY, string $timezone = 'America/Bogota') => $this->travelTo(CarbonImmutable::parse($moment, $timezone));
});

it('says the data was refreshed today, with the hour', function () {
    ($this->receive)(CompanyCode::SGX, AdvisorWorld::CUTOFF, '2026-10-16 07:10');
    ($this->now)();

    $this->actingAs($this->world->ana)->get(route('asesores.inicio'))
        ->assertOk()
        ->assertSeeText('Cartera al corte del 15/10/2026.')
        ->assertSeeText('Datos actualizados hoy a las 07:10.');
});

it('names the day when the data was refreshed before today', function () {
    ($this->receive)(CompanyCode::SGX, AdvisorWorld::CUTOFF, '2026-10-15 18:45');
    ($this->now)();

    $this->actingAs($this->world->ana)->get(route('asesores.inicio'))
        ->assertOk()
        ->assertSeeText('Datos actualizados el 15/10/2026 a las 18:45.');
});

it('says nothing about the refresh when there is no load', function () {
    ($this->now)();

    $this->actingAs($this->world->ana)->get(route('asesores.inicio'))
        ->assertOk()
        ->assertSeeText('Cartera al corte del 15/10/2026.')
        ->assertDontSeeText('Datos actualizados');
});

it('reads only the loads of the companies in the current view', function () {
    ($this->receive)(CompanyCode::SGX, AdvisorWorld::CUTOFF, '2026-10-16 06:00');
    ($this->receive)(CompanyCode::SDC, '2026-10-14', '2026-10-16 08:30');
    ($this->now)();

    $this->actingAs($this->world->ana)->get(route('asesores.inicio'))
        ->assertOk()
        ->assertSeeText('Datos actualizados hoy a las 06:00.')
        ->assertDontSeeText('08:30');
});

it('uses the company with the older cutoff in the group view', function () {
    ($this->receive)(CompanyCode::SGX, AdvisorWorld::CUTOFF, '2026-10-16 06:00');
    ($this->receive)(CompanyCode::SDC, '2026-10-14', '2026-10-16 08:30');
    ($this->now)();

    $this->actingAs($this->world->gloria)
        ->withSession(['core.company' => 'group'])
        ->get(route('asesores.inicio'))
        ->assertOk()
        ->assertSeeText('Datos actualizados hoy a las 08:30.')
        ->assertDontSeeText('06:00');
});

it('shows the hour of Bogotá even when the clock runs in UTC', function () {
    // 02:30 UTC on the 16th is 21:30 of the 15th in Bogotá.
    ($this->receive)(CompanyCode::SGX, AdvisorWorld::CUTOFF, '2026-10-16 02:30', 'UTC');
    ($this->now)('2026-10-16 15:00', 'UTC');

    $this->actingAs($this->world->ana)->get(route('asesores.inicio'))
        ->assertOk()
        ->assertSeeText('Datos actualizados el 15/10/2026 a las 21:30.');
});

it('repeats the refresh inside the warning of an old cutoff', function () {
    ($this->receive)(CompanyCode::SGX, AdvisorWorld::CUTOFF, '2026-10-15 07:10');
    ($this->now)('2026-10-20 10:00');

    $this->actingAs($this->world->ana)->get(route('asesores.inicio'))
        ->assertOk()
        ->assertSeeText('Es posible que esta cartera esté desactualizada')
        ->assertSeeText('El último corte es del 15/10/2026, hace 5 días.')
        ->assertSeeText('Datos actualizados el 15/10/2026 a las 07:10.');
});

it('shows the refresh on every screen with a cutoff', function (string $route) {
    // Same cutoff in both companies: the batch received first decides.
    ($this->receive)(CompanyCode::SDC, AdvisorWorld::CUTOFF, '2026-10-16 07:10');
    ($this->receive)(CompanyCode::SGX, AdvisorWorld::CUTOFF, '2026-10-16 07:20');
    ($this->now)();

    $this->actingAs($this->world->gloria)
        ->withSession(['core.company' => 'group'])
        ->get(route($route))
        ->assertOk()
        ->assertSeeText('Datos actualizados hoy a las 07:10.');
})->with([
    'dashboard' => ['asesores.inicio'],
    'clients' => ['asesores.clientes.index'],
    'history' => ['asesores.historico'],
    'team' => ['asesores.equipo.index'],
]);
