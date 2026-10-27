<?php

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\LoadStatus;
use App\Modules\Core\Contracts\Events\BatchAccepted;
use App\Modules\Core\Contracts\IngestaSink;
use App\Modules\Ingesta\Domain\Schema\DatasetSchemas;
use App\Modules\Ingesta\Http\Middleware\VerifyRobotSignature;
use App\Modules\Ingesta\Infrastructure\Persistence\IngestaLoad;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\AuditEntry;
use Tests\Fakes\FakeAuditLogger;
use Tests\Modules\Ingesta\Support\IngestaRows;
use Tests\Modules\Ingesta\Support\RobotRequests;

beforeEach(function () {
    Event::fake([BatchAccepted::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 07:00:00', 'America/Bogota'));
    config(['ingesta.hmac_keys' => RobotRequests::KEY_ID.':'.RobotRequests::SECRET]);
    $this->app->instance(AuditLogger::class, $this->audit = new FakeAuditLogger);
});

/**
 * Posts a signed batch to /api/ingesta/{lote}.
 *
 * @param  array<string, string>  $headers  Server variables that replace the signed ones.
 */
function postRobotBatch(string $lote, string $body, bool $gzip = true, array $headers = [], array $sign = []): TestResponse
{
    $raw = $gzip ? RobotRequests::gzip($body) : $body;
    $server = array_merge(RobotRequests::headers($lote, $raw, $gzip, ...$sign), $headers);

    return test()->call('POST', "/api/ingesta/{$lote}", [], [], [], $server, $raw);
}

/**
 * @return list<array<string, scalar|null>>
 */
function robotAdvisorRows(): array
{
    return [IngestaRows::advisor(11), IngestaRows::advisor(12)];
}

it('serves the robot endpoint behind the rate limit and the signature check', function () {
    $route = Route::getRoutes()->getByName('ingesta.api.lotes.store');

    expect($route?->uri())->toBe('api/ingesta/{lote}')
        ->and($route?->methods())->toBe(['POST'])
        ->and($route?->gatherMiddleware())->toContain('throttle:ingesta-robot', VerifyRobotSignature::class);
});

it('accepts a signed gzipped batch with 201 and stores its rows', function () {
    Log::spy();
    $rows = [IngestaRows::cartera(['saldo' => '1000000.50', 'dias' => 12]), IngestaRows::cartera(['saldo' => '250000.00', 'dias' => -3])];

    $response = postRobotBatch('cartera', RobotRequests::body($rows, batchId: 'cartera-SGX-2026-10-15-0123456789abcdef'));

    $load = IngestaLoad::query()->sole();
    $response->assertCreated()->assertExactJson([
        'load' => [
            'id' => $load->id,
            'dataset' => 'cartera',
            'company' => 'SGX',
            'cutoff_date' => '2026-10-15',
            'source' => 'robot',
            'status' => 'aceptada',
            'row_count' => 2,
            'total' => '1250000.50',
            'overdue' => '1000000.50',
            'received_at' => '2026-10-15T07:00:00-05:00',
            'error' => null,
        ],
        'duplicate' => false,
    ]);

    expect(iterator_to_array(app(IngestaSink::class)->rows($load->id), false))->toBe($rows)
        ->and($load->cutoff_date->format('Y-m-d e'))->toBe('2026-10-15 America/Bogota');
    Event::assertDispatchedTimes(BatchAccepted::class, 1);
    Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context): bool => $context === [
        'dataset' => 'cartera', 'company' => 'SGX', 'rows' => 2, 'batch_id' => 'cartera-SGX-2026-10-15-0123456789abcdef', 'duplicate' => false,
    ]);
    $this->audit->assertRecorded('ingesta.batch.accepted', fn (AuditEntry $entry): bool => $entry->subjectType === 'ingesta_load'
        && $entry->subjectId === $load->id
        && $entry->changes === []
        && $entry->context === ['dataset' => 'cartera', 'company' => 'SGX', 'rows' => 2, 'batch_id' => 'cartera-SGX-2026-10-15-0123456789abcdef', 'key_id' => RobotRequests::KEY_ID]);
    expect($this->audit->entries())->toHaveCount(1);
});

