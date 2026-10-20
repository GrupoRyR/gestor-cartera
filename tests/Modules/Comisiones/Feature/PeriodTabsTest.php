<?php

use App\Modules\Comisiones\Database\Seeders\ComisionesPermissionSeeder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Comisiones\Feature\Concerns\AugustCollections;
use Tests\Modules\Comisiones\Feature\Concerns\ComisionesUsers;
use Tests\Modules\Comisiones\Feature\Concerns\SapBatches;

/*
| The period tabs show only the screens the user may open: the reports tab
| needs comisiones.reports, so a read-only user never meets a 403 there.
*/

beforeEach(function () {
    $this->seed(ComisionesPermissionSeeder::class);
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-09-03 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->sap = new SapBatches($this->fakes->ingesta);

    AugustCollections::stage($this->sap);
    $this->approver = ComisionesUsers::approver();

    AugustCollections::close($this, ComisionesUsers::analyst(), $this->approver);
});

it('hides the reports tab from a user without the reports permission', function () {
    $reports = '/comisiones/periodos/'.AugustCollections::PERIOD.'/informes';

    $this->actingAs(ComisionesUsers::viewer())
        ->get('/comisiones/periodos/'.AugustCollections::PERIOD)
        ->assertOk()
        ->assertSee('/controles', false)
        ->assertDontSee($reports, false);
});

it('shows the reports tab to a user with the reports permission', function () {
    $reports = '/comisiones/periodos/'.AugustCollections::PERIOD.'/informes';

    $this->actingAs($this->approver)
        ->get('/comisiones/periodos/'.AugustCollections::PERIOD)
        ->assertOk()
        ->assertSee($reports, false);
});
