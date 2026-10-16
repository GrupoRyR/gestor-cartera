<?php

use App\Modules\Core\Contracts\Data\IncomingBatch;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\LoadSource;
use App\Modules\Ingesta\Application\BatchValidator;
use App\Modules\Ingesta\Domain\Schema\DatasetSchemas;
use Carbon\CarbonImmutable;

/**
 * @param  list<array<string, scalar|null>>  $rows
 */
function batchToValidate(
    array $rows,
    Dataset $dataset = Dataset::Advisors,
    string $batchId = 'asesores-SGX-2026-10-15-0123456789abcdef',
    string $cutoffDate = '2026-10-15',
): IncomingBatch {
    return new IncomingBatch(
        $dataset,
        CompanyCode::SGX,
        CarbonImmutable::parse($cutoffDate, 'America/Bogota'),
        LoadSource::Robot,
        $batchId,
        $rows,
    );
}

/**
 * @return array<string, scalar|null>
 */
function advisorRow(int $code = 11, array $overrides = []): array
{
    return array_merge(['slp_code' => $code, 'nombre' => 'Ana Ríos', 'email' => 'ana@segurex.com', 'activo' => 'Y', 'clientes' => 40], $overrides);
}

/**
 * @return array<string, scalar|null>
 */
function salesRow(array $overrides = []): array
{
    return array_merge([
        'tipo_objeto' => 13, 'doc_entry' => 501, 'numero_documento' => 9001, 'linea' => 0,
        'clase_documento' => 'factura', 'estado_documento' => 'abierto',
        'fecha_documento' => '2026-10-01', 'fecha_contabilizacion' => '2026-10-01',
        'slp_code' => 11, 'vendedor' => '14 MONICA RIVERA AREVALO', 'canal' => null,
        'codigo_cliente' => 'C001', 'cliente' => 'Cliente uno', 'nit' => '900123456',
        'ciudad' => null, 'pedido' => null, 'id_pedido_app' => null, 'oc_cliente' => null,
        'ciudad_envio' => null, 'direccion_envio' => null, 'marca' => null, 'familia' => null,
        'codigo_articulo' => 'A-1', 'descripcion' => 'Guantes', 'cantidad' => '12.500000',
        'costo_unitario' => '1000.00', 'costo_total' => '12500.00', 'precio_lista' => null,
        'precio_unitario' => '1500', 'total' => '18750.00', 'iva' => '3562.50', 'moneda' => 'COP',
        'trm' => '1', 'comentarios' => null,
    ], $overrides);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 07:00:00', 'America/Bogota'));
    $this->validator = new BatchValidator;
});

it('accepts a valid batch', function () {
    expect($this->validator->errors(batchToValidate([advisorRow(11), advisorRow(12)])))->toBe([])
        ->and($this->validator->errors(batchToValidate([salesRow()], Dataset::Sales)))->toBe([]);
});

it('checks the batch id', function (string $batchId, string $message) {
    expect($this->validator->errors(batchToValidate([advisorRow()], batchId: $batchId)))
        ->toBe(['batch_id' => [$message]]);
})->with([
    'blank' => ['   ', 'Falta el identificador del lote.'],
    'too long' => [str_repeat('a', 121), 'El identificador del lote no puede tener más de 120 caracteres.'],
    'spaces' => ['lote 1', 'El identificador del lote solo admite letras sin tildes, números y los signos . _ : -'],
    'accents' => ['cartera-año', 'El identificador del lote solo admite letras sin tildes, números y los signos . _ : -'],
]);

it('accepts a batch id of 120 characters with every allowed sign', function () {
    $batchId = str_pad('a.b_c:d-', 120, '9');

    expect($this->validator->errors(batchToValidate([advisorRow()], batchId: $batchId)))->toBe([]);
});

it('refuses a cutoff date after today in Bogota', function () {
    // 2026-10-16 01:00 UTC is still 2026-10-15 in Bogota.
    $this->travelTo(CarbonImmutable::parse('2026-10-16 01:00:00', 'UTC'));

    expect($this->validator->errors(batchToValidate([advisorRow()], cutoffDate: '2026-10-15')))->toBe([])
        ->and($this->validator->errors(batchToValidate([advisorRow()], cutoffDate: '2026-10-16')))
        ->toBe(['cutoff_date' => ['La fecha de corte 2026-10-16 es posterior a hoy (2026-10-15).']]);
});

it('refuses an empty batch unless the dataset allows it', function () {
    expect($this->validator->errors(batchToValidate([])))
        ->toBe(['rows' => ['El lote de asesores llegó sin filas y este tipo de carga no puede estar vacío.']])
        ->and($this->validator->errors(batchToValidate([], Dataset::Sales)))->toBe([]);
});

it('refuses more rows than the configured maximum', function () {
    config(['ingesta.max_rows' => 2]);

    expect($this->validator->errors(batchToValidate([advisorRow(1), advisorRow(2), advisorRow(3)])))
        ->toBe(['rows' => ['El lote trae 3 filas y el máximo es 2.']]);
});

it('ships a row limit in the module configuration', function () {
    expect(config('ingesta.max_rows'))->toBe(200000);
});

it('reports missing and unknown columns once per row', function () {
    $row = advisorRow();
    unset($row['email'], $row['clientes']);
    $row['correo'] = 'ana@segurex.com';

    expect($this->validator->errors(batchToValidate([advisorRow(1), $row])))->toBe(['rows.1' => [
        'A la fila le faltan estas columnas: email, clientes.',
        'La fila trae columnas que no existen en asesores: correo.',
    ]]);
});