it('accepts a plain JSON body and rebuilds rows from columns in any order', function () {
    $columns = array_reverse(array_keys(IngestaRows::advisor()));
    $rows = array_map(fn (array $row): array => array_merge(array_flip($columns), $row), robotAdvisorRows());

    postRobotBatch('asesores', RobotRequests::body($rows, columns: $columns), gzip: false)->assertCreated();

    expect(iterator_to_array(app(IngestaSink::class)->rows(IngestaLoad::query()->sole()->id), false))->toBe(robotAdvisorRows());
});

it('answers 200 with the original load for a repeated batch', function () {
    $first = postRobotBatch('asesores', RobotRequests::body(robotAdvisorRows()))->assertCreated();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 07:05:00', 'America/Bogota'));

    $again = postRobotBatch('asesores', RobotRequests::body(robotAdvisorRows()));

    $again->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('load', $first->json('load'));
    expect(IngestaLoad::query()->count())->toBe(1)
        ->and($this->audit->entries())->toHaveCount(1);
    Event::assertDispatchedTimes(BatchAccepted::class, 1);
});

it('refuses the exact same signed request a second time', function () {
    Log::spy();
    $raw = RobotRequests::gzip(RobotRequests::body(robotAdvisorRows()));
    $server = RobotRequests::headers('asesores', $raw, gzip: true);

    $this->call('POST', '/api/ingesta/asesores', [], [], [], $server, $raw)->assertCreated();
    $this->call('POST', '/api/ingesta/asesores', [], [], [], $server, $raw)
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Firma inválida.']);

    expect(IngestaLoad::query()->count())->toBe(1);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context['reason'] === 'replayed'
        && $context['key'] === RobotRequests::KEY_ID);
});

it('records nothing in the audit log for a rejected batch or without an audit logger', function () {
    postRobotBatch('asesores', RobotRequests::body([IngestaRows::advisor(11, ['nombre' => null])]))->assertUnprocessable();

    $this->app->forgetInstance(AuditLogger::class);
    unset($this->app[AuditLogger::class]);

    postRobotBatch('asesores', RobotRequests::body(robotAdvisorRows(), batchId: 'b-2'))->assertCreated();

    expect($this->audit->entries())->toBe([]);
});

it('accepts an empty batch of a dataset that allows it', function () {
    $body = RobotRequests::body([], batchId: 'ventas-SGX-2026-10-15-0', columns: DatasetSchemas::for(Dataset::Sales)->columnNames());

    postRobotBatch('ventas', $body)
        ->assertCreated()
        ->assertJsonPath('load.dataset', 'ventas')
        ->assertJsonPath('load.row_count', 0)
        ->assertJsonPath('load.total', null);
});

it('accepts every key of the keyring, so keys can rotate', function () {
    $old = 'k2026a-secreto-viejo-de-al-menos-32-bytes';
    $new = 'k2026b-secreto-nuevo-de-al-menos-32-bytes';
    config(['ingesta.hmac_keys' => "k2026a:{$old},k2026b:{$new}"]);

    postRobotBatch('asesores', RobotRequests::body(robotAdvisorRows(), batchId: 'b-1'), sign: ['keyId' => 'k2026a', 'secret' => $old])->assertCreated();
    postRobotBatch('asesores', RobotRequests::body(robotAdvisorRows(), batchId: 'b-2'), sign: ['keyId' => 'k2026b', 'secret' => $new])->assertCreated();

    config(['ingesta.hmac_keys' => "k2026b:{$new}"]);

    postRobotBatch('asesores', RobotRequests::body(robotAdvisorRows(), batchId: 'b-3'), sign: ['keyId' => 'k2026a', 'secret' => $old])->assertUnauthorized();
});

