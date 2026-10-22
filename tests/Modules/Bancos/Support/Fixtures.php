<?php

namespace Tests\Modules\Bancos\Support;

use App\Modules\Core\Contracts\Enums\Bank;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * The invented statements in tests/Modules/Bancos/fixtures (see the README
 * there) and a helper to write a statement as .xlsx.
 */
final class Fixtures
{
    /**
     * Path of the invented statement of a bank, with the extension its real
     * export has (Davivienda's is an HTML table saved as .xls).
     */
    public static function path(Bank $bank): string
    {
        $extension = $bank === Bank::Davivienda ? 'xls' : 'csv';

        return dirname(__DIR__).DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.$bank->value.'-ejemplo-inventado.'.$extension;
    }

    public static function contents(Bank $bank): string
    {
        return (string) file_get_contents(self::path($bank));
    }

    /**
     * Writes rows to a temporary .xlsx (strings, numbers and dates as given)
     * and returns its path; delete it with unlink().
     *
     * @param  list<list<mixed>>  $rows
     */
    public static function workbook(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bancos').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);

        foreach ($rows as $cells) {
            $writer->addRow(Row::fromValues($cells));
        }

        $writer->close();

        if (! is_file($path)) {
            throw new RuntimeException('The test workbook was not written.');
        }

        return $path;
    }
}
