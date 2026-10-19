<?php

namespace App\Modules\Bancos\Domain\Statement\Banks;

use App\Modules\Bancos\Domain\Statement\BankProfile;
use App\Modules\Bancos\Domain\Statement\Column;
use App\Modules\Bancos\Domain\Statement\NumberStyle;
use App\Modules\Bancos\Domain\Statement\TabularStatementReader;
use App\Modules\Core\Contracts\Enums\Bank;

/**
 * Davivienda. ASSUMED layout, to be confirmed with a real extract
 * (docs/prompt-gestor-cartera.md, Part D, item 3): one unsigned amount column
 * plus a column that says whether it is a credit or a debit, dates as
 * dd/mm/aaaa, Colombian numbers (1.234.567,89).
 *
 *   Fecha | Oficina | Descripción motivo | Documento | Transacción | Valor
 */
final class DaviviendaStatementReader extends TabularStatementReader
{
    protected function bankProfile(): BankProfile
    {
        return new BankProfile(
            bank: Bank::Davivienda,
            headers: [
                Column::Date->value => ['fecha', 'fecha de sistema', 'fecha sistema'],
                Column::Description->value => ['descripcion motivo', 'descripcion', 'motivo'],
                Column::Reference->value => ['documento', 'referencia', 'referencia 1'],
                Column::Account->value => ['cuenta', 'numero de cuenta'],
                Column::Amount->value => ['valor', 'valor total', 'monto'],
                Column::Type->value => ['transaccion', 'tipo', 'tipo de transaccion', 'naturaleza'],
            ],
            dateFormats: ['d/m/Y', 'Y/m/d', 'Y-m-d'],
            numbers: NumberStyle::Colombian,
        );
    }
}
