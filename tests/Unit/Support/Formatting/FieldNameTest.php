<?php

use App\Support\Formatting\FieldName;

it('turns a form field name into the dot key of its validation errors', function () {
    expect(FieldName::toKey('correo'))->toBe('correo')
        ->and(FieldName::toKey('clientes[0][email]'))->toBe('clientes.0.email')
        ->and(FieldName::toKey('clientes[]'))->toBe('clientes')
        ->and(FieldName::toKey('filtros[estado][]'))->toBe('filtros.estado');
});

it('turns a form field name into an HTML id', function () {
    expect(FieldName::toId('correo'))->toBe('correo')
        ->and(FieldName::toId('fecha_pago'))->toBe('fecha_pago')
        ->and(FieldName::toId('clientes[0][email]'))->toBe('clientes-0-email')
        ->and(FieldName::toId('clientes[]'))->toBe('clientes');
});

it('adds a value to the id so every checkbox of a list gets its own', function () {
    expect(FieldName::toId('clientes[]', 'C-001'))->toBe('clientes-C-001')
        ->and(FieldName::toId('clientes[]', 'C_001'))->toBe('clientes-C_001')
        ->and(FieldName::toId('activo', '1'))->toBe('activo-1');
});

it('adds a hash of a value that is not id-safe so similar values keep distinct ids', function () {
    expect(FieldName::toId('clientes[]', 'C900.123'))->toBe('clientes-C900-123-'.hash('crc32b', 'C900.123'))
        ->and(FieldName::toId('estados[]', 'en gestión'))->toBe('estados-en-gesti-n-'.hash('crc32b', 'en gestión'))
        ->and(FieldName::toId('clientes[]', 'C900.123'))->not->toBe(FieldName::toId('clientes[]', 'C900-123'))
        ->and(FieldName::toId('clientes[]', 'C900/123'))->not->toBe(FieldName::toId('clientes[]', 'C900.123'))
        ->and(FieldName::toId('clientes[]', 'C900--123'))->not->toBe(FieldName::toId('clientes[]', 'C900-123'));
});

it('keeps every id within the characters an HTML id selector accepts', function (string $value) {
    expect(FieldName::toId('clientes[]', $value))->toMatch('/^[A-Za-z0-9_-]+$/');
})->with(['C900.123', 'en gestión', '...', 'a b', '-C-001-']);
