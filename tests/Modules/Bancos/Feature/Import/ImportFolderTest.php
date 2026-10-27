<?php

use App\Modules\Bancos\Application\Import\FolderImporter;
use App\Modules\Bancos\Domain\Movement\ImportSource;
use App\Modules\Bancos\Infrastructure\Persistence\BankLoad;
use App\Modules\Bancos\Infrastructure\Persistence\BankMovement;
use App\Modules\Core\Contracts\Enums\Bank;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Bancos\Support\Fixtures;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 06:30:00', 'America/Bogota'));
    ContractFakes::install($this->app);

    $this->folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bancos-entrada-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->folder);
    config()->set('bancos.import_path', $this->folder);
});

afterEach(function () {
    File::deleteDirectory($this->folder);
});

/**
 * Drops a copy of the invented statement of a bank in <company>/<bank>, old
 * enough to be taken, named extracto.<extension of the fixture> by default.
 */
function bancosDrop(string $folder, string $company, string $bankFolder, Bank $bank, ?string $name = null, int $age = 3600): string
{
    $name ??= 'extracto.'.pathinfo(Fixtures::path($bank), PATHINFO_EXTENSION);
    $directory = $folder.DIRECTORY_SEPARATOR.$company.DIRECTORY_SEPARATOR.$bankFolder;
    File::ensureDirectoryExists($directory);
    $path = $directory.DIRECTORY_SEPARATOR.$name;
    copy(Fixtures::path($bank), $path);
    touch($path, time() - $age);

    return $path;
}

it('imports the file of each company and bank folder, with the company and bank of its folder', function () {
    bancosDrop($this->folder, 'SGX', 'bogota', Bank::Bogota);
    bancosDrop($this->folder, 'SDC', 'davivienda', Bank::Davivienda);

    $this->artisan('bancos:importar-carpeta')->assertSuccessful();

    expect(BankMovement::query()->where('company', 'SGX')->where('bank', 'bogota')->count())->toBe(3)
        ->and(BankMovement::query()->where('company', 'SDC')->where('bank', 'davivienda')->count())->toBe(2)
        ->and(BankLoad::query()->pluck('source')->unique()->all())->toBe([ImportSource::Folder])
        ->and(glob($this->folder.'/procesados/2026-10/SDC-davivienda-*-extracto.xls'))->toHaveCount(1);
});

it('moves an imported file to procesados so it is not read again', function () {
    $path = bancosDrop($this->folder, 'SGX', 'bogota', Bank::Bogota);

    $this->artisan('bancos:importar-carpeta')->assertSuccessful();

    expect(file_exists($path))->toBeFalse()
        ->and(glob($this->folder.'/procesados/2026-10/SGX-bogota-*-extracto.csv'))->toHaveCount(1);

    $this->artisan('bancos:importar-carpeta')->assertSuccessful();

    expect(BankLoad::query()->count())->toBe(1);
});

it('accepts folder names in any case', function () {
    bancosDrop($this->folder, 'sgx', 'Bancolombia', Bank::Bancolombia);

    $this->artisan('bancos:importar-carpeta')->assertSuccessful();

    expect(BankMovement::query()->where('company', 'SGX')->where('bank', 'bancolombia')->count())->toBe(4);
});

it('says there is nothing to do when the folder is empty or missing', function () {
    $this->artisan('bancos:importar-carpeta')->expectsOutputToContain('No hay extractos')->assertSuccessful();

    config()->set('bancos.import_path', $this->folder.'/no-existe');

    $this->artisan('bancos:importar-carpeta')->expectsOutputToContain('No hay extractos')->assertSuccessful();
});

it('moves a file that is not a statement of that bank to errores, says why, and fails the command', function () {
    // The Miami statement dropped in the Davivienda folder.
    $path = bancosDrop($this->folder, 'SGX', 'davivienda', Bank::Miami);

    $this->artisan('bancos:importar-carpeta')->assertFailed();

    $moved = glob($this->folder.'/errores/SGX-davivienda-*-extracto.csv');

    expect(file_exists($path))->toBeFalse()
        ->and($moved)->toHaveCount(1)
        ->and(file_get_contents($moved[0].'.error.txt'))->toContain('Davivienda')
        ->and(BankMovement::query()->count())->toBe(0);

    $failed = BankLoad::query()->sole();

    expect($failed->failed)->toBeTrue()
        ->and($failed->file_name)->toBe('extracto.csv')
        ->and($failed->errors[0]['message'])->toContain('Davivienda');
});

