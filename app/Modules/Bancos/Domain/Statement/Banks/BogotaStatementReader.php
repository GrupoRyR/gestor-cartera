<?php

namespace App\Modules\Bancos\Domain\Statement\Banks;

use App\Modules\Bancos\Domain\Statement\BankProfile;
use App\Modules\Bancos\Domain\Statement\Column;
use App\Modules\Bancos\Domain\Statement\NumberStyle;
use App\Modules\Bancos\Domain\Statement\TabularStatementReader;
use App\Modules\Core\Contracts\Enums\Bank;

/**
 * Banco de Bogotá. ASSUMED layout, to be confirmed with a real extract
 * (docs/prompt-gestor-cartera.md, Part D, item 3): credits and debits in
 * separate columns, dates as dd/mm/aaaa, Colombian numbers (1.234.567,89).
 *
 *   Fecha | Descripción | Referencia | Débito | Crédito | Saldo
 */
final class BogotaStatementReader extends TabularStatementReader
{
    protected function bankProfile(): BankProfile
    {
        return new BankProfile(
            bank: Bank::Bogota,
            headers: [
                Column::Date->value => ['fecha', 'fecha movimiento', 'fecha de movimiento', 'fecha transaccion'],
                Column::Description->value => ['descripcion', 'detalle', 'concepto'],
                Column::Reference->value => ['referencia', 'documento', 'n documento', 'no documento', 'numero de documento'],
                Column::Account->value => ['cuenta', 'numero de cuenta'],
                Column::Credit->value => ['credito', 'creditos', 'valor credito'],
                Column::Debit->value => ['debito', 'debitos', 'valor debito'],
            ],
            dateFormats: ['d/m/Y', 'Y-m-d', 'd-m-Y'],
            numbers: NumberStyle::Colombian,
        );
    }
}
