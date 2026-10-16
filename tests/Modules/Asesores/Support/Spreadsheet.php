<?php

namespace Tests\Modules\Asesores\Support;

use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Opens the Excel file a download returned and reads what is in it, so the
 * tests check the cells a person would see.
 */
final class Spreadsheet
{
    /**
     * The sheets of the file the response carries: name => rows, the title
     * row first.
     *
     * @return array<string, list<list<mixed>>>
     */
    public static function of(TestResponse $response): array
    {
        $file = $response->baseResponse;

        if (! $file instanceof BinaryFileResponse) {
            throw new RuntimeException('The response is not a file download.');
        }

        return self::read($file->getFile()->getPathname());
    }

    /**
     * @return array<string, list<list<mixed>>>
     */
    public static function read(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);
        $sheets = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];

            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }

            $sheets[$sheet->getName()] = $rows;
        }

        $reader->close();

        return $sheets;
    }

    /**
     * One column of a sheet without its title, as text.
     *
     * @param  list<list<mixed>>  $rows
     * @return list<mixed>
     */
    public static function column(array $rows, int $index): array
    {
        return array_map(fn (array $row): mixed => $row[$index] ?? null, array_slice($rows, 1));
    }
}
