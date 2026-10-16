<?php

use App\Modules\Cartera\Domain\Kanban\KanbanBoard;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use Tests\Fakes\Make;

it('has one column per status, in the order of the statuses', function () {
    $board = KanbanBoard::fromClients([], 10);

    expect(array_map(fn ($column) => $column->status, $board->columns))->toBe(ClientStatus::cases())
        ->and($board->columns)->toHaveCount(8);
});

it('puts every client in the column of its status', function () {
    $overdue = Make::client(name: 'Vencido', status: ClientStatus::Overdue, balance: 100.0);
    $legal = Make::client(name: 'Jurídico', status: ClientStatus::Legal, balance: 50.0);

    $board = KanbanBoard::fromClients([$overdue, $legal], 10);

    expect($board->column(ClientStatus::Overdue)->cards)->toBe([$overdue])
        ->and($board->column(ClientStatus::Legal)->cards)->toBe([$legal])
        ->and($board->column(ClientStatus::Current)->cards)->toBe([]);
});

it('adds up the balance of every client of a column', function () {
    $board = KanbanBoard::fromClients([
        Make::client(status: ClientStatus::Overdue, balance: 1_500.0),
        Make::client(status: ClientStatus::Overdue, balance: 2_500.0),
        Make::client(status: ClientStatus::Committed, balance: 700.0),
    ], 10);

    expect($board->column(ClientStatus::Overdue)->total)->toBe(4_000.0)
        ->and($board->column(ClientStatus::Overdue)->count)->toBe(2)
        ->and($board->column(ClientStatus::Committed)->total)->toBe(700.0);
});

it('keeps the first cards of a column and still counts the rest', function () {
    $clients = [];

    foreach (range(1, 5) as $step) {
        $clients[] = Make::client(name: "Cliente {$step}", status: ClientStatus::Overdue, balance: 100.0);
    }

    $column = KanbanBoard::fromClients($clients, 3)->column(ClientStatus::Overdue);

    expect($column->cards)->toHaveCount(3)
        ->and(array_map(fn ($card) => $card->name, $column->cards))->toBe(['Cliente 1', 'Cliente 2', 'Cliente 3'])
        ->and($column->count)->toBe(5)
        ->and($column->total)->toBe(500.0)
        ->and($column->hasMore())->toBeTrue()
        ->and($column->remaining())->toBe(2);
});

it('does not say there is more when everything fits', function () {
    $column = KanbanBoard::fromClients([Make::client(status: ClientStatus::Overdue)], 3)->column(ClientStatus::Overdue);

    expect($column->hasMore())->toBeFalse()->and($column->remaining())->toBe(0);
});

it('hides "Al día" and "Pago recibido" until the user asks for them', function () {
    $board = KanbanBoard::fromClients([], 10);

    $hidden = array_values(array_map(
        fn ($column) => $column->status,
        array_filter($board->columns, fn ($column) => $column->hiddenByDefault),
    ));

    expect($hidden)->toBe([ClientStatus::Current, ClientStatus::PaymentReceived]);
});

it('reads clients lazily, one pass, from any iterable', function () {
    $generator = (function () {
        yield Make::client(status: ClientStatus::Overdue, balance: 10.0);
        yield Make::client(status: ClientStatus::Overdue, balance: 20.0);
    })();

    expect(KanbanBoard::fromClients($generator, 10)->column(ClientStatus::Overdue)->total)->toBe(30.0);
});