it('refuses a request whose signature does not check out', function (array $sign, array $headers) {
    Log::spy();

    postRobotBatch('asesores', RobotRequests::body(robotAdvisorRows()), headers: $headers, sign: $sign)
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Firma inválida.']);

    expect(IngestaLoad::query()->count())->toBe(0);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => isset($context['reason'], $context['ip'])
        && array_key_exists('key', $context)
        && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), RobotRequests::SECRET));
})->with([
    'wrong secret' => [['secret' => 'otro-secreto'], []],
    'unknown key' => [['keyId' => 'k1999z'], []],
    // 1792065600 is 2026-10-15 07:00 in Bogota, the time of every test.
    'stale timestamp' => [['timestamp' => 1_792_065_600 - 301], []],
    'future timestamp' => [['timestamp' => 1_792_065_600 + 301], []],
    'timestamp not an integer' => [[], ['HTTP_X_INGESTA_TIMESTAMP' => '1792152000.5']],
    'signed for another lote' => [['signFor' => 'cartera'], []],
    'signature without prefix' => [[], ['HTTP_X_INGESTA_SIGNATURE' => str_repeat('a', 64)]],
    'missing key' => [[], ['HTTP_X_INGESTA_KEY' => '']],
    'missing timestamp' => [[], ['HTTP_X_INGESTA_TIMESTAMP' => '']],
    'missing signature' => [[], ['HTTP_X_INGESTA_SIGNATURE' => '']],
]);

it('accepts a timestamp within the allowed skew', function (int $offset) {
    postRobotBatch('asesores', RobotRequests::body(robotAdvisorRows()), sign: ['timestamp' => now()->getTimestamp() + $offset])->assertCreated();
})->with([-300, 300]);

it('refuses a body changed after signing', function () {
    $raw = RobotRequests::gzip(RobotRequests::body(robotAdvisorRows()));
    $server = RobotRequests::headers('asesores', $raw, gzip: true);

    $this->call('POST', '/api/ingesta/asesores', [], [], [], $server, RobotRequests::gzip(RobotRequests::body([IngestaRows::advisor(99)])))
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Firma inválida.']);
});

it('refuses every request while no key is configured', function () {
    Log::spy();
    config(['ingesta.hmac_keys' => '']);

    postRobotBatch('asesores', RobotRequests::body(robotAdvisorRows()))->assertUnauthorized()->assertExactJson(['message' => 'Firma inválida.']);

    Log::shouldHaveReceived('error')->once();
});

it('answers 404 for an unknown lote', function () {
    postRobotBatch('facturas', RobotRequests::body(robotAdvisorRows()))->assertNotFound();
});

it('answers 413 for a body over the size limit, before reading it', function () {
    config(['ingesta.max_request_bytes' => 100]);

    postRobotBatch('asesores', RobotRequests::body(robotAdvisorRows()), gzip: false)
        ->assertStatus(413)
        ->assertExactJson(['message' => 'El lote pesa más de 100 bytes, el máximo que se acepta.']);
    expect(IngestaLoad::query()->count())->toBe(0);
});

it('refuses an oversized request with 413 before checking its signature', function (array $server, string $body) {
    Log::spy();
    config(['ingesta.max_request_bytes' => 100]);

    $this->call('POST', '/api/ingesta/asesores', [], [], [], $server + ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body)
        ->assertStatus(413)
        ->assertExactJson(['message' => 'El lote pesa más de 100 bytes, el máximo que se acepta.']);

    // A signature check would have logged why it refused the request.
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
    expect(IngestaLoad::query()->count())->toBe(0);
})->with([
    'unsigned body over the limit' => fn (): array => [[], str_repeat('x', 101)],
    'wrongly signed body over the limit' => fn (): array => [[
        'HTTP_X_INGESTA_KEY' => RobotRequests::KEY_ID,
        'HTTP_X_INGESTA_TIMESTAMP' => (string) now()->getTimestamp(),
        'HTTP_X_INGESTA_SIGNATURE' => 'sha256='.str_repeat('0', 64),
    ], str_repeat('x', 101)],
    'Content-Length over the limit' => fn (): array => [['CONTENT_LENGTH' => '5000'], '{}'],
]);

