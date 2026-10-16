<?php

namespace App\Modules\Cartera\Application\Clients;

use App\Modules\Core\Contracts\Data\Activity;
use App\Modules\Core\Contracts\Data\ClientDetail;
use App\Modules\Core\Contracts\Data\Commitment;
use App\Modules\Core\Contracts\Data\InboundMail;
use App\Modules\Core\Contracts\Data\Invoice;

/**
 * Everything the client file shows about one client.
 */
final readonly class ClientFile
{
    /**
     * @param  list<Invoice>  $invoices  Open invoices, the oldest due date first.
     * @param  list<Activity>  $activities  The client's log, newest first.
     * @param  list<Commitment>  $commitments  Payment commitments, the latest due date first.
     * @param  list<InboundMail>  $replies  Replies and bounces, newest first.
     * @param  bool  $repliesAvailable  Whether a module reads the mailboxes at all.
     */
    public function __construct(
        public ClientDetail $detail,
        public array $invoices,
        public array $activities,
        public array $commitments,
        public array $replies,
        public bool $repliesAvailable,
    ) {}

    /**
     * Sum of the balance of the open invoices.
     */
    public function invoiceBalance(): float
    {
        return array_sum(array_map(fn (Invoice $invoice): float => $invoice->balance, $this->invoices));
    }
}
