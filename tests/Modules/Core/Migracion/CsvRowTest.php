<?php

use App\Modules\Core\Console\Migracion\CsvRow;
use App\Modules\Core\Console\Migracion\RowRejected;

/*
 * The typed getters of a legacy CSV row. The app runs in America/Bogota
 * (UTC-5, no daylight saving time).
 */

function migracionRow(array $values): CsvRow
{
    return new CsvRow('archivo.csv', 7, $values);
}

it('reads a naive timestamp as Bogotá time', function () {
    $moment = migracionRow(['created_at' => '2025-03-01 10:00:00'])->timestamp('created_at');

    expect($moment->format('Y-m-d H:i:s P'))->toBe('2025-03-01 10:00:00 -05:00')
        ->and($moment->getTimezone()->getName())->toBe('America/Bogota');
});

it('converts a timestamp with an offset to Bogotá time', function (string $value, string $expected) {
    $moment = migracionRow(['created_at' => $value])->timestamp('created_at');

    expect($moment->format('Y-m-d H:i:s.u'))->toBe($expected)
        ->and($moment->getTimezone()->getName())->toBe('America/Bogota');
})->with([
    'short offset' => ['2025-03-01 15:00:00+00', '2025-03-01 10:00:00.000000'],
    'long offset' => ['2025-03-01 15:00:00+00:00', '2025-03-01 10:00:00.000000'],
    'zulu with T' => ['2025-03-01T15:00:00Z', '2025-03-01 10:00:00.000000'],
    'fraction and offset' => ['2025-03-01 15:00:00.123456+00', '2025-03-01 10:00:00.123456'],
    'long fraction' => ['2025-03-01T15:00:00.1234567+00:00', '2025-03-01 10:00:00.123456'],
    'other offset' => ['2025-03-01 12:00:00-03:00', '2025-03-01 10:00:00.000000'],
    'compact offset' => ['2025-03-01 20:30:00+0530', '2025-03-01 10:00:00.000000'],
    'no seconds' => ['2025-03-01 10:00', '2025-03-01 10:00:00.000000'],
    'day boundary' => ['2025-03-02 02:00:00+00', '2025-03-01 21:00:00.000000'],
]);

it('rejects an invalid timestamp with the column and the value', function (string $value) {
    migracionRow(['created_at' => $value])->timestamp('created_at');
})->with([
    '31/02/2024',
    '2024-02-31 10:00:00',
    '2024-01-01 24:00:00',
    '2024-01-01 10:60:00',
    '2024-01-01',
    'ayer',
])->throws(RowRejected::class, 'fecha inválida en created_at: ');

it('rejects the exact value of an invalid timestamp', function () {
    expect(fn () => migracionRow(['created_at' => '31/02/2024'])->timestamp('created_at'))
        ->toThrow(RowRejected::class, 'fecha inválida en created_at: 31/02/2024');
});

it('reads a strict date at the start of the day in Bogotá', function () {
    $date = migracionRow(['fecha' => '2024-02-29'])->date('fecha');

    expect($date->format('Y-m-d H:i:s'))->toBe('2024-02-29 00:00:00')
        ->and($date->getTimezone()->getName())->toBe('America/Bogota');
});

it('rejects a date that is not Y-m-d or does not exist', function (string $value) {
    migracionRow(['fecha' => $value])->date('fecha');
})->with(['2023-02-29', '2024-2-1', '01/02/2024', '0000-01-00', '2024-02-01 10:00:00'])
    ->throws(RowRejected::class, 'fecha inválida en fecha: ');

it('treats a MySQL zero date as an empty date', function (string $value) {
    $row = migracionRow(['fecha' => $value]);

    expect($row->optionalDate('fecha'))->toBeNull()
        ->and($row->optionalTimestamp('fecha'))->toBeNull()
        ->and(fn () => $row->date('fecha'))->toThrow(RowRejected::class, 'fecha vacía: fecha')
        ->and(fn () => $row->timestamp('fecha'))->toThrow(RowRejected::class, 'fecha vacía: fecha');
})->with(['0000-00-00', '0000-00-00 00:00:00', '0000-00-00 00:00:00.000000']);

