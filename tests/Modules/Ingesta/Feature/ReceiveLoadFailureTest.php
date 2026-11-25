<?php

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Ingesta\Domain\Failures\FailureCause;
use App\Modules\Ingesta\Domain\Failures\FailureSource;
use App\Modules\Ingesta\Domain\Failures\FailureStage;
use App\Modules\Ingesta\Http\Middleware\VerifyRobotSignature;
use App\Modules\Ingesta\Infrastructure\Persistence\IngestaLoad;
use App\Modules\Ingesta\Infrastructure\Persistence\LoadFailureRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Modules\Ingesta\Support\IngestaRows;
use Tests\Modules\Ingesta\Support\RobotRequests;

const FAILURE_REPORT_PATH = '/api/ingesta/reportes';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-23 05:30:00', 'America/Bogota'));
    config(['ingesta.hmac_keys' => RobotRequests::KEY_ID.':'.RobotRequests::SECRET]);
});

/**
 * The failure report of the module README, with $overrides merged in.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function failureReport(array $overrides = []): array
{
    return array_merge([
        'report_id' => '6f1c2b0e-4d3a-4f5e-9a7b-1c2d3e4f5a6b',
        'run_id' => '0a9b8c7d-6e5f-4a3b-8c2d-1e0f9a8b7c6d',
        'company' => 'SGX',
        'dataset' => 'cartera',
        'slot' => '2026-11-23T05:00:00-05:00',
        'stage' => 'HANA',
        'cause' => 'ruta_hana',
        'transient' => true,
        'attempt' => 3,
        'max_attempts' => 3,
        'final' => true,
        'message' => 'No hay ruta a HANA tras 60 s',
        'occurred_at' => '2026-11-23T05:21:10-05:00',
    ], $overrides);
}

/**
 * Posts a signed failure report; $body is JSON-encoded unless it is already a string.
 *
 * @param  array<string, mixed>|string  $body
 * @param  array<string, mixed>  $sign  Named arguments of RobotRequests::signedHeaders().
 */
function postFailureReport(array|string $body, array $sign = []): TestResponse
{
    $raw = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    return test()->call('POST', FAILURE_REPORT_PATH, [], [], [], RobotRequests::signedHeaders(FAILURE_REPORT_PATH, $raw, ...$sign), $raw);
}

it('serves the failure report endpoint behind the rate limit and the signature check', function () {
    $route = Route::getRoutes()->getByName('ingesta.api.reportes.store');

    expect($route?->uri())->toBe('api/ingesta/reportes')
        ->and($route?->methods())->toBe(['POST'])
        ->and($route?->gatherMiddleware())->toContain('throttle:ingesta-robot', VerifyRobotSignature::class)
        ->and(Dataset::tryFrom('reportes'))->toBeNull();
});

it('records a signed failure report with 201 as sent by the robot, whatever source it claims', function () {
    $response = postFailureReport(failureReport(['source' => 'bases_sap']));

    $response->assertCreated()->assertExactJson([
        'report_id' => '6f1c2b0e-4d3a-4f5e-9a7b-1c2d3e4f5a6b',
        'status' => 'recorded',
        'duplicate' => false,
    ]);

    $failure = LoadFailureRecord::query()->sole();

    expect($failure->source)->toBe(FailureSource::Robot)
        ->and($failure->report_id)->toBe('6f1c2b0e-4d3a-4f5e-9a7b-1c2d3e4f5a6b')
        ->and($failure->run_id)->toBe('0a9b8c7d-6e5f-4a3b-8c2d-1e0f9a8b7c6d')
        ->and($failure->company)->toBe(CompanyCode::SGX)
        ->and($failure->dataset)->toBe(Dataset::Receivables)
        ->and($failure->stage)->toBe(FailureStage::Hana)
        ->and($failure->cause)->toBe(FailureCause::HanaRoute)
        ->and($failure->transient)->toBeTrue()
        ->and($failure->attempt)->toBe(3)
        ->and($failure->max_attempts)->toBe(3)
        ->and($failure->final)->toBeTrue()
        ->and($failure->message)->toBe('No hay ruta a HANA tras 60 s')
        ->and($failure->slot_at->format('Y-m-d H:i:s e'))->toBe('2026-11-23 05:00:00 America/Bogota')
        ->and($failure->occurred_at->format('Y-m-d H:i:s e'))->toBe('2026-11-23 05:21:10 America/Bogota')
        ->and($failure->received_at->format('Y-m-d H:i:s e'))->toBe('2026-11-23 05:30:00 America/Bogota');
});

it('stores the times in the app timezone whatever offset the robot sends', function () {
    postFailureReport(failureReport(['slot' => '2026-11-23T10:00:00+00:00', 'occurred_at' => '2026-11-23T10:21:10.250000+00:00']))->assertCreated();

    $failure = LoadFailureRecord::query()->sole();

    expect($failure->slot_at->format('Y-m-d H:i:s e'))->toBe('2026-11-23 05:00:00 America/Bogota')
        ->and($failure->occurred_at->format('Y-m-d H:i:s e'))->toBe('2026-11-23 05:21:10 America/Bogota');
});

