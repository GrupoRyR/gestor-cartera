<?php

use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'module-registry-'.uniqid();

    foreach (['Cartera', 'Bancos', 'Core', 'Zeta2', 'lowercase', '_Hidden', 'Has-Dash'] as $directory) {
        File::ensureDirectoryExists($this->root.DIRECTORY_SEPARATOR.$directory);
    }

    File::put($this->root.DIRECTORY_SEPARATOR.'Notes.php', '<?php');
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('lists Core first and the other modules in alphabetical order', function () {
    $registry = new ModuleRegistry($this->root);

    expect($registry->names())->toBe(['Core', 'Bancos', 'Cartera', 'Zeta2']);
});

it('ignores files and directories that are not module names', function () {
    $names = (new ModuleRegistry($this->root))->names();

    expect($names)->not->toContain('lowercase')
        ->and($names)->not->toContain('_Hidden')
        ->and($names)->not->toContain('Has-Dash')
        ->and($names)->not->toContain('Notes.php');
});

it('describes each module with its path, namespace and conventional class names', function () {
    $module = (new ModuleRegistry($this->root))->find('Cartera');

    $root = realpath($this->root.DIRECTORY_SEPARATOR.'Cartera');

    expect($module)->not->toBeNull()
        ->and($module->name)->toBe('Cartera')
        ->and($module->key())->toBe('cartera')
        ->and($module->path)->toBe($root)
        ->and($module->path())->toBe($root)
        ->and($module->path('routes/web.php'))->toBe($root.DIRECTORY_SEPARATOR.'routes'.DIRECTORY_SEPARATOR.'web.php')
        ->and($module->namespace)->toBe('App\\Modules\\Cartera')
        ->and($module->providerClass())->toBe('App\\Modules\\Cartera\\Providers\\CarteraServiceProvider')
        ->and($module->seederClass('PermissionSeeder'))->toBe('App\\Modules\\Cartera\\Database\\Seeders\\CarteraPermissionSeeder');
});

it('builds module namespaces from the given base namespace', function () {
    $module = (new ModuleRegistry($this->root, 'Tests\\Fixtures\\Modules'))->find('Bancos');

    expect($module?->namespace)->toBe('Tests\\Fixtures\\Modules\\Bancos');
});

it('returns null for a module that does not exist', function () {
    expect((new ModuleRegistry($this->root))->find('Inexistente'))->toBeNull();
});

it('returns no modules when the base directory is missing', function () {
    $registry = new ModuleRegistry($this->root.DIRECTORY_SEPARATOR.'missing');

    expect($registry->all())->toBe([])
        ->and($registry->names())->toBe([]);
});

it('scans the directory only once', function () {
    $registry = new ModuleRegistry($this->root);
    $registry->all();

    File::ensureDirectoryExists($this->root.DIRECTORY_SEPARATOR.'Asesores');

    expect($registry->names())->toBe(['Core', 'Bancos', 'Cartera', 'Zeta2']);
});
