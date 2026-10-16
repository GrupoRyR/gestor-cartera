<?php

use App\Modules\Asesores\Domain\Client\InvoiceAging;
use App\Modules\Asesores\Domain\Client\Position;
use Tests\Fakes\Make;

it('puts an invoice in the same age bucket as Core does', function (int $days, string $key) {
    expect(InvoiceAging::key($days))->toBe($key);
})->with([
    'not due yet' => [-10, 'not_due'],
    'due today' => [0, 'not_due'],
    'one day late' => [1, 'days_1_30'],
    'thirty days late' => [30, 'days_1_30'],
    'thirty one' => [31, 'days_31_60'],
    'sixty' => [60, 'days_31_60'],
    'sixty one' => [61, 'days_61_90'],
    'ninety' => [90, 'days_61_90'],
    'ninety one' => [91, 'over_90'],
    'very late' => [400, 'over_90'],
]);

it('adds up the open invoices of a client by age', function () {
    $position = Position::of([
        Make::invoice(balance: 5_000_000.0, daysOverdue: 100),
        Make::invoice(balance: 2_000_000.0, daysOverdue: -10),
        Make::invoice(balance: 300_000.0, daysOverdue: 0),
        Make::invoice(balance: 700_000.0, daysOverdue: 45),
    ]);

    expect($position->balance)->toBe(8_000_000.0)
        ->and($position->overdue)->toBe(5_700_000.0)
        ->and($position->notDue)->toBe(2_300_000.0)
        ->and($position->aging->over90)->toBe(5_000_000.0)
        ->and($position->aging->days31To60)->toBe(700_000.0)
        ->and($position->aging->notDue)->toBe(2_300_000.0)
        ->and($position->aging->total())->toBe(8_000_000.0);
});

it('subtracts credit notes and advances, which sit in the bucket they fall in', function () {
    $position = Position::of([
        Make::invoice(balance: 1_000_000.0, daysOverdue: 20),
        Make::invoice(balance: -250_000.0, daysOverdue: 20),
    ]);

    expect($position->balance)->toBe(750_000.0)
        ->and($position->overdue)->toBe(750_000.0)
        ->and($position->aging->days1To30)->toBe(750_000.0);
});

it('sums cents exactly, without floating point drift', function () {
    $position = Position::of(array_map(
        fn () => Make::invoice(balance: 0.10, daysOverdue: 5),
        range(1, 30),
    ));

    expect($position->balance)->toBe(3.0)
        ->and($position->overdue)->toBe(3.0);
});

it('has nothing to add up without invoices', function () {
    $position = Position::of([]);

    expect($position->balance)->toBe(0.0)
        ->and($position->overdue)->toBe(0.0)
        ->and($position->notDue)->toBe(0.0)
        ->and($position->aging->total())->toBe(0.0);
});

it('measures the credit limit against the balance', function (?float $limit, float $balance, bool $has, float $available, float $exceeded) {
    $position = Position::of([Make::invoice(balance: $balance)], $limit);

    expect($position->hasCredit())->toBe($has)
        ->and($position->available())->toBe($available)
        ->and($position->exceeded())->toBe($exceeded);
})->with([
    'room left' => [10_000_000.0, 4_000_000.0, true, 6_000_000.0, 0.0],
    'exactly at the limit' => [10_000_000.0, 10_000_000.0, true, 0.0, 0.0],
    'over the limit' => [10_000_000.0, 12_500_000.0, true, 0.0, 2_500_000.0],
    'no limit' => [null, 4_000_000.0, false, 0.0, 0.0],
    'zero limit means none assigned' => [0.0, 4_000_000.0, false, 0.0, 0.0],
]);
