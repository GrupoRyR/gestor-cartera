<?php

use App\Modules\Cartera\Domain\Dashboard\OverdueRisk;

it('grades the overdue share of the portfolio', function (float $percent, OverdueRisk $risk) {
    expect(OverdueRisk::forPercent($percent))->toBe($risk);
})->with([
    'nothing overdue' => [0.0, OverdueRisk::Low],
    'right at the lower limit' => [15.0, OverdueRisk::Low],
    'just above the lower limit' => [15.1, OverdueRisk::Medium],
    'right at the upper limit' => [30.0, OverdueRisk::Medium],
    'just above the upper limit' => [30.1, OverdueRisk::High],
    'everything overdue' => [100.0, OverdueRisk::High],
]);

it('maps each grade to a component tone', function (OverdueRisk $risk, string $tone) {
    expect($risk->tone())->toBe($tone);
})->with([
    [OverdueRisk::Low, 'success'],
    [OverdueRisk::Medium, 'warning'],
    [OverdueRisk::High, 'danger'],
]);
