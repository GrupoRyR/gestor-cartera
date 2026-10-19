<?php

/*
|--------------------------------------------------------------------------
| Why an advisor cannot read the portfolio of another
|--------------------------------------------------------------------------
|
| The scope of the portfolio (who sees which client) is Core's: PortfolioScope,
| behind CarteraRepository, CompromisoService and GestionLogger. Asesores is
| safe as long as it has no other way to the data, and these rules keep it so:
| no database access, no models, no tables of its own, and never the system
| copy of the portfolio. The module-to-module boundary itself is checked in
| tests/Architecture/ModulesTest.php.
|
*/

arch('Asesores never touches the database: every read and write goes through the Core contracts')
    ->expect('App\Modules\Asesores')
    ->not->toUse([
        'Illuminate\Database',
        'Illuminate\Support\Facades\DB',
        'Illuminate\Support\Facades\Schema',
    ]);

arch('Asesores has no Eloquent models of its own')
    ->expect('App\Modules\Asesores')
    ->not->toExtend('Illuminate\Database\Eloquent\Model');

arch('the domain rules are plain PHP: no framework in them')
    ->expect('App\Modules\Asesores\Domain')
    ->not->toUse('Illuminate');

arch('the module does not depend on test code')
    ->expect('App\Modules\Asesores')
    ->not->toUse('Tests');

it('has no migrations: clients, commitments and the log are Core’s tables', function () {
    expect(glob(app_path('Modules/Asesores/database/migrations').DIRECTORY_SEPARATOR.'*.php') ?: [])->toBe([]);
});

it('never calls the system copy of the portfolio, the one without user scope', function () {
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Modules/Asesores'), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'asSystem')) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([], 'asSystem() is for jobs and listeners, never for the screens of an advisor.');
});
