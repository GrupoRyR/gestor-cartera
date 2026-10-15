<?php

use App\Support\Modules\Module;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\ModuleServiceProvider;

/*
|--------------------------------------------------------------------------
| Module boundaries (docs/prompt-gestor-cartera.md, Part C.2)
|--------------------------------------------------------------------------
|
| Modules talk to each other only through App\Modules\Core\Contracts. The
| registry is built when this file loads (the app is not booted yet), so one
| set of rules is generated per module found under app/Modules; with no
| modules only the global rules run.
|
*/

$coreContracts = 'App\\Modules\\Core\\Contracts';

$modules = (new ModuleRegistry(__DIR__.'/../../app/Modules'))->all();

foreach ($modules as $module) {
    $others = array_values(array_map(
        fn (Module $other): string => $other->namespace,
        array_filter($modules, fn (Module $other): bool => $other->name !== $module->name),
    ));

    if ($module->name === 'Core') {
        if ($others !== []) {
            arch('Core does not depend on any other module')
                ->expect($module->namespace)
                ->not->toUse($others);
        }
    } else {
        // ignoring() drops both the uses and the layer objects whose name
        // starts with Core\Contracts, so only the contracts stay reachable.
        arch("{$module->name} reaches other modules only through Core contracts")
            ->expect($module->namespace)
            ->not->toUse($others)
            ->ignoring($coreContracts);
    }

    it("registers {$module->name} through a ModuleServiceProvider", function () use ($module) {
        expect(class_exists($module->providerClass()))->toBeTrue()
            ->and(is_subclass_of($module->providerClass(), ModuleServiceProvider::class))->toBeTrue();
    });

    it("keeps {$module->name} migrations on its reserved date prefix", function () use ($module) {
        $prefix = config("modules.migration_prefixes.{$module->name}");

        expect($prefix)->toBeString();

        foreach (glob($module->path('database/migrations').DIRECTORY_SEPARATOR.'*.php') ?: [] as $migration) {
            expect(basename($migration))->toStartWith($prefix.'_');
        }
    });
}

arch('shared support code does not depend on modules except Core contracts')
    ->expect('App\\Support')
    ->not->toUse('App\\Modules')
    ->ignoring($coreContracts);

// One expectation per helper: ->expect([...])->not->toBeUsedIn('App') would
// only fail when App used all of them at once.
arch('application code ships without debugging helpers')
    ->expect('App')
    ->not->toUse(['dd', 'dump', 'ray', 'var_dump', 'die']);
