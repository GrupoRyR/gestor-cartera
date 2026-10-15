<?php

use App\Modules\Core\Contracts\Data\CommitmentChanges;
use App\Modules\Core\Contracts\Data\CommitmentQuery;

it('builds a commitment query with sensible defaults', function () {
    $query = new CommitmentQuery;

    expect($query->clientId)->toBeNull()
        ->and($query->statuses)->toBe([])
        ->and($query->companies)->toBe([])
        ->and($query->sort)->toBe('due_date')
        ->and($query->direction)->toBe('asc');
});

it('documents the commitment sorts', function () {
    expect(CommitmentQuery::SORTS)->toBe(['due_date', 'amount', 'created_at']);
});

it('accepts every documented commitment sort', function (string $sort) {
    expect((new CommitmentQuery(sort: $sort, direction: 'desc'))->sort)->toBe($sort);
})->with(CommitmentQuery::SORTS);

it('rejects an unknown commitment sort', function () {
    expect(fn () => new CommitmentQuery(sort: 'client'))
        ->toThrow(InvalidArgumentException::class, 'client');
});

it('rejects an unknown commitment sort direction', function () {
    expect(fn () => new CommitmentQuery(direction: 'DESC '))
        ->toThrow(InvalidArgumentException::class);
});

it('leaves every field unchanged by default', function () {
    $changes = new CommitmentChanges;

    expect($changes->dueDate)->toBeNull()
        ->and($changes->amount)->toBeNull()
        ->and($changes->notes)->toBeNull();
});
