<?php

namespace App\Modules\Cartera\Domain\Kanban;

use App\Modules\Core\Contracts\Data\ClientSummary;
use App\Modules\Core\Contracts\Enums\ClientStatus;

/**
 * The eight columns of the Kanban board, one per client status and in the
 * order of ClientStatus (the order of the collection workflow).
 */
final readonly class KanbanBoard
{
    /**
     * "Al día" and "Pago recibido" hold the clients that need no work, so
     * they start hidden (Part B, module 2, item 3).
     */
    private const array HIDDEN_BY_DEFAULT = [ClientStatus::Current, ClientStatus::PaymentReceived];

    /**
     * @param  list<KanbanColumn>  $columns
     */
    private function __construct(public array $columns) {}

    /**
     * Groups the clients by status in one pass. Counts and totals cover every
     * client; only the first $cardsPerColumn of each status become cards, so
     * the page stays light however large the portfolio is.
     *
     * @param  iterable<ClientSummary>  $clients
     */
    public static function fromClients(iterable $clients, int $cardsPerColumn): self
    {
        $counts = [];
        $totals = [];
        $cards = [];

        foreach (ClientStatus::cases() as $status) {
            $counts[$status->value] = 0;
            $totals[$status->value] = 0.0;
            $cards[$status->value] = [];
        }

        foreach ($clients as $client) {
            $key = $client->status->value;
            $counts[$key]++;
            $totals[$key] += $client->balance;

            if (count($cards[$key]) < $cardsPerColumn) {
                $cards[$key][] = $client;
            }
        }

        return new self(array_map(
            fn (ClientStatus $status): KanbanColumn => new KanbanColumn(
                status: $status,
                count: $counts[$status->value],
                total: $totals[$status->value],
                cards: $cards[$status->value],
                hiddenByDefault: in_array($status, self::HIDDEN_BY_DEFAULT, true),
            ),
            ClientStatus::cases(),
        ));
    }

    public function column(ClientStatus $status): KanbanColumn
    {
        foreach ($this->columns as $column) {
            if ($column->status === $status) {
                return $column;
            }
        }

        // Unreachable: there is a column for every case.
        throw new \LogicException("The board has no column for [{$status->value}].");
    }
}
