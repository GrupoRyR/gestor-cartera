<?php

namespace App\Modules\Core\Console\Migracion;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

/**
 * Base of the core:migrar-* commands, which import the history of the
 * legacy systems from CSV exports (see README.md in this folder).
 *
 * Options: --dir (the folder with the exports, required) and --dry-run. The
 * command opens every file it declares in files() that is in the folder,
 * checking its header first: a broken file stops it before anything is
 * written. Then import() runs inside one transaction, committed at the end
 * or rolled back on --dry-run or on an error, so a dry run counts exactly
 * what a real run would save. Rejected rows do not make the command fail.
 */
abstract class MigrationCommand extends Command
{
    private ConnectionInterface $database;

    /**
     * The files the command reads, by name (fase1_* or supabase_*), with the
     * lowercase columns each must have, in the order they are imported.
     *
     * @return array<string, list<string>>
     */
    abstract protected function files(): array;

    /**
     * Columns a file may have, by file name; read as empty when it lacks them.
     *
     * @return array<string, list<string>>
     */
    protected function optionalColumns(): array
    {
        return [];
    }

    /**
     * Checks that the files found are enough to import something, before
     * anything is written (e.g. a file that needs another one).
     *
     * @param  array<string, CsvFile>  $files
     *
     * @throws InvalidCsvFile With the message to show when they are not.
     */
    protected function checkFiles(array $files): void {}

    /**
     * Imports the rows of $files, usually with eachRow().
     *
     * @param  array<string, CsvFile>  $files  The declared files found in the folder, by name.
     */
    abstract protected function import(array $files, ImportReport $report): void;

    public function handle(ConnectionInterface $database): int
    {
        $this->database = $database;
        $folder = $this->folder();

        if ($folder === null) {
            return self::FAILURE;
        }

        try {
            $files = $this->open($folder);

            if ($files === []) {
                throw new InvalidCsvFile("No encontré ninguno de estos archivos en {$folder}: ".implode(', ', array_keys($this->files())).'.');
            }

            $this->checkFiles($files);
        } catch (InvalidCsvFile $problem) {
            $this->error($problem->getMessage());
            $this->line('No se importó nada.');

            return self::FAILURE;
        }

        $report = new ImportReport;
        $database->beginTransaction();

        try {
            $this->import($files, $report);
        } catch (Throwable $error) {
            $database->rollBack();
            report($error);
            $this->error('La migración se detuvo por un error y no se guardó nada: '.$error->getMessage());

            return self::FAILURE;
        }

        $this->dryRun() ? $database->rollBack() : $database->commit();
        $this->render($report);

        return self::SUCCESS;
    }

    /**
     * Runs $import on each row of $file and counts the outcome: true when it
     * saved something, false (or RowSkipped, with its reason) when there was
     * nothing to save. Each row runs in its own savepoint: a row that is
     * unreadable, or that $import refuses with RowRejected, leaves nothing
     * behind and is counted as rejected with its reason; the next row goes on.
     *
     * @param  Closure(CsvRow): bool  $import
     */
    protected function eachRow(CsvFile $file, ImportReport $report, Closure $import): void
    {
        $report->opened($file);

        foreach ($file->rows() as $row) {
            try {
                $row->assertReadable();
                $saved = $this->database->transaction(fn (): bool => $import($row));
            } catch (RowRejected $rejection) {
                $report->rejected($row, $rejection->getMessage());

                continue;
            } catch (RowSkipped $skip) {
                $report->skipped($row, $skip->getMessage());

                continue;
            }

            $saved ? $report->imported($row) : $report->skipped($row);
        }
    }

    protected function dryRun(): bool
    {
        return (bool) $this->option('dry-run');
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    protected function getOptions(): array
    {
        return [
            ['dir', null, InputOption::VALUE_REQUIRED, 'Carpeta con los CSV exportados de los sistemas anteriores'],
            ['dry-run', null, InputOption::VALUE_NONE, 'Hace todo y muestra el reporte, pero no guarda nada'],
        ];
    }

    private function folder(): ?string
    {
        $folder = $this->option('dir');

        if (! is_string($folder) || trim($folder) === '') {
            $this->error('Indica la carpeta de los archivos con --dir=<carpeta>.');

            return null;
        }

        if (! is_dir($folder)) {
            $this->error("La carpeta {$folder} no existe.");

            return null;
        }

        return rtrim($folder, '/\\');
    }

    /**
     * @return array<string, CsvFile>
     *
     * @throws InvalidCsvFile
     */
    private function open(string $folder): array
    {
        $files = [];
        $missing = [];

        foreach ($this->files() as $name => $columns) {
            $path = $folder.DIRECTORY_SEPARATOR.$name;

            if (is_file($path)) {
                $files[$name] = CsvFile::open($path, $columns, $this->optionalColumns()[$name] ?? []);
            } else {
                $missing[] = $name;
            }
        }

        if ($files !== []) {
            foreach ($missing as $name) {
                $this->warn("No encontré {$name} en la carpeta; sigo con los demás archivos.");
            }
        }

        return $files;
    }

    private function render(ImportReport $report): void
    {
        $totals = [];

        foreach ($report->totals() as $file => $counts) {
            $totals[] = [LegacySource::ofFile($file)->label(), $file, $counts['read'], $counts['imported'], $counts['skipped'], $counts['rejected']];
        }

        $this->newLine();
        $this->table(['Origen', 'Archivo', 'Leídos', 'Importados', 'Omitidos', 'Rechazados'], $totals);

        $skipped = [];

        foreach ($report->skipReasons() as $file => $reasons) {
            foreach ($reasons as $reason => $count) {
                $skipped[] = [$file, $reason, $count];
            }
        }

        if ($skipped !== []) {
            $this->newLine();
            $this->line('Omitidos por motivo:');
            $this->table(['Archivo', 'Motivo', 'Filas'], $skipped);
        }

        $this->entries('Filas rechazadas (no se importaron):', 'Motivo', $report->rejections());
        $this->entries('Avisos (la fila se importó u omitió, con esta salvedad):', 'Aviso', $report->warnings());

        $this->newLine();

        if ($this->dryRun()) {
            $this->warn('Modo de prueba: no se guardó nada.');
        } else {
            $this->info('Listo: los cambios quedaron guardados.');
        }
    }

    /**
     * @param  list<array{file: string, line: int, id: string, reason: string}>  $entries
     */
    private function entries(string $title, string $reasonHeader, array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $this->newLine();
        $this->line($title);
        $this->table(
            ['Archivo', 'Línea', 'Id legado', $reasonHeader],
            array_map(fn (array $entry): array => [$entry['file'], $entry['line'], $entry['id'], $entry['reason']], $entries),
        );
    }
}
