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

    expect($rows[0][2])->toBe('Fecha de Transacción')
        ->and($rows[0][11])->toBe('Indicador Débito / Crédito')
        ->and($rows[0])->toHaveCount(18)
        ->and($rows[3])->toHaveCount(18); // a quoted text with a comma stays one cell
});

it('drops the byte order mark', function () {
    $path = bancosTempFile("\xEF\xBB\xBFFecha;Valor\n15/10/2026;1.000,00\n");

    try {
        $rows = TabularFile::rows($path);
    } finally {
        unlink($path);
    }

    expect($rows[0][0])->toBe('Fecha');
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

describe('an .xls that is an HTML table, as some bank portals export', function () {
    it('reads every row of the document, in order, as the texts of its cells', function () {
        $html = "<!-- invented -->\r\n<table><tr /></table>\r\n<TABLE border=\"1\">\r\n"
            ."  <tr><td colspan=\"3\"><STRONG>Movimientos</STRONG></td></tr>\r\n"
            ."  <TR>\r\n    <TD><STRONG>Fecha</STRONG></TD>\r\n    <TD><STRONG>Desc Mot.</STRONG></TD>\r\n    <TD><STRONG>Referencia 1</STRONG></TD>\r\n  </TR>\r\n"
            ."  <TR><TD align=\"right\">15/10/2026</TD><TD align=\"right\"> Abono   ACH\r\n EJEMPLO</TD><TD>'0000000900111222</TD></TR>\r\n"
            ."  <TR><TD>16/10/2026</TD><TD>Consignación</TD><TD>'</TD></TR>\r\n"
            ."  <tr><td height=\"20\" /></tr>\r\n"
            ."</TABLE>\r\n";
        $path = bancosTempFile($html, 'xls');

        try {
            $rows = TabularFile::rows($path);
        } finally {
            unlink($path);
        }

        expect($rows)->toBe([
            [],
            ['Movimientos'],
            ['Fecha', 'Desc Mot.', 'Referencia 1'],
            ['15/10/2026', 'Abono ACH EJEMPLO', '0000000900111222'],
            ['16/10/2026', 'Consignación', ''],
            [''],
        ]);
    });

    it('keeps the accents of a table saved in Windows-1252, with or without a byte order mark', function (string $prefix, string $encoding) {
        $path = bancosTempFile($prefix.mb_convert_encoding('<table><tr><th>Transacción</th><td>Débito</td></tr></table>', $encoding, 'UTF-8'), 'xls');

        try {
            $rows = TabularFile::rows($path);
        } finally {
            unlink($path);
        }

        expect($rows)->toBe([['Transacción', 'Débito']]);
    })->with([
        'Windows-1252' => ['', 'Windows-1252'],
        'UTF-8 with BOM' => ["\xEF\xBB\xBF  ", 'UTF-8'],
    ]);

    it('still explains what to do with an old binary .xls', function () {
        $path = bancosTempFile("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 64), 'xls');

        try {
            expect(fn () => TabularFile::rows($path))->toThrow(UnreadableFile::class, '.xlsx');
        } finally {
            unlink($path);
        }
    });
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
