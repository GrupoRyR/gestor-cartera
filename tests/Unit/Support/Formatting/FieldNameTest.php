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
        ->and(FieldName::toId('estados[]', 'en gestión'))->toBe('estados-en-gesti-n')
        ->and(FieldName::toId('activo', '1'))->toBe('activo-1');
});
