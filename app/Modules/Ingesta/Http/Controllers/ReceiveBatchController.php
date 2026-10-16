<?php

namespace App\Modules\Ingesta\Http\Controllers;

use App\Modules\Core\Contracts\Data\IncomingBatch;
use App\Modules\Core\Contracts\Data\LoadRecord;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\LoadSource;
use App\Modules\Core\Contracts\Exceptions\InvalidBatch;
use App\Modules\Core\Contracts\IngestaSink;
use App\Modules\Ingesta\Application\ValidationErrors;
use App\Modules\Ingesta\Infrastructure\Persistence\IngestaLoad;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

/**
 * POST /api/ingesta/{lote}: a batch of the ETL robot, already signed
 * (VerifyRobotSignature). Wire protocol v1, shared with
 * etl/sinks/http_sink.py and described in the module README.
 *
 * Checks the size, gunzips, parses the columnar JSON, rebuilds one
 * associative row per value list and hands the batch to IngestaSink, which
 * validates it against the dataset schema and stores it. A malformed
 * request answers 422 without an entry in the load history; a batch that
 * fails the schema answers 422 and is recorded as rejected.
 */
final class ReceiveBatchController
{
    public function __construct(
        private readonly IngestaSink $sink,
    ) {}

    public function __invoke(Request $request, string $lote): JsonResponse
    {
        $maxBytes = (int) config('ingesta.max_request_bytes');
        $raw = $request->getContent();

        if (max((int) $request->header('Content-Length', '0'), strlen($raw)) > $maxBytes) {
            return new JsonResponse(['message' => __('ingesta::robot.demasiado_grande', ['max' => $maxBytes])], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        // No local copy of the payload: batch() releases its rows as it reads them.
        $batch = $this->batch(Dataset::from($lote), $this->payload($request, $raw));
        $duplicate = IngestaLoad::query()->where('accepted_batch_id', $batch->batchId)->exists();

        try {
            $load = $this->sink->receive($batch);
        } catch (InvalidBatch $e) {
            return new JsonResponse(['message' => $e->getMessage(), 'errors' => $e->errors()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        Log::info('Ingesta: accepted a robot batch.', [
            'dataset' => $load->dataset->value,
            'company' => $load->company->value,
            'rows' => $load->rowCount,
            'batch_id' => $batch->batchId,
            'duplicate' => $duplicate,
        ]);

        return new JsonResponse(
            ['load' => $this->present($load), 'duplicate' => $duplicate],
            $duplicate ? Response::HTTP_OK : Response::HTTP_CREATED,
        );
    }

    /**
     * The decoded JSON object of the body, gunzipped when the robot sent
     * Content-Encoding: gzip.
     *
     * @return array<mixed>
     *
     * @throws ValidationException When the body is not a JSON object.
     */
    private function payload(Request $request, string $raw): array
    {
        $encoding = strtolower(trim((string) $request->header('Content-Encoding', '')));

        if (! in_array($encoding, ['', 'identity', 'gzip'], true)) {
            $this->fail('body', __('ingesta::robot.codificacion'));
        }

        if ($encoding === 'gzip') {
            $maxBytes = (int) config('ingesta.max_payload_bytes');
            $raw = @gzdecode($raw, $maxBytes);

            if ($raw === false) {
                $this->fail('body', __('ingesta::robot.gzip_invalido', ['max' => $maxBytes]));
            }
        }

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            $this->fail('body', __('ingesta::robot.json_invalido'));
        }

        if (! is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            $this->fail('body', __('ingesta::robot.json_objeto'));
        }

        return $payload;
    }

    /**
     * The batch of the envelope, with one associative row per value list.
     *
     * @param  array<mixed>  $payload
     *
     * @throws ValidationException When the envelope or a row is malformed.
     */
    private function batch(Dataset $dataset, array $payload): IncomingBatch
    {
        // The rows stay out of the validator, which would walk every value
        // of a batch of many thousands; they are checked by hand below.
        $envelope = Validator::make(array_diff_key($payload, ['rows' => true]), [
            'batch_id' => ['required', 'string'],
            'company' => ['required', 'string', Rule::enum(CompanyCode::class)],
            'cutoff_date' => ['required', 'string', 'date_format:Y-m-d'],
            'columns' => ['required', 'array', 'list'],
            'columns.*' => ['string', 'distinct'],
        ])->validate();

        if (! isset($payload['rows']) || ! is_array($payload['rows']) || ! array_is_list($payload['rows'])) {
            $this->fail('rows', __('ingesta::robot.filas_lista'));
        }

        /** @var list<string> $columns */
        $columns = $envelope['columns'];

        return new IncomingBatch(
            dataset: $dataset,
            company: CompanyCode::from($envelope['company']),
            cutoffDate: CarbonImmutable::createFromFormat('!Y-m-d', $envelope['cutoff_date'], 'America/Bogota')
                ?: throw new UnexpectedValueException('The cutoff date passed date_format but did not parse.'),
            source: LoadSource::Robot,
            batchId: $envelope['batch_id'],
            rows: $this->rows($columns, $payload['rows']),
        );
    }

    /**
     * Associative rows from the value lists, which are dropped one by one so
     * a large batch is not held twice in memory.
     *
     * @param  list<string>  $columns
     * @param  array<mixed>  $values
     * @return list<array<string, scalar|null>>
     *
     * @throws ValidationException When a row is not a list of count($columns) scalars or nulls.
     */
    private function rows(array $columns, array &$values): array
    {
        $errors = new ValidationErrors;
        $rows = [];

        foreach (array_keys($values) as $index) {
            $row = $values[$index];
            unset($values[$index]);

            if (! is_array($row) || ! array_is_list($row) || count($row) !== count($columns)) {
                $errors->add("rows.{$index}", __('ingesta::robot.fila_lista', ['count' => count($columns)]));
            } elseif (array_filter($row, fn (mixed $value): bool => $value !== null && ! is_scalar($value)) !== []) {
                $errors->add("rows.{$index}", __('ingesta::robot.fila_valores'));
            } else {
                /** @var list<scalar|null> $row */
                $rows[] = array_combine($columns, $row);
            }

            if ($errors->full()) {
                break;
            }
        }

        if ($errors->all() !== []) {
            throw ValidationException::withMessages($errors->all());
        }

        return $rows;
    }

    /**
     * @throws ValidationException
     */
    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => [$message]]);
    }

    /**
     * The LoadRecord as the robot reads it: amounts as decimal strings.
     *
     * @return array<string, scalar|null>
     */
    private function present(LoadRecord $load): array
    {
        return [
            'id' => $load->id,
            'dataset' => $load->dataset->value,
            'company' => $load->company->value,
            'cutoff_date' => $load->cutoffDate->format('Y-m-d'),
            'source' => $load->source->value,
            'status' => $load->status->value,
            'row_count' => $load->rowCount,
            'total' => $load->total === null ? null : number_format($load->total, 2, '.', ''),
            'overdue' => $load->overdue === null ? null : number_format($load->overdue, 2, '.', ''),
            'received_at' => $load->receivedAt->toIso8601String(),
            'error' => $load->error,
        ];
    }
}