it('still says a required date that is blank is an empty field', function () {
    migracionRow(['fecha' => ''])->timestamp('fecha');
})->throws(RowRejected::class, 'campo vacío: fecha');

it('treats empty values and literal NULL as missing', function (string $value) {
    $row = migracionRow(['nombre' => $value, 'fecha' => $value, 'valor' => $value]);

    expect($row->optionalString('nombre'))->toBeNull()
        ->and($row->optionalDate('fecha'))->toBeNull()
        ->and($row->optionalDecimal('valor'))->toBeNull()
        ->and(fn () => $row->string('nombre'))->toThrow(RowRejected::class, 'campo vacío: nombre');
})->with(['', '   ', 'NULL']);

it('trims strings', function () {
    expect(migracionRow(['nombre' => '  Laura  '])->string('nombre'))->toBe('Laura');
});

it('normalizes a valid e-mail and rejects an invalid one', function () {
    expect(migracionRow(['email' => ' Laura.Restrepo@Segurex.COM '])->email('email'))->toBe('laura.restrepo@segurex.com')
        ->and(fn () => migracionRow(['email' => 'laura@'])->email('email'))
        ->toThrow(RowRejected::class, 'correo inválido en email: laura@');
});

it('reads booleans in the forms the legacy systems write', function (string $value, bool $expected) {
    expect(migracionRow(['activo' => $value])->bool('activo'))->toBe($expected);
})->with([
    ['true', true], ['TRUE', true], ['t', true], ['1', true],
    ['false', false], ['f', false], ['0', false],
]);

it('rejects a boolean it does not know', function () {
    migracionRow(['activo' => 'quizás'])->bool('activo');
})->throws(RowRejected::class, 'valor no reconocido en activo: quizás');

it('reads one of a list of values, ignoring case', function () {
    expect(migracionRow(['empresa' => 'sgx'])->oneOf('empresa', ['SGX', 'SDC', 'ALL']))->toBe('SGX')
        ->and(fn () => migracionRow(['empresa' => 'XYZ'])->oneOf('empresa', ['SGX', 'SDC']))
        ->toThrow(RowRejected::class, 'valor no reconocido en empresa: XYZ (se espera SGX, SDC)');
});

it('reads integer lists in every array notation', function (string $value, array $expected) {
    expect(migracionRow(['slp_codes' => $value])->intList('slp_codes'))->toBe($expected);
})->with([
    'postgres' => ['{84,85}', [84, 85]],
    'json' => ['[84, 85]', [84, 85]],
    'plain' => ['84,85', [84, 85]],
    'single' => ['84', [84]],
    'repeated' => ['{84,84}', [84]],
    'empty braces' => ['{}', []],
    'empty' => ['', []],
]);

it('rejects an integer list with something else in it', function () {
    migracionRow(['slp_codes' => '{84,abc}'])->intList('slp_codes');
})->throws(RowRejected::class, 'lista de números inválida en slp_codes: {84,abc}');

it('reads integers and decimals with a dot', function () {
    $row = migracionRow(['id' => '42', 'valor' => '2500000.50', 'descuento' => '-3']);

    expect($row->int('id'))->toBe(42)
        ->and($row->decimal('valor', positive: true))->toBe('2500000.50')
        ->and($row->decimal('descuento'))->toBe('-3');
});

it('rejects malformed numbers and non positive amounts', function (string $column, string $value, string $reason) {
    $row = migracionRow([$column => $value]);

    expect(fn () => $column === 'id' ? $row->int('id') : $row->decimal('valor', positive: true))
        ->toThrow(RowRejected::class, $reason);
})->with([
    ['id', '4.2', 'número inválido en id: 4.2'],
    ['valor', '2.500.000', 'número inválido en valor: 2.500.000'],
    ['valor', '2500000,50', 'número inválido en valor: 2500000,50'],
    ['valor', '0', 'el valor debe ser mayor que cero en valor: 0'],
]);

it('gives the legacy id of the row for the report', function () {
    expect(migracionRow(['id' => ' 17 '])->legacyId())->toBe('17')
        ->and(migracionRow(['nombre' => 'x'])->legacyId())->toBe('');
});

it('refuses to read a column the file did not declare', function () {
    migracionRow(['nombre' => 'x'])->optionalString('pass_hash');
})->throws(LogicException::class);
