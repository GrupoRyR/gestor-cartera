<?php

namespace App\Modules\Bancos\Domain\Statement\Banks;

use App\Modules\Bancos\Domain\Statement\BankProfile;
use App\Modules\Bancos\Domain\Statement\Column;
use App\Modules\Bancos\Domain\Statement\NumberStyle;
use App\Modules\Bancos\Domain\Statement\TabularStatementReader;
use App\Modules\Core\Contracts\Enums\Bank;

/**
 * Davivienda. Layout of the real export (sample of October 2026): an .xls
 * that is really an HTML table (TabularFile reads it), with a "Movimientos"
 * title row, then the header with 17 columns:
 *
 *   Fecha | Doc. | Tran | Ofi. | Jor. | Hora | Mot. | Desc Mot. | Valor Total |
 *   Valor Cheque | ID Origen/Destino | Referencia 1 | Referencia 2 | Terminal |
 *   Fecha Movto. | Ciudad | Valor Saldo
 *
 * Tran says whether Valor Total is a credit (Notas Credito) or a debit (Notas
 * Debito). Valor Total is unsigned, with a dollar sign and Colombian numbers
 * (1.234.567,89); dates are dd/mm/aaaa. Desc Mot. holds the payer's NIT and
 * name. Doc. is the reference: one per movement, except the bank's own
 * charges, which share 99999999 or 99999998. The totals printed at the foot
 * have no date and are left out.
 */
final class DaviviendaStatementReader extends TabularStatementReader
{
    protected function bankProfile(): BankProfile
    {
        return new BankProfile(
            bank: Bank::Davivienda,
            headers: [
                Column::Date->value => ['fecha'],
                Column::Description->value => ['desc mot', 'descripcion motivo'],
                Column::Reference->value => ['doc', 'documento'],
                Column::Amount->value => ['valor total'],
                Column::Type->value => ['tran', 'transaccion'],
            ],
            dateFormats: ['d/m/Y'],
            numbers: NumberStyle::Colombian,
        );
    }
}
