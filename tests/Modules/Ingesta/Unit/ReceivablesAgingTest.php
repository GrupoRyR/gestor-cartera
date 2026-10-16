<?php

use App\Modules\Ingesta\Domain\Manual\ReceivablesAging;

it('counts days from the due date to the cutoff', function (mixed $due, ?int $days) {
    expect(ReceivablesAging::days($due, '2026-10-15'))->toBe($days);
})->with([
    'overdue' => ['2026-10-01', 14],
    'due on the cutoff' => ['2026-10-15', 0],
    'not due yet' => ['2026-11-14', -30],
    'across a leap day' => ['2024-02-28', 960],
    'not a date' => ['01/10/2026', null],
    'impossible date' => ['2026-02-30', null],
    'null' => [null, null],
]);

it('puts the whole saldo in one bucket like the cartera query', function (int $days, string $bucket, string $range) {
    $columns = ReceivablesAging::columns('1000.50', $days);
    $buckets = ['sin_vencer', 'd_0_30', 'd_31_60', 'd_61_90', 'd_91_120', 'd_121_150', 'd_151_180', 'd_mas_180'];

    expect($columns['dias'])->toBe($days)
        ->and($columns['saldo_vencido'])->toBe('1000.50')
        ->and($columns['rango'])->toBe($range);

    foreach ($buckets as $name) {
        expect($columns[$name])->toBe($name === $bucket ? '1000.50' : '0.00');
    }
})->with([
    [-1, 'sin_vencer', 'Sin vencer'],
    [0, 'd_0_30', '0-30'],
    [30, 'd_0_30', '0-30'],
    [31, 'd_31_60', '31-60'],
    [60, 'd_31_60', '31-60'],
    [61, 'd_61_90', '61-90'],
    [90, 'd_61_90', '61-90'],
    [91, 'd_91_120', '91-120'],
    [120, 'd_91_120', '91-120'],
    [121, 'd_121_150', '121-150'],
    [150, 'd_121_150', '121-150'],
    [151, 'd_151_180', '151-180'],
    [180, 'd_151_180', '151-180'],
    [181, 'd_mas_180', '> 180'],
    [4000, 'd_mas_180', '> 180'],
]);

it('leaves the derived amounts null without days or a readable saldo', function (mixed $saldo, ?int $days) {
    $columns = ReceivablesAging::columns($saldo, $days);

    expect($columns['dias'])->toBe($days)
        ->and(array_filter(array_diff_key($columns, ['dias' => true]), fn (mixed $value): bool => $value !== null))->toBe([]);
})->with([
    ['1000.50', null],
    ['1.000,50', 10],
    [null, 10],
]);
