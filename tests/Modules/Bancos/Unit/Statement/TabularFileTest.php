<?php

use App\Modules\Bancos\Infrastructure\Files\TabularFile;
use App\Modules\Bancos\Infrastructure\Files\UnreadableFile;
use App\Modules\Core\Contracts\Enums\Bank;
use Tests\Modules\Bancos\Support\Fixtures;

/**
 * Writes content to a temporary file with the extension and returns the path.
 */
function bancosTempFile(string $content, string $extension = 'csv'): string
{
    $path = tempnam(sys_get_temp_dir(), 'bancos').'.'.$extension;
    file_put_contents($path, $content);

    return $path;
}

it('picks the delimiter that splits the lines evenly, even when amounts hold commas', function (string $content, array $expectedRow) {
    $path = bancosTempFile($content);

    try {
        $rows = TabularFile::rows($path);
    } finally {
        unlink($path);
    }

    expect($rows[1])->toBe($expectedRow);
})->with([
    'semicolons with Colombian decimals' => ["Fecha;Valor;Ref\n15/10/2026;1.234,50;A\n", ['15/10/2026', '1.234,50', 'A']],
    'commas with quoted amounts' => ["Fecha,Valor,Ref\n15/10/2026,\"1.234,50\",A\n", ['15/10/2026', '1.234,50', 'A']],
    'tabs' => ["Fecha\tValor\tRef\n15/10/2026\t1.234,50\tA\n", ['15/10/2026', '1.234,50', 'A']],
]);

it('reads a file saved by Excel in Windows-1252 as UTF-8', function () {
    $rows = TabularFile::rows(Fixtures::path(Bank::Bogota));

    expect($rows[3][1])->toBe('Descripción')
        ->and($rows[3][4])->toBe('Crédito');
});

it('drops the byte order mark', function () {
    $rows = TabularFile::rows(Fixtures::path(Bank::Bancolombia));

    expect($rows[0][0])->toStartWith('EXTRACTO');
});

it('keeps blank lines so row numbers match the ones the user sees', function () {
    $path = bancosTempFile("A;B\n\nC;D\n");

    try {
        $rows = TabularFile::rows($path);
    } finally {
        unlink($path);
    }

    expect($rows)->toBe([['A', 'B'], [], ['C', 'D']]);
});

it('reads the first sheet of an .xlsx and keeps numbers as numbers', function () {
    // A date without a date format reads back as its Excel serial number;
    // Cells::date() understands both that and the DateTimeInterface.
    $path = Fixtures::workbook([['Fecha', 'Valor'], [new DateTimeImmutable('2026-10-15'), 1500.5]]);

    try {
        $rows = TabularFile::rows($path);
    } finally {
        unlink($path);
    }

    expect($rows[0])->toBe(['Fecha', 'Valor'])
        ->and($rows[1][0])->toBeNumeric()
        ->and($rows[1][1])->toBe(1500.5);
});

it('explains what to do with an old .xls', function () {
    expect(fn () => TabularFile::rows('/tmp/extracto.xls'))->toThrow(UnreadableFile::class, '.xlsx');
});

it('refuses formats it cannot read', function () {
    expect(fn () => TabularFile::rows('/tmp/extracto.pdf'))->toThrow(UnreadableFile::class, '.pdf');
});

it('says so when an .xlsx is not a workbook', function () {
    $path = bancosTempFile('not a zip', 'xlsx');

    try {
        expect(fn () => TabularFile::rows($path))->toThrow(UnreadableFile::class);
    } finally {
        unlink($path);
    }
});
