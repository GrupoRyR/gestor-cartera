<?php

namespace App\Modules\Bancos\Domain\Statement\Banks;

use App\Modules\Bancos\Domain\Statement\BankProfile;
use App\Modules\Bancos\Domain\Statement\Column;
use App\Modules\Bancos\Domain\Statement\NumberStyle;
use App\Modules\Bancos\Domain\Statement\TabularStatementReader;
use App\Modules\Core\Contracts\Enums\Bank;

/**
 * Banco de Bogotá. Layout of the real export (sample of October 2026): a .csv
 * with commas, in Windows-1252, the header in the first row and 18 columns:
 *
 *   Número de Cuenta | Nombre de Cuenta | Fecha de Transacción | Número de Items |
 *   Texto de Referencia | Subtipo de Producto | Moneda de Cuenta | ID de Banco |
 *   Nombre de Banco | Descripción de Transacción | Tipo de Transacción |
 *   Indicador Débito / Crédito | Valor | Monto en Efectivo | Monto en Cheque |
 *   Referencia del Cliente | Referencia del Banco | Fecha
 *
 * The date is Fecha (dd/mm/aaaa); Fecha de Transacción comes empty. Valor is
 * unsigned, with a dot for decimals and no thousands separator; the indicator
 * (C or D) says whether it is a credit or a debit. Tipo de Transacción is a
 * numeric code, not the direction. The description is Descripción de
 * Transacción followed by Texto de Referencia, which holds the payer, without
 * the bank's labels. Referencia del Banco is shared by a credit and the fees
 * and tax (GMF) charged on it, so it does not identify one movement.
 */
final class BogotaStatementReader extends TabularStatementReader
{
    protected function bankProfile(): BankProfile
    {
        return new BankProfile(
            bank: Bank::Bogota,
            headers: [
                Column::Date->value => ['fecha'],
                Column::Description->value => ['descripcion de transaccion'],
                Column::Detail->value => ['texto de referencia'],
                Column::Reference->value => ['referencia del banco'],
                Column::Account->value => ['numero de cuenta'],
                Column::Currency->value => ['moneda de cuenta'],
                Column::Amount->value => ['valor'],
                Column::Type->value => ['indicador debito credito'],
            ],
            dateFormats: ['d/m/Y'],
            numbers: NumberStyle::International,
        );
    }

    /**
     * Texto de Referencia without the labels the bank prepends ("Oficina
     * Receptora: 0940", "Otros detalles del movimiento:") and with a space
     * before a glued "Referencia 2:".
     */
    protected function cleanText(string $text): string
    {
        $text = (string) preg_replace('/Oficina Receptora:\s*\d*/iu', ' ', $text);
        $text = (string) preg_replace('/Otros detalles del movimiento:/iu', ' ', $text);
        $text = (string) preg_replace('/(?<=\S)(Referencia 2:)/u', ' $1', $text);

        return parent::cleanText($text);
    }
}
