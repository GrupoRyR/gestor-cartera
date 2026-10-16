<?php

/*
| The commission engine is plain PHP: the infrastructure session feeds it SAP
| lines, budgets, parameters and dates, so the domain and the use cases never
| read the clock, the configuration or the database themselves.
*/

arch('Comisiones domain does not use Laravel')
    ->expect('App\\Modules\\Comisiones\\Domain')
    ->not->toUse(['Illuminate', 'now', 'today', 'config', 'env']);

arch('Comisiones application does not use Laravel')
    ->expect('App\\Modules\\Comisiones\\Application')
    ->not->toUse(['Illuminate', 'now', 'today', 'config', 'env']);

arch('Comisiones domain classes are final')
    ->expect('App\\Modules\\Comisiones\\Domain')
    ->classes()
    ->toBeFinal();

arch('Comisiones application classes are final')
    ->expect('App\\Modules\\Comisiones\\Application')
    ->classes()
    ->toBeFinal();
