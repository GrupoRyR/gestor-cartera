<?php

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\CompromisoService;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\GestionLogger;
use App\Modules\Core\Contracts\IngestaSink;
use App\Modules\Core\Contracts\MailboxReader;
use App\Modules\Core\Contracts\MailSender;
use App\Modules\Core\Contracts\RecaudoProvider;

/*
|--------------------------------------------------------------------------
| Core contracts (docs/prompt-gestor-cartera.md, Part C.4)
|--------------------------------------------------------------------------
|
| Every module depends on these types, so they stay plain PHP: no database,
| no HTTP, no facades and nothing from the rest of the application.
|
*/

$contracts = 'App\\Modules\\Core\\Contracts';

/*
| Every application namespace except the contracts themselves, derived from
| the directories under app/. ignoring() cannot be used here: it would also
| drop the contracts, which are the target of the expectation.
*/
$appPath = __DIR__.'/../../app';
$application = [];

foreach (glob($appPath.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
    if (basename($directory) !== 'Modules') {
        $application[] = 'App\\'.basename($directory);
    }
}

foreach (glob($appPath.'/Modules/*', GLOB_ONLYDIR) ?: [] as $module) {
    if (basename($module) !== 'Core') {
        $application[] = 'App\\Modules\\'.basename($module);

        continue;
    }

    foreach (glob($module.'/*', GLOB_ONLYDIR) ?: [] as $layer) {
        if (basename($layer) !== 'Contracts') {
            $application[] = 'App\\Modules\\Core\\'.ucfirst(basename($layer));
        }
    }
}

arch('contracts stay free of persistence, HTTP and facades')
    ->expect($contracts)
    ->not->toUse(['Illuminate\\Database', 'Illuminate\\Http', 'Illuminate\\Support\\Facades']);

arch('contracts do not depend on the rest of the application')
    ->expect($contracts)
    ->not->toUse($application);

it('derives the rest of the application from app/', function () use ($application) {
    expect($application)
        ->toContain('App\\Models', 'App\\Support', 'App\\Modules\\Cartera', 'App\\Modules\\Core\\Domain')
        ->not->toContain('App\\Modules\\Core\\Contracts', 'App\\Modules\\Core', 'App\\Modules');
});

arch('contract data objects are final and readonly')
    ->expect("{$contracts}\\Data")
    ->classes()
    ->toBeFinal()
    ->toBeReadonly();

arch('contract enums are string backed')
    ->expect("{$contracts}\\Enums")
    ->enums()
    ->toBeStringBackedEnums();

arch('contract exceptions are runtime exceptions')
    ->expect("{$contracts}\\Exceptions")
    ->classes()
    ->toExtend(RuntimeException::class);

arch('the nine C.4 contracts are interfaces')
    ->expect([
        CurrentCompany::class,
        CarteraRepository::class,
        CompromisoService::class,
        GestionLogger::class,
        AuditLogger::class,
        IngestaSink::class,
        MailSender::class,
        MailboxReader::class,
        RecaudoProvider::class,
    ])
    ->toBeInterfaces();
