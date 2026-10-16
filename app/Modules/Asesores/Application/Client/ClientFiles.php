<?php

namespace App\Modules\Asesores\Application\Client;

use App\Modules\Asesores\Domain\Client\Position;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\CompromisoService;
use App\Modules\Core\Contracts\Data\ClientDetail;
use App\Modules\Core\Contracts\Data\Commitment;
use App\Modules\Core\Contracts\Data\CommitmentQuery;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\GestionLogger;

/**
 * Puts the client file together. The client came from
 * CarteraRepository::client(), so it is already inside the user's scope; the
 * invoices, the log and the commitments are read through the same
 * contracts and follow the same scope.
 */
final readonly class ClientFiles
{
    /**
     * Entries of the log and commitments shown on the file.
     */
    public const int LIMIT = 50;

    public function __construct(
        private CarteraRepository $cartera,
        private GestionLogger $log,
        private CompromisoService $commitments,
    ) {}

    public function open(ClientDetail $detail): ClientFile
    {
        $id = $detail->summary->id;
        $invoices = $this->cartera->openInvoices($id);

        return new ClientFile(
            detail: $detail,
            position: Position::of($invoices, $detail->creditLimit),
            invoices: $invoices,
            commitments: $this->commitmentsOf($id),
            activity: $this->log->forClient($id, self::LIMIT),
        );
    }

    /**
     * Pending and broken commitments first (the ones to act on), the soonest
     * due date on top; then the closed ones, the latest on top.
     *
     * @return list<Commitment>
     */
    private function commitmentsOf(int $clientId): array
    {
        $all = array_values($this->commitments->paginate(
            new CommitmentQuery(clientId: $clientId, sort: 'due_date', direction: 'desc'),
            self::LIMIT,
        )->items());

        $open = array_values(array_filter($all, fn (Commitment $commitment): bool => $this->isOpen($commitment)));
        $closed = array_values(array_filter($all, fn (Commitment $commitment): bool => ! $this->isOpen($commitment)));

        usort($open, fn (Commitment $a, Commitment $b): int => $a->dueDate <=> $b->dueDate ?: $a->id <=> $b->id);

        return [...$open, ...$closed];
    }

    private function isOpen(Commitment $commitment): bool
    {
        return in_array($commitment->status, [CommitmentStatus::Pending, CommitmentStatus::Broken], true);
    }
}
