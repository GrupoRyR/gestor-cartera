<?php

/*
|--------------------------------------------------------------------------
| How Soportes is built
|--------------------------------------------------------------------------
|
| The receipt rules stay plain PHP. The module-to-module boundary itself
| (only Core's contracts) is checked in tests/Architecture/ModulesTest.php.
|
*/

arch('the domain is plain PHP: statuses, methods and their rules use no framework')
    ->expect('App\Modules\Soportes\Domain')
    ->not->toUse('Illuminate');

arch('the domain does not reach for the database or the web')
    ->expect('App\Modules\Soportes\Domain')
    ->not->toUse([
        'App\Modules\Soportes\Infrastructure',
        'App\Modules\Soportes\Http',
    ]);

arch('the module does not depend on test code')
    ->expect('App\Modules\Soportes')
    ->not->toUse('Tests');
