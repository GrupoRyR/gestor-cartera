<?php

namespace App\Modules\Ingesta\Http\Controllers;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Ingesta\Application\Failures\LoadFailure;
use App\Modules\Ingesta\Application\Failures\RecordLoadFailure;
use App\Modules\Ingesta\Domain\Failures\FailureCause;
use App\Modules\Ingesta\Domain\Failures\FailureSource;
use App\Modules\Ingesta\Domain\Failures\FailureStage;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/ingesta/reportes: a load failure report of the ETL robot,
 * already signed for this route (VerifyRobotSignature). The body is a
 * plain JSON object of at most MAX_BYTES bytes, described in the module
 * README.
 *
 * The report is validated and handed to RecordLoadFailure with the source
 * "robot", whatever the body says. A new report answers 201; a repeated
 * report_id answers 200 with duplicate true and stores nothing. A
 * malformed or invalid report answers 422 and leaves a warning in the log
 * with the fields that failed, never a value of the report.
 */
final class ReceiveLoadFailureController
{
    /** Largest report body, in bytes; a bigger one answers 413. */
    public const int MAX_BYTES = 8 * 1024;

    /** Longest message accepted before RecordLoadFailure cuts it. */
    private const int MAX_MESSAGE_LENGTH = 2000;

    /** ISO 8601 with an offset, with or without microseconds. */
    private const array DATE_TIME_FORMATS = ['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP'];

    public function __construct(
        private readonly RecordLoadFailure $recorder,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $raw = $request->getContent();

        if (max((int) $request->header('Content-Length', '0'), strlen($raw)) > self::MAX_BYTES) {
            return new JsonResponse(['message' => __('ingesta::robot.reporte_demasiado_grande', ['max' => self::MAX_BYTES])], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        try {
            $failure = $this->failure($this->payload($request, $raw));
        } catch (ValidationException $e) {
            Log::warning('Ingesta: rejected a robot failure report.', [
                // Rule keys and "body" only: the caller's own keys never become an error key.
                'fields' => array_keys($e->errors()),
                'key_id' => (string) $request->header('X-Ingesta-Key'),
            ]);

            throw $e;
        }

        $recorded = $this->recorder->record($failure);

        Log::info('Ingesta: received a robot failure report.', [
            'report_id' => $failure->reportId,
            'company' => $failure->company->value,
            'dataset' => $failure->dataset->value,
            'stage' => $failure->stage->value,
            'cause' => $failure->cause->value,
            'final' => $failure->final,
            'duplicate' => ! $recorded,
        ]);

        return new JsonResponse(
            ['report_id' => $failure->reportId, 'status' => 'recorded', 'duplicate' => ! $recorded],
            $recorded ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    /**
     * The decoded JSON object of the body.
     *
     * @return array<mixed>
     *
     * @throws ValidationException When the body is compressed or not a JSON object.
     */
    private function payload(Request $request, string $raw): array
    {
        if (! in_array(strtolower(trim((string) $request->header('Content-Encoding', ''))), ['', 'identity'], true)) {
            $this->fail(__('ingesta::robot.reporte_codificacion'));
        }

        try {
            $payload = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->fail(__('ingesta::robot.json_invalido'));
        }

        if (! is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            $this->fail(__('ingesta::robot.reporte_objeto'));
        }

        return $payload;
    }

    /**
     * The validated report; a "source" in the body is ignored.
     *
     * @param  array<mixed>  $payload
     *
     * @throws ValidationException
     */
    private function failure(array $payload): LoadFailure
    {
        $dateTime = ['required', 'string', 'date_format:'.implode(',', self::DATE_TIME_FORMATS)];

        $report = Validator::make($payload, [
            'report_id' => ['required', 'string', 'uuid'],
            'run_id' => ['required', 'string', 'uuid'],
            'company' => ['required', 'string', Rule::enum(CompanyCode::class)],
            'dataset' => ['required', 'string', Rule::enum(Dataset::class)],
            'slot' => $dateTime,
            'stage' => ['required', 'string', Rule::enum(FailureStage::class)],
            'cause' => ['required', 'string', Rule::enum(FailureCause::class)],
            'transient' => ['required', 'boolean'],
            'attempt' => ['required', 'integer', 'min:1'],
            'max_attempts' => ['required', 'integer', 'gte:attempt', 'max:10'],
            'final' => ['required', 'boolean'],
            'message' => ['required', 'string', 'max:'.self::MAX_MESSAGE_LENGTH],
            'occurred_at' => $dateTime,
        ])->validate();

        return new LoadFailure(
            source: FailureSource::Robot,
            reportId: strtolower($report['report_id']),
            runId: strtolower($report['run_id']),
            company: CompanyCode::from($report['company']),
            dataset: Dataset::from($report['dataset']),
            slotAt: CarbonImmutable::parse($report['slot']),
            stage: FailureStage::from($report['stage']),
            cause: FailureCause::from($report['cause']),
            transient: (bool) $report['transient'],
            attempt: (int) $report['attempt'],
            maxAttempts: (int) $report['max_attempts'],
            final: (bool) $report['final'],
            message: $report['message'],
            occurredAt: CarbonImmutable::parse($report['occurred_at']),
        );
    }

    /**
     * @throws ValidationException
     */
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['body' => [$message]]);
    }
}