it('cleans the message and cuts it to 300 characters', function () {
    $message = "Fallo en HANA:\n\tsin\x07 ruta  ".str_repeat('ñ', 400);

    postFailureReport(failureReport(['message' => $message]))->assertCreated();

    $stored = LoadFailureRecord::query()->sole()->message;

    expect(mb_strlen($stored))->toBe(300)
        ->and($stored)->toStartWith('Fallo en HANA: sin ruta ñ')
        ->and(preg_match('/[\x00-\x1F\x7F]/', $stored))->toBe(0);
});

it('answers 200 with duplicate true for a repeated report id and keeps the first report', function () {
    postFailureReport(failureReport())->assertCreated();

    postFailureReport(failureReport(['message' => 'Otro texto', 'attempt' => 1]))
        ->assertOk()
        ->assertExactJson(['report_id' => '6f1c2b0e-4d3a-4f5e-9a7b-1c2d3e4f5a6b', 'status' => 'recorded', 'duplicate' => true]);

    expect(LoadFailureRecord::query()->count())->toBe(1)
        ->and(LoadFailureRecord::query()->sole()->message)->toBe('No hay ruta a HANA tras 60 s');
});

it('answers 422 for a report that is not valid', function (array $overrides, string $key) {
    postFailureReport(failureReport($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$key]);

    expect(LoadFailureRecord::query()->count())->toBe(0);
})->with([
    'report id not a uuid' => [['report_id' => 'r-1'], 'report_id'],
    'run id not a uuid' => [['run_id' => '123'], 'run_id'],
    'unknown company' => [['company' => 'XYZ'], 'company'],
    'unknown dataset' => [['dataset' => 'facturas'], 'dataset'],
    'unknown stage' => [['stage' => 'hana'], 'stage'],
    'unknown cause' => [['cause' => 'disco'], 'cause'],
    'slot without offset' => [['slot' => '2026-11-23T05:00:00'], 'slot'],
    'slot as a plain date' => [['slot' => '2026-11-23'], 'slot'],
    'occurred at not a date' => [['occurred_at' => 'ayer'], 'occurred_at'],
    'transient not a boolean' => [['transient' => 'sí'], 'transient'],
    'final missing' => [['final' => null], 'final'],
    'attempt zero' => [['attempt' => 0], 'attempt'],
    'max attempts under the attempt' => [['attempt' => 3, 'max_attempts' => 2], 'max_attempts'],
    'max attempts over 10' => [['attempt' => 3, 'max_attempts' => 11], 'max_attempts'],
    'message blank' => [['message' => '   '], 'message'],
    'message over 2000 characters' => [['message' => str_repeat('a', 2001)], 'message'],
]);

it('answers 422 for a body that is not a JSON object', function (string $body) {
    postFailureReport($body)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['body']);

    expect(LoadFailureRecord::query()->count())->toBe(0);
})->with([
    'broken JSON' => ['{"report_id": '],
    'a JSON list' => ['[1, 2]'],
]);

it('refuses an unsigned report with 401', function () {
    $this->call('POST', FAILURE_REPORT_PATH, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(failureReport(), JSON_THROW_ON_ERROR))
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Firma inválida.']);

    expect(LoadFailureRecord::query()->count())->toBe(0);
});

it('refuses the exact same signed report a second time', function () {
    $raw = json_encode(failureReport(), JSON_THROW_ON_ERROR);
    $server = RobotRequests::signedHeaders(FAILURE_REPORT_PATH, $raw);

    $this->call('POST', FAILURE_REPORT_PATH, [], [], [], $server, $raw)->assertCreated();
    $this->call('POST', FAILURE_REPORT_PATH, [], [], [], $server, $raw)
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Firma inválida.']);

    expect(LoadFailureRecord::query()->count())->toBe(1);
});

it('answers 413 for a report over 8 KB', function () {
    $raw = json_encode(failureReport(['message' => str_repeat('a', 8 * 1024)]), JSON_THROW_ON_ERROR);

    postFailureReport($raw)
        ->assertStatus(413)
        ->assertExactJson(['message' => 'El reporte pesa más de 8192 bytes, el máximo que se acepta.']);

    expect(LoadFailureRecord::query()->count())->toBe(0);
});

it('refuses a batch signature on the report route', function () {
    Log::spy();
    $raw = json_encode(failureReport(), JSON_THROW_ON_ERROR);

    $this->call('POST', FAILURE_REPORT_PATH, [], [], [], RobotRequests::headers('cartera', $raw), $raw)->assertUnauthorized();

    expect(LoadFailureRecord::query()->count())->toBe(0);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context['reason'] === 'signature_mismatch');
});

it('refuses a report signature on a batch route', function () {
    $raw = RobotRequests::body([IngestaRows::advisor(11)]);

    $this->call('POST', '/api/ingesta/asesores', [], [], [], RobotRequests::signedHeaders(FAILURE_REPORT_PATH, $raw), $raw)->assertUnauthorized();

    expect(IngestaLoad::query()->count())->toBe(0);
});

it('signs the route path, not the query string of the request', function () {
    $raw = json_encode(failureReport(), JSON_THROW_ON_ERROR);

    $this->call('POST', FAILURE_REPORT_PATH.'?intento=3', [], [], [], RobotRequests::signedHeaders(FAILURE_REPORT_PATH, $raw), $raw)->assertCreated();
});
