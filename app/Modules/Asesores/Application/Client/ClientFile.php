<?php

namespace App\Modules\Asesores\Application\Client;

use App\Modules\Asesores\Domain\Client\Position;
use App\Modules\Core\Contracts\Data\Activity;
use App\Modules\Core\Contracts\Data\ClientDetail;
use App\Modules\Core\Contracts\Data\Commitment;
use App\Modules\Core\Contracts\Data\Invoice;

/**
 * Everything the client file shows, read through the Core contracts.
 */
final readonly class ClientFile
{
    /**
     * @param  list<Invoice>  $invoices  Open documents, oldest due date first.
     * @param  list<Commitment>  $commitments  The ones to act on first, then the closed ones.
     * @param  list<Activity>  $activity  The log, most recent first.
     */
    public function __construct(
        public ClientDetail $detail,
        public Position $position,
        public array $invoices,
        public array $commitments,
        public array $activity,
    ) {}
}
