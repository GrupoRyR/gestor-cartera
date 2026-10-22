<?php

use App\Modules\Core\Contracts\Enums\Role;
use Database\Seeders\DatabaseSeeder;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Bancos\Support\BancosUsers;

/*
 * / opens the first menu entry a user may see, so the Bancos entries go
 * after Cartera and Gerencia: the portfolio roles also open Bancos and must
 * still land on Cartera. Every module's permissions are seeded, as in
 * production.
 */

beforeEach(function () {
    ContractFakes::install($this->app);
    $this->seed(DatabaseSeeder::class);
});

it('sends the portfolio manager and the analyst to Cartera, not to Recaudo', function (Role $role) {
    $this->actingAs(BancosUsers::create($role))->get('/')->assertRedirect(route('cartera.tablero'));
})->with([
    'gerente de cartera' => [Role::PortfolioManager],
    'analista de cartera' => [Role::PortfolioAnalyst],
]);

it('still sends treasury to Recaudo, its only module', function () {
    $this->actingAs(BancosUsers::create(Role::Treasury))->get('/')->assertRedirect(route('bancos.recaudo'));
});
