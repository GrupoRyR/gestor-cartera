<?php

namespace App\Modules\Cartera\Domain\Kanban;

use App\Modules\Core\Contracts\Data\ClientSummary;
use App\Modules\Core\Contracts\Enums\ClientStatus;

/**
 * One column of the Kanban board: all the clients in a status, of which only
 * the first ones travel as cards.
 */
final readonly class KanbanColumn
{
    /**
     * @param  int  $count  Every client in the status, cards or not.
     * @param  float  $total  Sum of the balance of every client in the status.
     * @param  list<ClientSummary>  $cards  The clients drawn as cards, in the order they were read.
     * @param  bool  $hiddenByDefault  Closed statuses start out of view.
     */
    public function __construct(
        public ClientStatus $status,
        public int $count,
        public float $total,
        public array $cards,
        public bool $hiddenByDefault,
    ) {}

    public function hasMore(): bool
    {
        return $this->remaining() > 0;
    }

    /**
     * Clients of the status that are not drawn as cards.
     */
    public function remaining(): int
    {
        return $this->count - count($this->cards);
    }
}
