<?php

namespace App\Modules\Bancos\Application\Import;

use App\Modules\Bancos\Infrastructure\Persistence\BankLoad;

/**
 * What the importer did with one statement.
 */
final readonly class ImportResult
{
    public function __construct(
        public BankLoad $load,
    ) {}

    /** New credits stored. */
    public function inserted(): int
    {
        return $this->load->inserted;
    }

    /** Credits that were already loaded and were left as they were. */
    public function duplicates(): int
    {
        return $this->load->duplicates;
    }

    /** Rows with a date or an amount that could not be read, or dated in the future. */
    public function errorCount(): int
    {
        return $this->load->error_count;
    }

    public function hasErrors(): bool
    {
        return $this->load->error_count > 0;
    }
}
