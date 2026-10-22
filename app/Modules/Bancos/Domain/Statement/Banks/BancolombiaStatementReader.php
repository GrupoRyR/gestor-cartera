<?php

namespace App\Modules\Bancos\Domain\Statement\Banks;

use App\Modules\Bancos\Domain\Statement\BankProfile;
use App\Modules\Bancos\Domain\Statement\Cells;
use App\Modules\Bancos\Domain\Statement\Column;
use App\Modules\Bancos\Domain\Statement\NumberStyle;
use App\Modules\Bancos\Domain\Statement\TabularStatementReader;
use App\Modules\Core\Contracts\Enums\Bank;

/**
 * Bancolombia. Layout of the real export (sample of October 2026): a .csv
 * with commas and no header row, nine columns, every row a movement. The
 * columns are read by position (zero-based):
 *
 *   0 account | 1 office code | 2 unknown (always 1) | 3 date | 4 document
 *   (empty, or a zero-padded number such as a cheque's) | 5 amount |
 *   6 transaction code | 7 description | 8 unknown (always 0)
 *
 * The amount is signed (debits negative), with a dot for decimals and no
 * thousands separator. Dates are ddmmaaaa without separators. There is no
 * currency column (the account is in pesos) and no unique movement id; the
 * document number is the reference.
 */
final class BancolombiaStatementReader extends TabularStatementReader
{
    protected function bankProfile(): BankProfile
    {
        return new BankProfile(
            bank: Bank::Bancolombia,
            headers: [],
            dateFormats: ['dmY'],
            numbers: NumberStyle::International,
            positions: [
                Column::Account->value => 0,
                Column::Date->value => 3,
                Column::Reference->value => 4,
                Column::Amount->value => 5,
                Column::Description->value => 7,
            ],
        );
    }

    /**
     * A file opened and saved in Excel turns 01102026 into the number
     * 1102026: seven digits are a date that lost its leading zero.
     */
    protected function dateCell(mixed $cell): mixed
    {
        $text = Cells::text($cell);

        return $text !== null && preg_match('/^\d{7}$/', $text) === 1 ? '0'.$text : $cell;
    }
}
