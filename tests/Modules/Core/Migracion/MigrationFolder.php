<?php

namespace Tests\Modules\Core\Migracion;

use Illuminate\Support\Facades\File;

/**
 * Temporary folders of legacy CSV exports for the migration commands, under
 * the system temp directory. Remove each one after the test.
 */
final class MigrationFolder
{
    /**
     * The headers of the tables of the import report.
     */
    public const array TOTALS = ['Origen', 'Archivo', 'Leídos', 'Importados', 'Omitidos', 'Rechazados'];

    public const array SKIPPED = ['Archivo', 'Motivo', 'Filas'];

    public const array REJECTIONS = ['Archivo', 'Línea', 'Id legado', 'Motivo'];

    public const array WARNINGS = ['Archivo', 'Línea', 'Id legado', 'Aviso'];

    /**
     * A new folder with a copy of each fixture in $fixtures (file names in
     * tests/Modules/Core/Migracion/fixtures).
     */
    public static function with(string ...$fixtures): string
    {
        $folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'migracion-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($folder);

        foreach ($fixtures as $fixture) {
            copy(__DIR__.DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.$fixture, $folder.DIRECTORY_SEPARATOR.$fixture);
        }

        return $folder;
    }

    /**
     * Writes $bytes, exactly as given, to $name in $folder and returns its path.
     */
    public static function put(string $folder, string $name, string $bytes): string
    {
        $path = $folder.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, $bytes);

        return $path;
    }

    public static function remove(string $folder): void
    {
        File::deleteDirectory($folder);
    }
}
