<?php

namespace App\Modules\Ingesta\Http\Controllers;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\Data\IncomingBatch;
use App\Modules\Core\Contracts\Data\LoadRecord;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\LoadSource;
use App\Modules\Core\Contracts\Exceptions\InvalidBatch;
use App\Modules\Core\Contracts\IngestaSink;
use App\Modules\Ingesta\Application\ValidationErrors;
use App\Modules\Ingesta\Domain\Schema\DatasetSchemas;
use App\Modules\Ingesta\Infrastructure\Persistence\IngestaLoad;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
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
 * Checks the size, gunzips, parses the columnar JSON, checks the columns
 * and the row count against the dataset, rebuilds one associative row per
 * value list and hands the batch to IngestaSink, which validates it against
 * the dataset schema and stores it. A malformed request, columns other than
 * the dataset's or more than ingesta.max_rows rows answer 422 without an
 * entry in the load history; a batch that fails the schema answers 422 and
 * is recorded as rejected. Every 422 leaves a warning in the log with why,
 * never a value of the batch. A new accepted batch goes to the audit log as
 * ingesta.batch.accepted when Core binds an AuditLogger.
 */
final class ReceiveBatchController
{
    /**
     * More columns than any dataset has (cartera, the widest, has 38). A
     * longer list is refused before the validator, whose distinct rule
     * compares every column with all the others.
     */
    private const int MAX_COLUMNS = 100;

    /** Characters of an unknown column name quoted back in a 422. */
    private const int QUOTED_COLUMN_LENGTH = 40;

    /** Most error keys one rejection log line lists. */
    private const int LOGGED_FIELDS = 10;

    /**
     * An error key the controller or the schema can produce, like
     * "rows.12.saldo"; any other one (columns.{any key of the caller}) is
     * left out of the log.
     */
    private const string LOGGABLE_FIELD = '/^[a-z_]+(\.\d+(\.[a-z0-9_]+)?)?$/';

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

        $dataset = Dataset::from($lote);
        // What the rejection log says about the batch, filled in as it is
        // read: "reason" names the stage under way.
        $context = ['dataset' => $dataset->value, 'company' => null, 'rows' => null, 'reason' => 'encoding'];

