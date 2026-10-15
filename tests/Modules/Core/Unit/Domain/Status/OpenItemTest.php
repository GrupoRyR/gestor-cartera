<?php

use App\Modules\Core\Domain\Status\OpenItem;
use Carbon\CarbonImmutable;

it('counts calendar days from the due date to the cutoff', function (string $dueAt, string $cutoff, int $expected) {
    $item = OpenItem::fromDates(
        250_000.0,
        CarbonImmutable::parse($dueAt, 'America/Bogota'),
        CarbonImmutable::parse($cutoff, 'America/Bogota'),
    );

    expect($item->daysOverdue)->toBe($expected)
        ->and($item->balance)->toBe(250_000.0);
})->with([
    'past due' => ['2026-10-01', '2026-10-16', 15],
    'due on the cutoff' => ['2026-10-15', '2026-10-15', 0],
    'not yet due' => ['2026-10-20', '2026-10-14', -6],
    'across a leap day' => ['2028-02-28', '2028-03-01', 2],
    'a full year late' => ['2025-10-15', '2026-10-15', 365],
]);

it('ignores the time of day on both dates', function (string $dueAt, string $cutoff, int $expected) {
    $item = OpenItem::fromDates(
        1_000.0,
        CarbonImmutable::parse($dueAt, 'America/Bogota'),
        CarbonImmutable::parse($cutoff, 'America/Bogota'),
    );

    expect($item->daysOverdue)->toBe($expected);
})->with([
    'due late at night, cutoff just after midnight' => ['2026-10-01 23:59:59', '2026-10-02 00:00:01', 1],
    'due at midnight, cutoff late the same day' => ['2026-10-01 00:00:00', '2026-10-01 23:59:59', 0],
    'due late at night, cutoff early the day before' => ['2026-10-02 23:00:00', '2026-10-01 01:00:00', -1],
]);

it('keeps a credit note balance as given', function () {
    $item = OpenItem::fromDates(
        -80_000.0,
        CarbonImmutable::parse('2026-09-01', 'America/Bogota'),
        CarbonImmutable::parse('2026-10-15', 'America/Bogota'),
    );

    expect($item->balance)->toBe(-80_000.0)
        ->and($item->daysOverdue)->toBe(44);
});
