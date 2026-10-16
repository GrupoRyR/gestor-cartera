<?php

namespace App\Modules\Cartera\Application\Clients;

use App\Modules\Cartera\Infrastructure\Mail\ClientReplies;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\CompromisoService;
use App\Modules\Core\Contracts\Data\Commitment;
use App\Modules\Core\Contracts\Data\CommitmentQuery;
use App\Modules\Core\Contracts\GestionLogger;

/**
 * Gathers the client file from the Core contracts and the mailbox reader;
 * each one applies the user's scope, so a client out of reach is not found.
 */
final class LoadClientFile
{
    private const int LOG_ENTRIES = 50;

    private const int COMMITMENTS = 50;

    private const int REPLIES = 10;

    public function __construct(
        private readonly CarteraRepository $cartera,
        private readonly GestionLogger $log,
        private readonly CompromisoService $commitments,
        private readonly ClientReplies $replies,
    ) {}

    /**
     * Null when the client does not exist or is out of the user's reach.
     */
    public function handle(int $clientId): ?ClientFile
    {
        $detail = $this->cartera->client($clientId);

        if ($detail === null) {
            return null;
        }

        /** @var list<Commitment> $commitments */
        $commitments = array_values($this->commitments->paginate(
            new CommitmentQuery(clientId: $clientId, sort: 'due_date', direction: 'desc'),
            self::COMMITMENTS,
        )->items());

        return new ClientFile(
            detail: $detail,
            invoices: $this->cartera->openInvoices($clientId),
            activities: $this->log->forClient($clientId, self::LOG_ENTRIES),
            commitments: $commitments,
            replies: $this->replies->forClient($clientId, self::REPLIES),
            repliesAvailable: $this->replies->available(),
        );
    }
}
