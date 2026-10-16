<?php

namespace App\Modules\Asesores\Application\Export;

/**
 * A workbook written to a temporary file, ready to download.
 */
final readonly class Workbook
{
    /**
     * @param  string  $path  The temporary .xlsx file.
     * @param  int  $clients  Rows of the clients sheet.
     * @param  int  $invoices  Rows of the invoices sheet.
     * @param  bool  $truncated  The invoices of some clients were left out: there were too many.
     */
    public function __construct(
        public string $path,
        public int $clients,
        public int $invoices,
        public bool $truncated,
    ) {}
}
