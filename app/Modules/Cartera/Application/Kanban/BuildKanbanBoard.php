<?php

namespace App\Modules\Cartera\Application\Kanban;

use App\Modules\Cartera\Domain\Kanban\KanbanBoard;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\Data\ClientQuery;

/**
 * Reads every client within the user's scope, the largest overdue amount
 * first, and lays them out in the Kanban columns.
 */
final class BuildKanbanBoard
{
    public function __construct(private readonly CarteraRepository $cartera) {}

    public function handle(int $cardsPerColumn): KanbanBoard
    {
        return KanbanBoard::fromClients(
            $this->cartera->allClients(new ClientQuery(sort: 'overdue', direction: 'desc')),
            $cardsPerColumn,
        );
    }
}
