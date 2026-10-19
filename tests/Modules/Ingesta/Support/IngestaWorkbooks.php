<?php

namespace Tests\Modules\Ingesta\Support;

use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * .xlsx files built at run time like the SAP "Cartera del día" export, in
 * a temporary directory (no binary fixtures in the repository). Call
 * cleanup() after each test.
 */
final class IngestaWorkbooks
{
    /**
     * The 34 headers of the SAP export, in its order: the cartera query
     * without SlpCode, Email asesor, Base gravable and Saldo base.
     */
    public const array SAP_HEADERS = [
        'Código SN', 'Nombre SN', 'Nit', 'Grupo', 'Municipio', 'Cuenta visible', 'Tipo doc', 'Documento',
        'Documento base', 'Referencia 1', 'No. referencia', 'Empleado ventas / compras', 'Total plazos', 'No. plazo',
        'Condicion pago documento', 'Fecha contabilización', 'Fecha vencimiento', 'Fecha documento', 'Días',
        'Límite crédito', 'Importe original', 'Saldo vencido', 'Valor abonado', 'Sin vencer',
        'Valor cheques aplicados', '0-30', '31-60', '61-90', '91-120', '121-150', '151-180', '> 180', 'Saldo', 'Rango',
    ];

    /** @var list<string> */
    private static array $paths = [];

    /**
     * A row of the SAP export as header => value, with the given overrides.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function sapRow(array $overrides = []): array
    {
        return array_merge([
            'Código SN' => 'C001',
            'Nombre SN' => 'Ferretería Ñandú SAS',
            'Nit' => 900123456.0,
            'Grupo' => 'Distribuidores',
            'Municipio' => 'Bogotá',
            'Cuenta visible' => 13050505.0,
            'Tipo doc' => 13.0,
            'Documento' => 9280.0,
            'Documento base' => '9280',
            'Referencia 1' => '9280',
            'No. referencia' => null,
            'Empleado ventas / compras' => 'Carlos Pérez',
            'Total plazos' => 0.0,
            'No. plazo' => 0.0,
            'Condicion pago documento' => '30 días',
            'Fecha contabilización' => '2026-09-01',
            'Fecha vencimiento' => '2026-10-01',
            'Fecha documento' => '2026-09-01',
            'Días' => 999.0,
            'Límite crédito' => 50000000.0,
            'Importe original' => 1500000.0,
            'Saldo vencido' => 1000000.5,
            'Valor abonado' => null,
            'Sin vencer' => 0.0,
            'Valor cheques aplicados' => null,
            '0-30' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '91-120' => 0.0, '121-150' => 0.0, '151-180' => 0.0, '> 180' => 0.0,
            'Saldo' => 1000000.5,
            'Rango' => 'x',
        ], $overrides);
    }

    /**
     * A one-sheet workbook with title rows, the header row and the data
     * rows, as SAP exports it.
     *
     * @param  list<array<string, mixed>>  $rows  header => value, e.g. from sapRow().
     * @param  list<string>|null  $headers  Defaults to SAP_HEADERS.
     * @param  list<list<mixed>>  $before  Rows above the header row.
     */
    public static function sap(array $rows, ?array $headers = null, array $before = [['Cartera del día'], []], string $sheet = 'Datos'): string
    {
        return self::write([$sheet => self::table($rows, $headers, $before)]);
    }

    /**
     * The header row and the data rows of a sheet.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>|null  $headers
     * @param  list<list<mixed>>  $before
     * @return list<list<mixed>>
     */
    public static function table(array $rows, ?array $headers = null, array $before = []): array
    {
        $headers ??= self::SAP_HEADERS;

        return [
            ...$before,
            $headers,
            ...array_map(fn (array $row): array => array_map(fn (string $header): mixed => $row[$header] ?? null, $headers), $rows),
        ];
    }

    /**
     * A workbook with one sheet per entry, in order.
     *
     * @param  array<string, list<list<mixed>>>  $sheets  Sheet name => rows of cell values.
     */
    public static function write(array $sheets): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ingesta-xlsx-').'.xlsx';
        self::$paths[] = $path;

        $writer = new Writer;
        $writer->openToFile($path);
        $first = true;

        foreach ($sheets as $name => $rows) {
            $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName($name);
            $first = false;

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }
        }

        $writer->close();

        return $path;
    }

    /**
     * The workbook as an upload of the form.
     */
    public static function upload(string $path, string $name = 'cartera-del-dia.xlsx'): UploadedFile
    {
        return new UploadedFile($path, $name, null, null, true);
    }

    public static function cleanup(): void
    {
        foreach (self::$paths as $path) {
            @unlink($path);
            @unlink(substr($path, 0, -5));
        }

        self::$paths = [];
    }
}