it('answers 422 without a history entry for a malformed request', function (string $body, bool $gzip, array $headers, string $key) {
    $raw = $gzip ? RobotRequests::gzip($body) : $body;
    $server = array_merge(RobotRequests::headers('asesores', $raw, $gzip), $headers);

    $this->call('POST', '/api/ingesta/asesores', [], [], [], $server, $raw)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$key]);

    expect(IngestaLoad::query()->count())->toBe(0);
    Event::assertNotDispatched(BatchAccepted::class);
})->with([
    'broken JSON' => ['{"batch_id": ', false, [], 'body'],
    'JSON that is not an object' => ['[1, 2]', false, [], 'body'],
    'gzip header on plain bytes' => ['{"rows": []}', false, ['HTTP_CONTENT_ENCODING' => 'gzip'], 'body'],
    'unsupported encoding' => ['{"rows": []}', false, ['HTTP_CONTENT_ENCODING' => 'br'], 'body'],
    'unknown company' => [RobotRequests::body([IngestaRows::advisor()], company: 'XYZ'), true, [], 'company'],
    'bad cutoff date' => [RobotRequests::body([IngestaRows::advisor()], cutoffDate: '15/10/2026'), true, [], 'cutoff_date'],
    'repeated columns' => [RobotRequests::body([IngestaRows::advisor()], columns: ['slp_code', 'slp_code', 'email', 'activo', 'clientes']), true, [], 'columns.1'],
    'rows missing' => ['{"batch_id": "b-1", "company": "SGX", "cutoff_date": "2026-10-15", "columns": ["slp_code"]}', true, [], 'rows'],
    'rows not a list' => ['{"batch_id": "b-1", "company": "SGX", "cutoff_date": "2026-10-15", "columns": ["slp_code"], "rows": {"a": [1]}}', true, [], 'rows'],
    'short row' => [RobotRequests::body([IngestaRows::advisor()], columns: ['slp_code', 'nombre']), true, [], 'rows.0'],
    'nested value' => [str_replace('"Asesor 11"', '["Asesor 11"]', RobotRequests::body([IngestaRows::advisor()])), true, [], 'rows.0'],
]);

it('answers 422 for a batch that fails the schema and records it as rejected', function () {
    $rows = [IngestaRows::advisor(11, ['nombre' => null]), IngestaRows::advisor(11)];

    postRobotBatch('asesores', RobotRequests::body($rows))
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'El lote tiene 2 errores.',
            'errors' => [
                'rows.0.nombre' => ['La columna nombre es obligatoria.'],
                'rows.1' => ['La fila repite la llave (slp_code = 11) de la fila 0.'],
            ],
        ]);

    $history = app(IngestaSink::class)->history(Dataset::Advisors, CompanyCode::SGX);

    expect($history)->toHaveCount(1)
        ->and($history[0]->status)->toBe(LoadStatus::Rejected)
        ->and($history[0]->error)->toBe('El lote tiene 2 errores.');
    Event::assertNotDispatched(BatchAccepted::class);
});

it('answers 422 instead of failing the totals for a saldo of 17 integer digits', function () {
    $rows = [IngestaRows::cartera(['saldo' => '12345678901234567.00'])];

    postRobotBatch('cartera', RobotRequests::body($rows, batchId: 'cartera-SGX-2026-10-15-17'))
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'La columna saldo debe ser un número con punto decimal, hasta 16 cifras enteras y 2 decimales, como 1234.56.',
            'errors' => ['rows.0.saldo' => ['La columna saldo debe ser un número con punto decimal, hasta 16 cifras enteras y 2 decimales, como 1234.56.']],
        ]);

    expect(IngestaLoad::query()->sole()->status)->toBe(LoadStatus::Rejected);
    Event::assertNotDispatched(BatchAccepted::class);
});

it('answers 422 for a batch whose columns do not match the dataset', function () {
    $row = IngestaRows::advisor();
    unset($row['clientes']);

    postRobotBatch('asesores', RobotRequests::body([$row]))
        ->assertUnprocessable()
        ->assertJsonPath('errors', ['rows.0' => ['A la fila le faltan estas columnas: clientes.']]);
});
