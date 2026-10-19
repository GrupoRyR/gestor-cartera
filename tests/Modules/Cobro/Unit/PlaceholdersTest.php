<?php

use App\Modules\Cobro\Domain\Templates\Placeholders;

it('fills the known placeholders and leaves the rest', function () {
    $text = 'Hola, {cliente} ({nit}): debes {total_vencido}. {desconocido} {cliente}';

    expect(Placeholders::fill($text, ['cliente' => 'Ferretería', 'nit' => '900', 'total_vencido' => '$ 1']))
        ->toBe('Hola, Ferretería (900): debes $ 1. {desconocido} Ferretería');
});

it('names the placeholders that do not exist', function () {
    expect(Placeholders::unknown('{cliente} {Cliente} {saldo} {} {saldo}'))->toBe(['Cliente', 'saldo', ''])
        ->and(Placeholders::unknown('Sin datos {cliente} {empresa}'))->toBe([]);
});
