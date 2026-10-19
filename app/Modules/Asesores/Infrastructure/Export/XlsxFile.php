<?php

namespace App\Modules\Asesores\Infrastructure\Export;

use DateTimeInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\Common\Entity\Sheet;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * An .xlsx workbook written to a temporary file with OpenSpout, which streams
 * the rows instead of holding the sheets in memory.
 *
 *     $file = XlsxFile::create();
 *     $file->sheet('Clientes', [Column::text('Cliente', 40), Column::money('Saldo')]);
 *     $file->row(['Ferretería El Tornillo', 1250000.0]);
 *     $path = $file->finish();
 *
 * Money and dates are real numbers and dates with a format, so the person can
 * sort, filter and add them up in Excel.
 */
final class XlsxFile
{
    private readonly Writer $writer;

    private bool $hasSheet = false;

    /**
     * @var list<Style|null> The style of each column of the current sheet.
     */
    private array $styles = [];

    private function __construct(private readonly string $path)
    {
        $this->writer = new Writer;
        $this->writer->openToFile($path);
    }

    public static function create(): self
    {
        $path = tempnam(sys_get_temp_dir(), 'asesores-');

        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file for the workbook.');
        }

        return new self($path);
    }

    /**
     * Starts a sheet with its title row. The first call names the sheet the
     * workbook already has; the next ones add another.
     *
     * @param  list<Column>  $columns
     */
    public function sheet(string $name, array $columns): void
    {
        $sheet = $this->hasSheet ? $this->writer->addNewSheetAndMakeItCurrent() : $this->writer->getCurrentSheet();
        $this->hasSheet = true;
        $sheet->setName($name);

        $this->styles = array_map(fn (Column $column): ?Style => match ($column->kind) {
            Column::MONEY => (new Style)->withFormat('#,##0'),
            Column::DATE => (new Style)->withFormat('dd/mm/yyyy'),
            Column::NUMBER => (new Style)->withFormat('0'),
            default => null,
        }, $columns);

        foreach ($columns as $index => $column) {
            $this->setWidth($sheet, $column->width, $index + 1);
        }

        $this->writer->addRow(Row::fromValuesWithStyle(
            array_map(fn (Column $column): string => $column->title, $columns),
            (new Style)->withFontBold(true),
        ));
    }

    /**
     * @param  list<string|int|float|bool|DateTimeInterface|null>  $values  One per column of the current sheet.
     */
    public function row(array $values): void
    {
        $cells = [];

        foreach ($values as $index => $value) {
            // OpenSpout turns any string that starts with "=" into a formula.
            // These strings come from SAP (client names, document numbers),
            // so they are always written as text: a name like
            // =HYPERLINK(...) must never run when the advisor opens the file.
            $cells[] = is_string($value) && $value !== ''
                ? new StringCell($value, $this->styles[$index] ?? null)
                : Cell::fromValue($value, $this->styles[$index] ?? null);
        }

        $this->writer->addRow(new Row($cells));
    }

    /**
     * Closes the workbook and gives the path of the file.
     */
    public function finish(): string
    {
        $this->writer->close();

        return $this->path;
    }

    private function setWidth(Sheet $sheet, float $width, int $column): void
    {
        $sheet->setColumnWidth($width, $column);
    }
}
