<?php

namespace App\Modules\Bancos\Domain\Statement;

use App\Modules\Core\Contracts\Enums\Bank;

/**
 * Turns the rows of one bank's statement into normalized movements. One
 * implementation per bank, so a quirk of a real extract is fixed in one
 * place; the importer only knows this interface.
 */
interface StatementReader
{
    public function bank(): Bank;

    /**
     * ISO 4217 code of the account, for movements that do not say.
     */
    public function currency(): string;

    /**
     * @param  list<list<mixed>>  $rows  Raw cells of the first sheet or the CSV, one list per row: strings,
     *                                   numbers, DateTimeInterface or null.
     *
     * @throws UnrecognizedStatement When the columns of this bank are not in the file.
     */
    public function read(array $rows): StatementReading;
}
