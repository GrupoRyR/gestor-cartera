<?php

namespace App\Modules\Bancos\Domain\Statement;

use App\Modules\Bancos\Domain\Statement\Banks\BancolombiaStatementReader;
use App\Modules\Bancos\Domain\Statement\Banks\BogotaStatementReader;
use App\Modules\Bancos\Domain\Statement\Banks\DaviviendaStatementReader;
use App\Modules\Bancos\Domain\Statement\Banks\MiamiStatementReader;
use App\Modules\Core\Contracts\Enums\Bank;

/**
 * The reader of each bank. Adding a bank is a case in Core's Bank enum plus
 * a class here.
 */
final class StatementReaders
{
    /**
     * @param  string|null  $currency  ISO code of the account when it is not the bank's usual one.
     */
    public static function for(Bank $bank, ?string $currency = null): StatementReader
    {
        return match ($bank) {
            Bank::Bogota => new BogotaStatementReader($currency),
            Bank::Davivienda => new DaviviendaStatementReader($currency),
            Bank::Bancolombia => new BancolombiaStatementReader($currency),
            Bank::Miami => new MiamiStatementReader($currency),
        };
    }
}
