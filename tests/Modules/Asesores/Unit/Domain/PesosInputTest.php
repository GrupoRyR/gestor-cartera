<?php

use App\Modules\Asesores\Domain\Format\PesosInput;

it('reads an amount the way people write it in Colombia', function (string $text, float $pesos) {
    expect(PesosInput::parse($text))->toBe($pesos);
})->with([
    'plain digits' => ['5000000', 5_000_000.0],
    'dots as thousands' => ['5.000.000', 5_000_000.0],
    'a single group of thousands' => ['5.000', 5_000.0],
    'with the currency sign' => ['$ 5.000.000', 5_000_000.0],
    'with the no-break space of the screens' => ["$\u{00A0}5.000.000", 5_000_000.0],
    'spaces as thousands' => ['1 500 000', 1_500_000.0],
    'comma as decimal' => ['1,5', 1.5],
    'thousands and decimals' => ['5.000.000,50', 5_000_000.5],
    'dot as decimal' => ['12.5', 12.5],
    'two decimals with a dot' => ['1500000.25', 1_500_000.25],
    'padding' => ['  150000  ', 150_000.0],
    'zero' => ['0', 0.0],
]);

it('rejects what is not an amount in pesos', function (string $text) {
    expect(PesosInput::parse($text))->toBeNull();
})->with([
    'empty' => [''],
    'blank' => ['   '],
    'text' => ['cinco millones'],
    'negative' => ['-100'],
    'mixed' => ['12abc'],
    'a dot that is neither thousands nor decimals' => ['1.234.567.89'],
    'three decimals' => ['10,123'],
    'scientific notation' => ['1e6'],
    'two decimal commas' => ['1,5,5'],
    'a comma before the thousands' => ['5,000.000'],
]);