it('checks required values', function () {
    $errors = $this->validator->errors(batchToValidate([
        advisorRow(1, ['email' => null, 'clientes' => null]),
        advisorRow(2, ['nombre' => null]),
        advisorRow(3, ['nombre' => '   ']),
    ]));

    expect($errors)->toBe([
        'rows.1.nombre' => ['La columna nombre es obligatoria.'],
        'rows.2.nombre' => ['La columna nombre es obligatoria.'],
    ]);
});

it('checks text values', function () {
    $errors = $this->validator->errors(batchToValidate([
        advisorRow(1, ['email' => 12]),
        advisorRow(2, ['email' => str_repeat('ñ', 4000)]),
        advisorRow(3, ['email' => str_repeat('ñ', 4001)]),
    ]));

    expect($errors)->toBe([
        'rows.0.email' => ['La columna email debe ser texto.'],
        'rows.2.email' => ['La columna email no puede tener más de 4000 caracteres.'],
    ]);
});

it('checks integer values', function (mixed $value) {
    expect($this->validator->errors(batchToValidate([advisorRow(1, ['clientes' => $value])])))
        ->toBe(['rows.0.clientes' => ['La columna clientes debe ser un número entero.']]);
})->with(['numeric string' => '40', 'float' => 40.0, 'bool' => true]);

it('checks decimal values with two places', function (mixed $value, bool $valid) {
    $errors = $this->validator->errors(batchToValidate([salesRow(['total' => $value])], Dataset::Sales));

    expect($errors)->toBe($valid ? [] : ['rows.0.total' => ['La columna total debe ser un número con punto decimal y hasta 2 decimales, como 1234.56.']]);
})->with([
    ['18750', true],
    ['-18750.5', true],
    ['12345678901234567.99', true],
    ['123456789012345678', false],
    ['18750.505', false],
    ['18.750,50', false],
    ['+5', false],
    ['.5', false],
    [18750, false],
    [18750.5, false],
]);

it('checks decimal values with six places', function (mixed $value, bool $valid) {
    $errors = $this->validator->errors(batchToValidate([salesRow(['cantidad' => $value])], Dataset::Sales));

    expect($errors)->toBe($valid ? [] : ['rows.0.cantidad' => ['La columna cantidad debe ser un número con punto decimal y hasta 6 decimales, como 4123.456789.']]);
})->with([
    ['1', true],
    ['-0.123456', true],
    ['1234567890123.5', true],
    ['12345678901234', false],
    ['0.1234567', false],
    [1, false],
]);

it('checks date values', function (mixed $value, bool $valid) {
    $errors = $this->validator->errors(batchToValidate([salesRow(['fecha_documento' => $value])], Dataset::Sales));

    expect($errors)->toBe($valid ? [] : ['rows.0.fecha_documento' => ['La columna fecha_documento debe ser una fecha real con formato AAAA-MM-DD.']]);
})->with([
    ['2024-02-29', true],
    ['2026-02-29', false],
    ['2026-13-01', false],
    ['2026-1-01', false],
    ['01/10/2026', false],
    ['2026-10-01 00:00:00', false],
    [20261001, false],
]);

it('reports repeated natural keys', function () {
    $errors = $this->validator->errors(batchToValidate([
        salesRow(['linea' => 0]),
        salesRow(['linea' => 1]),
        salesRow(['linea' => 0]),
        salesRow(['linea' => 0, 'tipo_objeto' => 14]),
    ], Dataset::Sales));

    expect($errors)->toBe([
        'rows.2' => ['La fila repite la llave (tipo_objeto = 13, doc_entry = 501, linea = 0) de la fila 0.'],
    ]);
});

it('treats a null key value as part of the key', function () {
    $contact = fn (?int $id): array => [
        'codigo_sn' => 'C001', 'nit' => null, 'contacto_id' => $id, 'nombre' => null, 'cargo' => null,
        'correo' => null, 'telefono' => null, 'celular' => null, 'principal' => 'N', 'activo' => 'Y',
    ];

    expect($this->validator->errors(batchToValidate([$contact(null), $contact(5)], Dataset::Contacts)))->toBe([])
        ->and($this->validator->errors(batchToValidate([$contact(null), $contact(null)], Dataset::Contacts)))
        ->toBe(['rows.1' => ['La fila repite la llave (codigo_sn = C001, contacto_id = vacío) de la fila 0.']]);
});

it('does not check keys of datasets without one', function () {
    $row = ['codigo_sn' => 'C1', 'documento' => 'F-1', 'fecha_vencimiento' => '2026-10-01', 'dias' => 14, 'saldo' => '100.00'];
    $row += array_fill_keys(array_diff(
        DatasetSchemas::for(Dataset::Receivables)->columnNames(),
        array_keys($row),
    ), null);

    expect($this->validator->errors(batchToValidate([$row, $row], Dataset::Receivables)))->toBe([]);
});

it('stops at 50 error keys', function () {
    $rows = array_map(fn (int $code): array => advisorRow($code, ['nombre' => null, 'clientes' => 'x']), range(1, 40));

    $errors = $this->validator->errors(batchToValidate($rows));

    expect($errors)->toHaveCount(50)
        ->and(array_key_last($errors))->toBe('rows.24.clientes');
});

it('summarizes the errors for the load history', function () {
    expect(BatchValidator::summarize(['batch_id' => ['Falta el identificador del lote.']]))->toBe('Falta el identificador del lote.')
        ->and(BatchValidator::summarize(['rows.1' => ['Uno.', 'Dos.'], 'rows.2.nombre' => ['Tres.']]))->toBe('El lote tiene 3 errores.')
        ->and(BatchValidator::summarize(array_fill_keys(array_map(fn (int $i): string => "rows.{$i}", range(0, 49)), ['Uno.'])))
        ->toBe('El lote tiene 50 errores o más; corrige estos primero.');
});
