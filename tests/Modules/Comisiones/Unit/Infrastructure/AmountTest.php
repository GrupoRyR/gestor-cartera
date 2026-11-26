<?php

use App\Modules\Comisiones\Infrastructure\Captures\Amount;

it('gives the clean value of <x-ui.money-input>, with decimals only when there are cents', function (?string $stored, string $value) {
    expect(Amount::forMoneyInput($stored))->toBe($value);
})->with([
    'nothing' => [null, ''],
    'empty' => ['', ''],
    'whole pesos' => ['77241857.00', '77241857'],
    'round thousands' => ['100.00', '100'],
    'ten cents' => ['1200.10', '1200.1'],
    'one cent' => ['1200.01', '1200.01'],
    'no scale' => ['5000', '5000'],
    'more than two decimals' => ['10.005', '10.01'],
]);

it('keeps the Colombian format for the template', function () {
    expect(Amount::forInput('77241857.50'))->toBe('77.241.857,50');
});
