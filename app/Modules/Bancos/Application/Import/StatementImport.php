<?php

namespace App\Modules\Bancos\Application\Import;

use App\Modules\Bancos\Domain\Movement\ImportSource;
use App\Modules\Bancos\Domain\Statement\StatementReaders;
use App\Modules\Bancos\Domain\Statement\UnrecognizedStatement;
use App\Modules\Bancos\Infrastructure\Files\TabularFile;
use App\Modules\Bancos\Infrastructure\Files\UnreadableFile;
use App\Modules\Core\Contracts\Enums\Bank;
use App\Modules\Core\Contracts\Enums\CompanyCode;

/**
 * Imports a statement file: opens it, picks the reader of the bank and hands
 * the movements to the importer. The company and the bank come from where the
 * file was dropped or what the person chose, never from the file itself.
 */
final class StatementImport
{
    public function __construct(private readonly MovementImporter $importer) {}

    /**
     * @param  string|null  $fileName  Name to show in the history; the base name of the path by default.
     * @param  string|null  $extension  Without dot; taken from the file name when null.
     *
     * @throws UnreadableFile When the file cannot be opened.
     * @throws UnrecognizedStatement When it is not a statement of that bank.
     */
    public function fromFile(
        string $path,
        Bank $bank,
        CompanyCode $company,
        ImportSource $source,
        ?string $fileName = null,
        ?int $userId = null,
        ?string $extension = null,
    ): ImportResult {
        $fileName ??= basename($path);
        $rows = TabularFile::rows($path, $extension ?? pathinfo($fileName, PATHINFO_EXTENSION));
        $reading = StatementReaders::for($bank, $this->currencyOf($bank))->read($rows);

        return $this->importer->import($bank, $company, $reading, $source, $fileName, hash_file('sha256', $path) ?: null, $userId);
    }

    /**
     * The account currency set in the configuration, when it differs from the
     * one the reader of the bank assumes.
     */
    private function currencyOf(Bank $bank): ?string
    {
        $currency = config('bancos.currencies.'.$bank->value);

        return is_string($currency) && $currency !== '' ? $currency : null;
    }
}
