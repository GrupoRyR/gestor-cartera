<?php

namespace App\Modules\Bancos\Domain\Statement;

/**
 * What a reader found in one statement: every movement it understood (credits
 * and debits), the rows it had to leave out and why.
 */
final readonly class StatementReading
{
    /**
     * @param  list<StatementMovement>  $movements  In file order.
     * @param  list<RowError>  $errors  Rows with a date or an amount that could not be read.
     * @param  int  $ignoredRows  Rows without a date or without an amount (totals, notes).
     */
    public function __construct(
        public array $movements,
        public array $errors = [],
        public int $ignoredRows = 0,
    ) {}

    /**
     * The movements that are money in, the only ones Cartera keeps.
     *
     * @return list<StatementMovement>
     */
    public function credits(): array
    {
        return array_values(array_filter(
            $this->movements,
            fn (StatementMovement $movement): bool => $movement->isCredit(),
        ));
    }

    public function debitCount(): int
    {
        return count($this->movements) - count($this->credits());
    }
}
