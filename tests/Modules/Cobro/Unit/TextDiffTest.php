<?php

use App\Modules\Cobro\Domain\Templates\TextDiff;

it('keeps every line of equal texts', function () {
    expect(TextDiff::lines("Buen día\n\nGracias", "Buen día\n\nGracias"))->toBe([
        ['type' => 'same', 'line' => 'Buen día'],
        ['type' => 'same', 'line' => ''],
        ['type' => 'same', 'line' => 'Gracias'],
    ]);
});

it('marks a changed line as removed and then added', function () {
    expect(TextDiff::lines("Buen día\nPaga en la cuenta 123\nGracias", "Buen día\nPaga en la cuenta 999\nGracias"))->toBe([
        ['type' => 'same', 'line' => 'Buen día'],
        ['type' => 'removed', 'line' => 'Paga en la cuenta 123'],
        ['type' => 'added', 'line' => 'Paga en la cuenta 999'],
        ['type' => 'same', 'line' => 'Gracias'],
    ]);
});

it('marks added and removed lines in their place', function () {
    expect(TextDiff::lines("uno\ndos\ntres", "cero\nuno\ntres\ncuatro"))->toBe([
        ['type' => 'added', 'line' => 'cero'],
        ['type' => 'same', 'line' => 'uno'],
        ['type' => 'removed', 'line' => 'dos'],
        ['type' => 'same', 'line' => 'tres'],
        ['type' => 'added', 'line' => 'cuatro'],
    ]);
});

it('shows every line as added or removed when one side is empty', function () {
    expect(TextDiff::lines('', "uno\ndos"))->toBe([
        ['type' => 'added', 'line' => 'uno'],
        ['type' => 'added', 'line' => 'dos'],
    ])->and(TextDiff::lines("uno\ndos", ''))->toBe([
        ['type' => 'removed', 'line' => 'uno'],
        ['type' => 'removed', 'line' => 'dos'],
    ])->and(TextDiff::lines('', ''))->toBe([]);
});

it('keeps the order of both texts across several changes', function () {
    $diff = TextDiff::lines("a\nb\nc\nd\ne", "a\nx\nc\ne\nf");

    expect($diff)->toBe([
        ['type' => 'same', 'line' => 'a'],
        ['type' => 'removed', 'line' => 'b'],
        ['type' => 'added', 'line' => 'x'],
        ['type' => 'same', 'line' => 'c'],
        ['type' => 'removed', 'line' => 'd'],
        ['type' => 'same', 'line' => 'e'],
        ['type' => 'added', 'line' => 'f'],
    ])
        ->and(array_column(array_filter($diff, fn (array $entry): bool => $entry['type'] !== 'added'), 'line'))->toBe(['a', 'b', 'c', 'd', 'e'])
        ->and(array_column(array_filter($diff, fn (array $entry): bool => $entry['type'] !== 'removed'), 'line'))->toBe(['a', 'x', 'c', 'e', 'f']);
});
