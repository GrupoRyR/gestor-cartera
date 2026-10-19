<?php

namespace App\Modules\Cobro\Domain\Runs;

use App\Modules\Core\Contracts\Data\ClientSummary;

/**
 * A client a run would mail: its contact e-mails and what the mail is
 * about (the amount and number of invoices it lists).
 */
final readonly class Candidate
{
    /**
     * @param  list<string>  $emails  Contact e-mails, primary first; empty = no address.
     * @param  float  $amount  Overdue total (weekly) or balance (circularization) of the mail.
     */
    public function __construct(
        public ClientSummary $client,
        public array $emails,
        public float $amount,
        public int $invoiceCount,
    ) {}
}
