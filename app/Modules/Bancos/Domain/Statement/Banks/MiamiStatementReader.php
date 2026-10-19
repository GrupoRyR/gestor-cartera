<?php

namespace App\Modules\Bancos\Domain\Statement\Banks;

use App\Modules\Bancos\Domain\Statement\BankProfile;
use App\Modules\Bancos\Domain\Statement\Column;
use App\Modules\Bancos\Domain\Statement\NumberStyle;
use App\Modules\Bancos\Domain\Statement\TabularStatementReader;
use App\Modules\Core\Contracts\Enums\Bank;

/**
 * Miami account. ASSUMED layout, to be confirmed with a real extract
 * (docs/prompt-gestor-cartera.md, Part D, item 3): English headers, deposits
 * and withdrawals in separate columns, dates as mm/dd/yyyy, US numbers
 * (1,234,567.89) and a dollar account (USD) unless the file says otherwise.
 *
 *   Date | Description | Reference | Deposits | Withdrawals | Balance
 */
final class MiamiStatementReader extends TabularStatementReader
{
    protected function bankProfile(): BankProfile
    {
        return new BankProfile(
            bank: Bank::Miami,
            headers: [
                Column::Date->value => ['date', 'transaction date', 'posting date'],
                Column::Description->value => ['description', 'details'],
                Column::Reference->value => ['reference', 'ref', 'check number'],
                Column::Account->value => ['account', 'account number'],
                Column::Currency->value => ['currency'],
                Column::Credit->value => ['deposits', 'deposit', 'credits', 'credit'],
                Column::Debit->value => ['withdrawals', 'withdrawal', 'debits', 'debit'],
            ],
            dateFormats: ['m/d/Y', 'Y-m-d'],
            numbers: NumberStyle::International,
            currency: 'USD',
        );
    }
}
