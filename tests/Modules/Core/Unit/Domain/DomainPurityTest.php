<?php

/*
| Core's domain rules are plain PHP: the application layer feeds them dates,
| balances and parameters, so they never read the clock, the configuration
| or the database themselves.
*/

arch('Core domain rules do not use Laravel')
    ->expect('App\\Modules\\Core\\Domain')
    ->not->toUse(['Illuminate', 'now', 'today', 'config', 'env']);

arch('Core domain classes are final')
    ->expect('App\\Modules\\Core\\Domain')
    ->classes()
    ->toBeFinal();
