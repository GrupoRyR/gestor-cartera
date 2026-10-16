<?php

use App\Modules\Asesores\Application\Portfolio\HistoryDays;

it('takes the days from the query when they are one of the allowed ranges', function () {
    expect(HistoryDays::fromQuery(['dias' => '180'], [30, 60, 90, 180], 60))->toBe(180);
});

it('falls back to the default for anything else', function (mixed $value) {
    expect(HistoryDays::fromQuery(['dias' => $value], [30, 60, 90, 180], 60))->toBe(60);
})->with([
    'not offered' => ['45'],
    'text' => ['all'],
    'negative' => ['-30'],
    'array' => [['30']],
    'zero' => ['0'],
    'padded' => [' 30'],
]);

it('falls back to the default without the parameter', function () {
    expect(HistoryDays::fromQuery([], [30, 60], 30))->toBe(30);
});
