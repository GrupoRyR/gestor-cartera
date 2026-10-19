<?php

namespace App\Modules\Bancos\Domain\Statement\Banks;

use App\Modules\Bancos\Domain\Statement\BankProfile;
use App\Modules\Bancos\Domain\Statement\Column;
use App\Modules\Bancos\Domain\Statement\NumberStyle;
use App\Modules\Bancos\Domain\Statement\TabularStatementReader;
use App\Modules\Core\Contracts\Enums\Bank;

/**
 * Bancolombia. ASSUMED layout, to be confirmed with a real extract
 * (docs/prompt-gestor-cartera.md, Part D, item 3): a single signed amount
 * column (debits negative), dates as aaaa/mm/dd, plain numbers with a dot for
 * decimals (-1234567.00).
 *
 *   FECHA | DESCRIPCIÓN | SUCURSAL | REFERENCIA 1 | VALOR | SALDO
 */
final class BancolombiaStatementReader extends TabularStatementReader
{
    protected function bankProfile(): BankProfile
    {
        return new BankProfile(
            bank: Bank::Bancolombia,
            headers: [
                Column::Date->value => ['fecha'],
                Column::Description->value => ['descripcion', 'detalle'],
                Column::Reference->value => ['referencia 1', 'referencia'],
                Column::Account->value => ['cuenta', 'numero de cuenta'],
                Column::Amount->value => ['valor'],
            ],
            dateFormats: ['Y/m/d', 'Y-m-d', 'd/m/Y'],
            numbers: NumberStyle::International,
        );
    }
}
