<?php

namespace App\Modules\Bancos\Console;

use App\Modules\Bancos\Application\Import\FolderImporter;
use App\Modules\Bancos\Application\Import\FolderOutcome;
use Illuminate\Console\Command;

/**
 * bancos:importar-carpeta: imports the statements waiting in the import
 * folder. It runs every morning (the statements of the day before are there
 * by then) and can be run by hand after dropping a file.
 *
 * Exits with failure when a file could not be imported, so the scheduler and
 * the cPanel cron mail show it; the file and the reason are also in the
 * errores/ folder and in the load history of the app.
 */
final class ImportFolderCommand extends Command
{
    protected $signature = 'bancos:importar-carpeta
        {--min-age=60 : Segundos sin cambios que debe tener un archivo para tomarlo}';

    protected $description = 'Importa los extractos bancarios que hay en la carpeta de entrada';

    public function handle(FolderImporter $folder): int
    {
        $outcomes = $folder->run(max(0, (int) $this->option('min-age')));

        if ($outcomes === []) {
            $this->components->info('No hay extractos en la carpeta de entrada.');

            return self::SUCCESS;
        }

        foreach ($outcomes as $outcome) {
            $this->report($outcome);
        }

        $failed = count(array_filter($outcomes, fn (FolderOutcome $outcome): bool => $outcome->isFailed()));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function report(FolderOutcome $outcome): void
    {
        $name = basename($outcome->path);

        if ($outcome->result !== null) {
            $this->components->info(sprintf(
                '%s (%s, %s): %d nuevos, %d ya cargados, %d con error.',
                $name,
                $outcome->company?->value,
                $outcome->bank?->label(),
                $outcome->result->inserted(),
                $outcome->result->duplicates(),
                $outcome->result->errorCount(),
            ));

            return;
        }

        if ($outcome->isFailed()) {
            $this->components->error($name.': '.$outcome->problem);

            return;
        }

        $this->components->warn($name.': '.$outcome->problem);
    }
}
