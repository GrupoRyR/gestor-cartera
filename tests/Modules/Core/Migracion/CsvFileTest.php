<?php

use App\Modules\Core\Console\Migracion\CsvFile;
use App\Modules\Core\Console\Migracion\CsvRow;
use App\Modules\Core\Console\Migracion\InvalidCsvFile;
use App\Modules\Core\Console\Migracion\RowRejected;
use Tests\Modules\Core\Migracion\MigrationFolder;

beforeEach(function () {
    $this->folder = MigrationFolder::with();
});

afterEach(function () {
    MigrationFolder::remove($this->folder);
});

/**
 * @return list<CsvRow>
 */
function migracionRows(CsvFile $file): array
{
    return iterator_to_array($file->rows(), false);
}

/**
 * The rejection reason of $row, or null when it is readable.
 */
function migracionProblem(CsvRow $row): ?string
{
    try {
        $row->assertReadable();

        return null;
    } catch (RowRejected $rejection) {
        return $rejection->getMessage();
    }
}

it('reads a comma separated file with its line numbers', function () {
    $path = MigrationFolder::put($this->folder, 'fase1_users.csv', "id,nombre\n1,Laura\n2,Andrés\n");

    $rows = migracionRows(CsvFile::open($path, ['id', 'nombre']));

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->file)->toBe('fase1_users.csv')
        ->and($rows[0]->line)->toBe(2)
        ->and($rows[1]->string('nombre'))->toBe('Andrés')
        ->and($rows[1]->line)->toBe(3);
});

it('accepts a BOM, a semicolon delimiter, CRLF endings and loose header names', function () {
    $path = MigrationFolder::put($this->folder, 'fase1_users.csv', "\xEF\xBB\xBF ID ; Nombre ;Email\r\n1;Laura;laura@segurex.com\r\n");

    $rows = migracionRows(CsvFile::open($path, ['id', 'nombre', 'email']));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->legacyId())->toBe('1')
        ->and($rows[0]->string('nombre'))->toBe('Laura')
        ->and($rows[0]->email('email'))->toBe('laura@segurex.com');
});

it('drops the columns it was not asked for, so they are never read', function () {
    $path = MigrationFolder::put($this->folder, 'fase1_users.csv', "id,nombre,pass_hash\n1,Laura,secreto\n");

    $row = migracionRows(CsvFile::open($path, ['id', 'nombre']))[0];

    expect(fn () => $row->optionalString('pass_hash'))->toThrow(LogicException::class);
});

it('fails naming the missing columns', function () {
    $path = MigrationFolder::put($this->folder, 'fase1_users.csv', "id,nombre\n1,Laura\n");

    CsvFile::open($path, ['id', 'nombre', 'email', 'rol']);
})->throws(InvalidCsvFile::class, 'Al archivo fase1_users.csv le faltan columnas: email, rol.');

it('fails on an empty file', function () {
    $path = MigrationFolder::put($this->folder, 'fase1_users.csv', "\xEF\xBB\xBF\n");

    CsvFile::open($path, ['id']);
})->throws(InvalidCsvFile::class, 'El archivo fase1_users.csv está vacío');

it('fails when the header is not UTF-8', function () {
    $path = MigrationFolder::put($this->folder, 'fase1_users.csv', "id,dir\xE9ccion\n1,x\n");

    CsvFile::open($path, ['id']);
})->throws(InvalidCsvFile::class, 'El archivo fase1_users.csv no está en UTF-8');

it('marks a row that is not UTF-8 or has the wrong number of columns as unreadable', function () {
    $path = MigrationFolder::put($this->folder, 'fase1_users.csv', "id,nombre\n1,Jos\xE9\n2,Ana,extra\n3,Luis\n");

    $rows = migracionRows(CsvFile::open($path, ['id', 'nombre']));

    expect(array_map(migracionProblem(...), $rows))->toBe([
        'la fila no está en UTF-8: revisa que el archivo se exportó en utf-8',
        'la fila tiene 3 columnas y el encabezado 2',
        null,
    ])
        ->and($rows[0]->legacyId())->toBe('1')
        ->and($rows[1]->legacyId())->toBe('2')
        ->and(fn () => $rows[0]->string('nombre'))->toThrow(RowRejected::class);
});

it('skips blank lines and counts lines inside quoted values', function () {
    $path = MigrationFolder::put($this->folder, 'fase1_actions.csv', "id,detalle\n\n1,\"primera\nsegunda\"\n,\n2,otra\n");

    $rows = migracionRows(CsvFile::open($path, ['id', 'detalle']));

    expect(array_map(fn (CsvRow $row): int => $row->line, $rows))->toBe([3, 6])
        ->and($rows[0]->string('detalle'))->toBe("primera\nsegunda");
});

it('keeps backslashes as plain characters', function () {
    $path = MigrationFolder::put($this->folder, 'fase1_actions.csv', "id,detalle\n1,\"ruta C:\\\\carpeta\\\"\n");

    $rows = migracionRows(CsvFile::open($path, ['id', 'detalle']));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->string('detalle'))->toBe('ruta C:\\\\carpeta\\');
});
