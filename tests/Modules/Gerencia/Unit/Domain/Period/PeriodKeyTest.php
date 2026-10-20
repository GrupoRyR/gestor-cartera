<?php

use App\Modules\Gerencia\Domain\Period\PeriodKey;

it('reads the period key from the request value', function (string $value, PeriodKey $expected) {
    expect(PeriodKey::fromRequest($value))->toBe($expected);
})->with([
    'month' => ['mes', PeriodKey::Month],
    'previous month' => ['mes_anterior', PeriodKey::PreviousMonth],
    'quarter' => ['trimestre', PeriodKey::Quarter],
    'year' => ['anio', PeriodKey::Year],
]);

it('falls back to the current month for unknown or missing values', function (?string $value) {
    expect(PeriodKey::fromRequest($value))->toBe(PeriodKey::Month);
})->with([
    'null' => [null],
    'empty' => [''],
    'unknown' => ['semana'],
    'wrong case' => ['MES'],
]);
