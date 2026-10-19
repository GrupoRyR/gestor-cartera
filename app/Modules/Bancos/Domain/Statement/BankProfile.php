<?php

namespace App\Modules\Bancos\Domain\Statement;

use App\Modules\Core\Contracts\Enums\Bank;

/**
 * Everything that makes one bank's statement different from another's: the
 * headers it uses, how it writes dates and amounts, the words it uses to tell
 * credits from debits and the currency of its account.
 *
 * The headers are compared as Cells::header() writes them (lowercase, without
 * accents or punctuation), so list them in that form.
 */
final readonly class BankProfile
{
    /** Words that mark a credit in a Type column, in Cells::header() form. */
    public const array CREDIT_WORDS = ['credito', 'cr', 'c', 'abono', 'deposito', 'consignacion', 'ingreso', 'credit', 'deposit'];

    /** Words that mark a debit in a Type column. */
    public const array DEBIT_WORDS = ['debito', 'db', 'd', 'cargo', 'retiro', 'egreso', 'debit', 'withdrawal'];

    /**
     * @param  array<string, list<string>>  $headers  Column::value => accepted headers.
     * @param  list<string>  $dateFormats  PHP date formats, tried in order.
     * @param  string  $currency  ISO 4217 code of the account, unless the file has a Currency column.
     */
    public function __construct(
        public Bank $bank,
        public array $headers,
        public array $dateFormats,
        public NumberStyle $numbers,
        public string $currency = 'COP',
    ) {}

    /**
     * The same profile for an account in another currency.
     */
    public function withCurrency(string $currency): self
    {
        return new self($this->bank, $this->headers, $this->dateFormats, $this->numbers, strtoupper($currency));
    }

    /**
     * The role of a header in this bank's statement, or null.
     */
    public function roleOf(string $header): ?Column
    {
        foreach ($this->headers as $role => $aliases) {
            if (in_array($header, $aliases, true)) {
                return Column::from($role);
            }
        }

        return null;
    }
}
