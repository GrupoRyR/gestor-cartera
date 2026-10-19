<?php

namespace App\Modules\Bancos\Application\Import;

use App\Modules\Bancos\Domain\Movement\ImportSource;
use App\Modules\Bancos\Domain\Statement\UnrecognizedStatement;
use App\Modules\Bancos\Infrastructure\Files\TabularFile;
use App\Modules\Bancos\Infrastructure\Files\UnreadableFile;
use App\Modules\Core\Contracts\Enums\Bank;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Imports the statements dropped in the import folder (config
 * bancos.import_path):
 *
 *     <folder>/<SGX|SDC>/<bogota|davivienda|bancolombia|miami>/<file>.(csv|txt|xlsx)
 *
 * The company and the bank come from the two folders, so a file needs no
 * naming rule. Once imported a file moves to procesados/<year-month>/; one
 * that cannot be imported moves to errores/ next to a .error.txt that says
 * why, and is also recorded as a failed load in the history. A file touched in
 * the last minutes is left alone: it may still be being copied.
 */
final class FolderImporter
{
    public const string PROCESSED = 'procesados';

    public const string FAILED = 'errores';

    public function __construct(
        private readonly StatementImport $import,
        private readonly MovementImporter $importer,
    ) {}

    /**
     * The folder as a path on disk: absolute paths are used as they are, a
     * relative one is looked up on the "local" disk.
     */
    public static function root(): string
    {
        $configured = (string) config('bancos.import_path');

        if (preg_match('#^(?:[A-Za-z]:[\\\\/]|/|\\\\\\\\)#', $configured) === 1) {
            return rtrim($configured, '\\/');
        }

        return rtrim(Storage::disk('local')->path($configured), '\\/');
    }

    /**
     * @param  int  $minAgeSeconds  Files modified more recently than this are left for the next run.
     * @return list<FolderOutcome>
     */
    public function run(int $minAgeSeconds = 60): array
    {
        $root = self::root();

        if (! is_dir($root)) {
            return [];
        }

        $outcomes = [];

        foreach ($this->entries($root) as $entry) {
            if (! $entry['valid']) {
                $outcomes[] = FolderOutcome::skipped($entry['path'], $entry['reason']);

                continue;
            }

            if (time() - (int) filemtime($entry['path']) < $minAgeSeconds) {
                $outcomes[] = FolderOutcome::skipped($entry['path'], 'El archivo cambió hace poco; se revisará en la próxima corrida.');

                continue;
            }

            $outcomes[] = $this->process($root, $entry['path'], $entry['company'], $entry['bank']);
        }

        return $outcomes;
    }

    /**
     * Every file under <root>/<company>/<bank>, in name order, and the files
     * in the wrong place (so they are reported instead of waiting forever).
     *
     * @return list<array{path: string, valid: true, company: CompanyCode, bank: Bank}|array{path: string, valid: false, reason: string}>
     */
    private function entries(string $root): array
    {
        $entries = [];

        foreach ($this->children($root) as $companyPath) {
            $name = basename($companyPath);

            if (in_array($name, [self::PROCESSED, self::FAILED], true)) {
                continue;
            }

            $company = CompanyCode::tryFrom(strtoupper($name));

            if (is_file($companyPath)) {
                $entries[] = ['path' => $companyPath, 'valid' => false, 'reason' => 'Falta la carpeta de la empresa y la del banco: usa <empresa>/<banco>/<archivo>.'];

                continue;
            }

            if ($company === null) {
                $entries[] = ['path' => $companyPath, 'valid' => false, 'reason' => 'La carpeta «'.$name.'» no es una empresa (SGX o SDC).'];

                continue;
            }

            foreach ($this->children($companyPath) as $bankPath) {
                $bank = Bank::tryFrom(strtolower(basename($bankPath)));

                if (is_file($bankPath)) {
                    $entries[] = ['path' => $bankPath, 'valid' => false, 'reason' => 'Falta la carpeta del banco: usa '.$name.'/<banco>/<archivo>.'];

                    continue;
                }

                if ($bank === null) {
                    $entries[] = ['path' => $bankPath, 'valid' => false, 'reason' => 'La carpeta «'.basename($bankPath).'» no es un banco ('.implode(', ', array_column(Bank::cases(), 'value')).').'];

                    continue;
                }

                foreach ($this->children($bankPath) as $file) {
                    if (is_file($file) && $this->isStatement($file)) {
                        $entries[] = ['path' => $file, 'valid' => true, 'company' => $company, 'bank' => $bank];
                    }
                }
            }
        }

        return $entries;
    }

    private function process(string $root, string $path, CompanyCode $company, Bank $bank): FolderOutcome
    {
        $sha = hash_file('sha256', $path) ?: null;

        try {
            $result = $this->import->fromFile($path, $bank, $company, ImportSource::Folder);
        } catch (UnreadableFile|UnrecognizedStatement $e) {
            return $this->fail($root, $path, $company, $bank, $e->getMessage(), $sha);
        } catch (Throwable $e) {
            report($e);

            return $this->fail($root, $path, $company, $bank, 'Error inesperado al leer el archivo: '.$e->getMessage(), $sha);
        }

        $this->move($path, $root.DIRECTORY_SEPARATOR.self::PROCESSED.DIRECTORY_SEPARATOR.now()->format('Y-m'), $company, $bank);

        return FolderOutcome::imported($path, $company, $bank, $result);
    }

    private function fail(string $root, string $path, CompanyCode $company, Bank $bank, string $message, ?string $sha): FolderOutcome
    {
        $this->importer->recordFailure($bank, $company, ImportSource::Folder, $message, basename($path), $sha);

        $moved = $this->move($path, $root.DIRECTORY_SEPARATOR.self::FAILED, $company, $bank);

        if ($moved !== null) {
            file_put_contents($moved.'.error.txt', $message.PHP_EOL);
        }

        return FolderOutcome::failed($path, $company, $bank, $message);
    }

    /**
     * Moves the file to $directory under a name that tells where it was and
     * when, so two files with the same name never overwrite each other.
     *
     * @return string|null The new path; null when it could not be moved.
     */
    private function move(string $path, string $directory, CompanyCode $company, Bank $bank): ?string
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return null;
        }

        $target = $directory.DIRECTORY_SEPARATOR.sprintf('%s-%s-%s-%s', $company->value, $bank->value, now()->format('Ymd-His'), basename($path));

        return @rename($path, $target) ? $target : null;
    }

    /**
     * @return list<string>
     */
    private function children(string $directory): array
    {
        $names = scandir($directory) ?: [];
        sort($names);

        return array_values(array_map(
            fn (string $name): string => $directory.DIRECTORY_SEPARATOR.$name,
            array_filter($names, fn (string $name): bool => ! str_starts_with($name, '.') && ! str_starts_with($name, '~$')),
        ));
    }

    private function isStatement(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), [...TabularFile::EXTENSIONS, 'xls'], true);
    }
}
