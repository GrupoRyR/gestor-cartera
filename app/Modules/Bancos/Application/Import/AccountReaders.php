<?php

namespace App\Modules\Bancos\Application\Import;

use App\Modules\Bancos\Domain\Statement\StatementReader;
use App\Modules\Bancos\Domain\Statement\StatementReaders;
use App\Modules\Core\Contracts\Enums\Bank;

/**
 * The reader of a bank's account, with the account currency set in the
 * configuration (bancos.currencies) when it differs from the one the reader
 * assumes.
 */
final class AccountReaders
{
    public function for(Bank $bank): StatementReader
    {
        $currency = config('bancos.currencies.'.$bank->value);

        return StatementReaders::for($bank, is_string($currency) && $currency !== '' ? $currency : null);
    }
}
