<?php

namespace App\Modules\Bancos\Domain\Statement;

/**
 * A data row the reader could not turn into a movement.
 */
final readonly class RowError
{
    /**
     * @param  int  $row  1-based row number in the file, as the user sees it in Excel.
     * @param  string  $message  Spanish text for the user.
     */
    public function __construct(
        public int $row,
        public string $message,
    ) {}
}
