<?php

use App\Modules\Core\Contracts\Data\CommitmentChanges;
use App\Modules\Core\Contracts\Data\CommitmentQuery;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use Tests\Fakes\Make;

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

it('derives the remaining balance of a partially paid commitment', function () {
    $commitment = Make::commitment(amount: 1_000_000.1, status: CommitmentStatus::PartiallyPaid, paidAmount: 333_333.33);

    expect($commitment->remaining())->toBe(666_666.77)
        ->and($commitment->paidAmount)->toBe(333_333.33);
});

it('has no remaining balance unless partially paid with a paid amount', function (CommitmentStatus $status, ?float $paid) {
    expect(Make::commitment(status: $status, paidAmount: $paid)->remaining())->toBeNull();
})->with([
    'pending' => [CommitmentStatus::Pending, null],
    'fulfilled with a stray amount' => [CommitmentStatus::Fulfilled, 100.0],
    'partially paid without an amount' => [CommitmentStatus::PartiallyPaid, null],
]);

it('leaves the partial payment empty by default', function () {
    $commitment = Make::commitment();

    expect($commitment->paidAmount)->toBeNull()
        ->and($commitment->partiallyPaidAt)->toBeNull();
});