it('shows a generic message, never the exception, when a file fails unexpectedly', function () {
    Exceptions::fake();
    $internal = "SQLSTATE[42S02]: Base table or view not found: 1146 Table 'cartera.bancos_movimientos' doesn't exist (/home/ryr/app)";

    // Something outside the module's own errors (the database, a bug): the
    // import of the statement throws before anything is stored.
    BankLoad::creating(function (BankLoad $load) use ($internal): void {
        if (! $load->failed) {
            throw new RuntimeException($internal);
        }
    });

    bancosDrop($this->folder, 'SGX', 'bogota', Bank::Bogota);

    $this->artisan('bancos:importar-carpeta')
        ->expectsOutputToContain(__('bancos::cargas.error_inesperado'))
        ->doesntExpectOutputToContain('SQLSTATE')
        ->assertFailed();

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === $internal);

    $failed = BankLoad::query()->sole();
    $moved = glob($this->folder.'/errores/SGX-bogota-*-extracto.csv');

    expect($failed->failed)->toBeTrue()
        ->and($failed->errors)->toBe([['row' => null, 'message' => __('bancos::cargas.error_inesperado')]])
        ->and($moved)->toHaveCount(1)
        ->and(file_get_contents($moved[0].'.error.txt'))->toBe(__('bancos::cargas.error_inesperado').PHP_EOL)
        ->and(BankMovement::query()->count())->toBe(0);
});

it('keeps importing the other files when one fails', function () {
    bancosDrop($this->folder, 'SGX', 'davivienda', Bank::Miami, 'malo.csv');
    bancosDrop($this->folder, 'SGX', 'bogota', Bank::Bogota, 'bueno.csv');

    $this->artisan('bancos:importar-carpeta')->assertFailed();

    expect(BankMovement::query()->count())->toBe(3);
});

it('moves an old binary .xls to errores and tells how to save it', function () {
    $directory = $this->folder.'/SGX/bogota';
    File::ensureDirectoryExists($directory);
    file_put_contents($directory.'/viejo.xls', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1");
    touch($directory.'/viejo.xls', time() - 3600);

    $this->artisan('bancos:importar-carpeta')->assertFailed();

    expect(BankLoad::query()->sole()->errors[0]['message'])->toContain('.xlsx');
});

it('leaves a file alone that changed in the last minute: it may still be copying', function () {
    $path = bancosDrop($this->folder, 'SGX', 'bogota', Bank::Bogota, age: 5);

    $this->artisan('bancos:importar-carpeta')->assertSuccessful();

    expect(file_exists($path))->toBeTrue()
        ->and(BankLoad::query()->count())->toBe(0);

    $this->artisan('bancos:importar-carpeta', ['--min-age' => 0])->assertSuccessful();

    expect(BankLoad::query()->count())->toBe(1);
});

it('reports the files in the wrong place instead of waiting for them forever', function () {
    copy(Fixtures::path(Bank::Bogota), $this->folder.'/suelto.csv');
    File::ensureDirectoryExists($this->folder.'/XYZ/bogota');
    File::ensureDirectoryExists($this->folder.'/SGX/banco-raro');
    touch($this->folder.'/SGX/banco-raro/a.csv');

    $outcomes = app(FolderImporter::class)->run();

    expect($outcomes)->toHaveCount(3)
        ->and(array_map(fn ($outcome) => $outcome->isImported() || $outcome->isFailed(), $outcomes))->toBe([false, false, false]);
    expect(file_exists($this->folder.'/suelto.csv'))->toBeTrue()
        ->and(BankLoad::query()->count())->toBe(0);
});

it('uses a relative path on the local disk', function () {
    config()->set('bancos.import_path', 'bancos/entrada');

    $root = str_replace('\\', '/', FolderImporter::root());

    expect($root)->toEndWith('storage/app/private/bancos/entrada');
});

it('is scheduled every morning in Bogotá', function () {
    config()->set('bancos.import_at', '06:30');

    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'bancos:importar-carpeta'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('30 6 * * *')
        ->and($events->first()->timezone)->toBe('America/Bogota');
});
