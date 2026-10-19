<?php

use App\Modules\Bancos\Domain\Statement\StatementReaders;
use App\Modules\Core\Contracts\Enums\Bank;

/*
|--------------------------------------------------------------------------
| How Bancos is built
|--------------------------------------------------------------------------
|
| The rules that keep the readers, the importer and the reports independent
| of the framework and of each other. The module-to-module boundary itself
| (only Core's contracts) is checked in tests/Architecture/ModulesTest.php.
|
*/

arch('the domain is plain PHP: readers, dates, amounts and reports use no framework')
    ->expect('App\Modules\Bancos\Domain')
    ->not->toUse('Illuminate');

arch('the domain does not reach for the database, the web or the files')
    ->expect('App\Modules\Bancos\Domain')
    ->not->toUse([
        'App\Modules\Bancos\Infrastructure',
        'App\Modules\Bancos\Http',
        'App\Modules\Bancos\Application',
    ]);

arch('the importer knows the readers only through their interface and the registry')
    ->expect('App\Modules\Bancos\Application\Import\MovementImporter')
    ->not->toUse([
        'App\Modules\Bancos\Domain\Statement\Banks',
        'App\Modules\Bancos\Infrastructure\Files',
    ]);

arch('every reader of a bank implements the interface')
    ->expect('App\Modules\Bancos\Domain\Statement\Banks')
    ->toImplement('App\Modules\Bancos\Domain\Statement\StatementReader')
    ->toBeFinal();

arch('the controllers do not touch the database tables of the module directly')
    ->expect('App\Modules\Bancos\Http\Controllers')
    ->not->toUse('Illuminate\Support\Facades\DB');

arch('the module does not depend on test code')
    ->expect('App\Modules\Bancos')
    ->not->toUse('Tests');

it('has a reader for every bank of the Core enum', function () {
    foreach (Bank::cases() as $bank) {
        expect(StatementReaders::for($bank)->bank())->toBe($bank);
    }
});

it('has no routes that are closures, so the routes can be cached', function () {
    $closures = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'bancos.') && $route->getAction('uses') instanceof Closure)
        ->map(fn ($route) => $route->getName())
        ->values()
        ->all();

    expect($closures)->toBe([]);
});