        try {
            // No local copy of the payload: batch() releases its rows as it reads them.
            $batch = $this->batch($dataset, $this->payload($request, $raw, $context), $context);
            $duplicate = IngestaLoad::query()->where('accepted_batch_id', $batch->batchId)->exists();

            $context['reason'] = 'schema';
            $load = $this->sink->receive($batch);
        } catch (ValidationException $e) {
            $this->logRejection($request, $context, $e->errors());

            throw $e;
        } catch (InvalidBatch $e) {
            $this->logRejection($request, $context, $e->errors());

            return new JsonResponse(['message' => $e->getMessage(), 'errors' => $e->errors()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        Log::info('Ingesta: accepted a robot batch.', [
            'dataset' => $load->dataset->value,
            'company' => $load->company->value,
            'rows' => $load->rowCount,
            'batch_id' => $batch->batchId,
            'duplicate' => $duplicate,
        ]);

        if (! $duplicate) {
            $this->audit($load, $batch, (string) $request->header('X-Ingesta-Key'));
        }

        return new JsonResponse(
            ['load' => $this->present($load), 'duplicate' => $duplicate],
            $duplicate ? Response::HTTP_OK : Response::HTTP_CREATED,
        );
    }

    /**
     * Records the new load; $keyId was already verified by VerifyRobotSignature.
     */
    private function audit(LoadRecord $load, IncomingBatch $batch, string $keyId): void
    {
        if (! app()->bound(AuditLogger::class)) {
            return;
        }

        app(AuditLogger::class)->record('ingesta.batch.accepted', 'ingesta_load', $load->id, [], [
            'dataset' => $load->dataset->value,
            'company' => $load->company->value,
            'rows' => $load->rowCount,
            'batch_id' => $batch->batchId,
            'key_id' => $keyId,
        ]);
    }

    /**
     * Logs why a batch answered 422: the dataset, the company and the row
     * count once known, the stage that refused it, the first error keys and
     * the signing key id, already verified by VerifyRobotSignature. Never a
     * value of the batch nor an error message, which can quote them.
     *
     * @param  array{dataset: string, company: string|null, rows: int|null, reason: string}  $context
     * @param  array<string, mixed>  $errors
     */
    private function logRejection(Request $request, array $context, array $errors): void
    {
        $fields = array_filter(array_map(strval(...), array_keys($errors)), fn (string $field): bool => preg_match(self::LOGGABLE_FIELD, $field) === 1);

        Log::warning('Ingesta: rejected a robot batch.', [
            ...$context,
            'fields' => array_slice(array_values($fields), 0, self::LOGGED_FIELDS),
            'key_id' => (string) $request->header('X-Ingesta-Key'),
        ]);
    }

    /**
     * The decoded JSON object of the body, gunzipped when the robot sent
     * Content-Encoding: gzip.
     *
     * @param  array{dataset: string, company: string|null, rows: int|null, reason: string}  $context  Gets the stage under way.
     * @return array<mixed>
     *
     * @throws ValidationException When the body is not a JSON object.
     */
    private function payload(Request $request, string $raw, array &$context): array
    {
        $encoding = strtolower(trim((string) $request->header('Content-Encoding', '')));

        $context['reason'] = 'encoding';

        if (! in_array($encoding, ['', 'identity', 'gzip'], true)) {
            $this->fail('body', __('ingesta::robot.codificacion'));
        }

        if ($encoding === 'gzip') {
            $context['reason'] = 'gzip';
            $maxBytes = (int) config('ingesta.max_payload_bytes');
            $raw = @gzdecode($raw, $maxBytes);

            // gzdecode() checks its limit between output chunks, so it can
            // return a few more bytes than asked; the length is checked again.
            if ($raw === false || strlen($raw) > $maxBytes) {
                $this->fail('body', __('ingesta::robot.gzip_invalido', ['max' => $maxBytes]));
            }
        }

        $context['reason'] = 'json';

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
     * The columns and the row count are checked before any row is read.
     *
     * @param  array<mixed>  $payload
     * @param  array{dataset: string, company: string|null, rows: int|null, reason: string}  $context  Gets the company, the row count and the stage under way.
     *
     * @throws ValidationException When the envelope, its columns, its row count or a row is wrong.
     */
    private function batch(Dataset $dataset, array $payload, array &$context): IncomingBatch
    {
        $context['company'] = is_string($payload['company'] ?? null) ? CompanyCode::tryFrom($payload['company'])?->value : null;
        $context['rows'] = is_array($payload['rows'] ?? null) ? count($payload['rows']) : null;
        $context['reason'] = 'columns';

        if (is_array($payload['columns'] ?? null) && count($payload['columns']) > self::MAX_COLUMNS) {
            $this->fail('columns', __('ingesta::robot.columnas_demasiadas', ['count' => count($payload['columns']), 'max' => self::MAX_COLUMNS]));
        }

        $context['reason'] = 'envelope';

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

        $context['reason'] = 'columns';
        $this->checkColumns($dataset, $columns);

        $context['reason'] = 'too_many_rows';
        $maxRows = (int) config('ingesta.max_rows');

        if (count($payload['rows']) > $maxRows) {
            $this->fail('rows', __('ingesta::validacion.demasiadas_filas', ['filas' => count($payload['rows']), 'max' => $maxRows]));
        }

        $context['reason'] = 'rows';

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
     * Requires exactly the dataset's columns, in any order.
     *
     * @param  list<string>  $columns
     *
     * @throws ValidationException Listing the missing and the unknown columns.
     */
    private function checkColumns(Dataset $dataset, array $columns): void
    {
        $names = DatasetSchemas::for($dataset)->columnNames();
        $messages = [];

        if (($missing = array_diff($names, $columns)) !== []) {
            $messages[] = __('ingesta::robot.columnas_faltantes', ['columnas' => implode(', ', $missing)]);
        }

        if (($unknown = array_diff($columns, $names)) !== []) {
            // The caller chose these names: each one is cut so the answer stays short.
            $unknown = array_map(fn (string $name): string => Str::limit($name, self::QUOTED_COLUMN_LENGTH), $unknown);
            $messages[] = __('ingesta::robot.columnas_desconocidas', ['lote' => mb_strtolower($dataset->label()), 'columnas' => implode(', ', $unknown)]);
        }

        if ($messages !== []) {
            throw ValidationException::withMessages(['columns' => $messages]);
        }
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
