<?php

namespace App\Modules\Core\Console\Migracion;

use App\Modules\Core\Application\Activity\StatusChangeSummary;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;

/**
 * core:migrar-gestiones: imports the activity log of Cartera Fase 1
 * (fase1_actions.csv, with fase1_clients.csv, fase1_client_codes.csv and
 * fase1_users.csv to find clients and authors) and the notes of Supabase
 * (supabase_gestiones.csv) into "activities". Commitments are left to
 * core:migrar-compromisos.
 *
 * Summaries, details and results follow what the app writes for each type
 * (see the mapping in README.md): the result only for calls, the first line
 * of the text as the summary of a note. An author the app does not have
 * leaves the entry without author, named in metadata.legacy_author, with a
 * warning.
 */
final class MigrateActivitiesCommand extends MigrationCommand
{
    private const string USERS = 'fase1_users.csv';

    private const string CLIENTS = 'fase1_clients.csv';

    private const string CODES = 'fase1_client_codes.csv';

    private const string ACTIONS = 'fase1_actions.csv';

    private const string NOTES = 'supabase_gestiones.csv';

    private const array FASE1_TYPES = ['llamada', 'correo', 'whatsapp', 'nota', 'documento', 'compromiso', 'pago', 'sistema'];

    private const string LATER = 'se importa con core:migrar-compromisos';

    /**
     * @var string
     */
    protected $name = 'core:migrar-gestiones';

    /**
     * @var string
     */
    protected $description = 'Importa las gestiones de Cartera Fase 1 y las notas de Supabase desde los CSV exportados';

    private LegacyClients $clients;

    private LegacyAuthors $authors;

    private LegacyActivities $activities;

    private ImportReport $report;

    protected function files(): array
    {
        return [
            self::USERS => ['id', 'email'],
            self::CLIENTS => ['id', 'empresa', 'nit'],
            self::CODES => ['client_id', 'codigo_sn'],
            self::ACTIONS => ['id', 'client_id', 'empresa', 'user_id', 'tipo', 'resultado', 'detalle', 'compromiso_fecha', 'compromiso_valor', 'descuento', 'created_at'],
            self::NOTES => ['id', 'codigo_sn', 'documento', 'tipo', 'nota', 'creado_por', 'creado_en'],
        ];
    }

    protected function optionalColumns(): array
    {
        return [self::ACTIONS => ['adjunto_nombre']];
    }

    protected function checkFiles(array $files): void
    {
        if (! isset($files[self::ACTIONS]) && ! isset($files[self::NOTES])) {
            throw new InvalidCsvFile('No encontré '.self::ACTIONS.' ni '.self::NOTES.': no hay gestiones que importar.');
        }

        if (isset($files[self::ACTIONS]) && ! isset($files[self::CLIENTS])) {
            throw new InvalidCsvFile('Para importar '.self::ACTIONS.' hace falta '.self::CLIENTS.' en la misma carpeta.');
        }
    }

    protected function import(array $files, ImportReport $report): void
    {
        $this->report = $report;
        $this->clients = LegacyClients::load($files[self::CLIENTS] ?? null, $files[self::CODES] ?? null, $report);
        $this->authors = LegacyAuthors::load($files[self::USERS] ?? null, $report);
        $this->activities = new LegacyActivities(LegacyKeys::ofActivities());

        if (isset($files[self::ACTIONS])) {
            $this->eachRow($files[self::ACTIONS], $report, fn (CsvRow $row): bool => $this->save($row, $this->fromAction($row)));
        }

        if (isset($files[self::NOTES])) {
            $this->eachRow($files[self::NOTES], $report, fn (CsvRow $row): bool => $this->save($row, $this->fromNote($row)));
        }

        $this->activities->advanceLastActivity();
    }

    private function save(CsvRow $row, LegacyActivity $activity): bool
    {
        if ($activity->author->isUnknown()) {
            $this->report->warn($row, "no encontré al autor ({$activity->author->legacy}) en la app; la gestión queda sin autor");
        }

        $this->activities->save($activity);

        return true;
    }

    /**
     * @throws RowRejected
     * @throws RowSkipped
     */
    private function fromAction(CsvRow $row): LegacyActivity
    {
        $type = $row->oneOf('tipo', self::FASE1_TYPES);

        if ($type === 'compromiso') {
            throw new RowSkipped(self::LATER);
        }

        $key = LegacyKeys::key(LegacySource::Fase1, 'actions', (string) $row->int('id'));
        $this->skipImported($key);

        $company = CompanyCode::from($row->oneOf('empresa', ['SGX', 'SDC']));
        $occurredAt = $row->timestamp('created_at');
        $client = $this->clients->fase1($row->int('client_id'));

        if ($client->company !== $company) {
            throw new RowRejected("la empresa de la gestión ({$company->value}) no coincide con la del cliente ({$client->company->value})");
        }

        $author = $this->authors->fase1($row->optionalInt('user_id'));
        $result = $row->optionalString('resultado');
        $detail = $row->optionalString('detalle');
        $metadata = [
            'legacy_author' => $author->legacy,
            'discount_amount' => $this->extra($row, 'descuento', fn (): ?float => self::amount($row->optionalDecimal('descuento'))),
            'commitment_due_date' => $this->extra($row, 'compromiso_fecha', fn (): ?string => $row->optionalDate('compromiso_fecha')?->toDateString()),
            'commitment_amount' => $this->extra($row, 'compromiso_valor', fn (): ?float => self::amount($row->optionalDecimal('compromiso_valor'))),
        ];

        [$activityType, $summary, $details, $callResult, $extra] = match ($type) {
            'llamada' => [ActivityType::Call, $result === null ? 'Llamada' : "Llamada: {$result}", $detail, $result, []],
            'correo' => [ActivityType::EmailSent, $result ?? self::firstLine($detail) ?? ActivityType::EmailSent->label(), $detail, null, []],
            'whatsapp' => [ActivityType::Note, self::prefixed('WhatsApp', $result ?? self::firstLine($detail)), $detail, null, []],
            'nota' => [ActivityType::Note, $result ?? self::firstLine($detail) ?? ActivityType::Note->label(), $detail, null, []],
            'documento' => $this->document($row, $detail),
            'pago' => [ActivityType::Note, self::prefixed('Pago registrado', $result ?? self::firstLine($detail)), $detail, null, []],
            default => $this->system($result, $detail),
        };

        $summary = LegacyActivity::summaryOf($summary);

        // Nothing of the row is lost: a result that is neither the call's
        // outcome nor in the summary goes to metadata («Cambio de estado»
        // is what the type already says).
        $said = $callResult !== null || $activityType === ActivityType::StatusChange || str_contains($summary, LegacyActivity::summaryOf($result ?? ''));

        if ($result !== null && ! $said) {
            $extra['legacy_result'] = $result;
        }

        return new LegacyActivity(
            key: $key,
            client: $client,
            type: $activityType,
            summary: $summary,
            details: $details === $summary ? null : $details,
            result: $callResult === null ? null : mb_substr($callResult, 0, LegacyActivity::RESULT_LENGTH),
            author: $author,
            occurredAt: $occurredAt,
            metadata: [...$metadata, ...$extra],
        );
    }

    /**
     * @throws RowRejected
     * @throws RowSkipped
     */
    private function fromNote(CsvRow $row): LegacyActivity
    {
        if ($row->oneOf('tipo', ['nota', 'compromiso']) === 'compromiso') {
            throw new RowSkipped(self::LATER);
        }

        $key = LegacyKeys::key(LegacySource::Supabase, 'gestiones', $row->string('id'));
        $this->skipImported($key);

        $client = $this->clients->supabase($row->string('codigo_sn'));
        $occurredAt = $row->timestamp('creado_en');
        $note = $row->string('nota');
        $createdBy = $row->optionalString('creado_por');
        $author = $createdBy === null ? LegacyAuthor::unknown('sin autor') : $this->authors->email($createdBy);
        $summary = LegacyActivity::summaryOf($note);

        return new LegacyActivity(
            key: $key,
            client: $client,
            type: ActivityType::Note,
            summary: $summary,
            details: $note === $summary ? null : $note,
            result: null,
            author: $author,
            occurredAt: $occurredAt,
            metadata: ['legacy_author' => $author->legacy, 'document' => $row->optionalString('documento')],
        );
    }

    /**
     * A document: its file is not migrated, only its name and description.
     *
     * @return array{ActivityType, string, string|null, null, array<string, scalar|null>}
     */
    private function document(CsvRow $row, ?string $detail): array
    {
        $name = $row->optionalString('adjunto_nombre');

        return [
            ActivityType::Note,
            self::prefixed('Documento', $name ?? self::firstLine($detail) ?? 'sin nombre'),
            $detail,
            null,
            ['attachment_name' => $name],
        ];
    }

    /**
     * A status change of Fase 1 («De «vencida» a «juridico»») is written
     * like a manual one of the app when both statuses are known; any other
     * entry of the system stays a System entry.
     *
     * @return array{ActivityType, string, string|null, null, array<string, scalar|null>}
     */
    private function system(?string $result, ?string $detail): array
    {
        if ($result === null || mb_strtolower($result) !== 'cambio de estado') {
            return [ActivityType::System, $result ?? self::firstLine($detail) ?? ActivityType::System->label(), $detail, null, []];
        }

        if (preg_match('/^De «(.+?)» a «(.+?)»$/u', $detail ?? '', $states) === 1) {
            $from = ClientStatus::tryFrom($states[1]);
            $to = ClientStatus::tryFrom($states[2]);

            if ($from !== null && $to !== null) {
                return [ActivityType::StatusChange, StatusChangeSummary::manual($from, $to), null, null, ['from' => $from->value, 'to' => $to->value]];
            }
        }

        return [ActivityType::StatusChange, self::firstLine($detail) ?? $result, $detail, null, []];
    }

    /**
     * @throws RowSkipped When the row with $key is already in the log.
     */
    private function skipImported(string $key): void
    {
        if ($this->activities->has($key)) {
            throw new RowSkipped('ya importado');
        }
    }

    private static function firstLine(?string $text): ?string
    {
        return $text === null ? null : LegacyActivity::summaryOf($text);
    }

    private static function prefixed(string $prefix, ?string $text): string
    {
        return $text === null ? $prefix : "{$prefix}: {$text}";
    }

    /**
     * An extra of the entry (discount, commitment date or amount): only
     * metadata here, so a value $read cannot parse never rejects the
     * entry. It is kept as the text written, with a warning.
     *
     * @param  callable(): (float|string|null)  $read
     */
    private function extra(CsvRow $row, string $column, callable $read): float|string|null
    {
        try {
            return $read();
        } catch (RowRejected) {
            $raw = (string) $row->optionalString($column);
            $what = $column === 'compromiso_fecha' ? 'una fecha válida' : 'un número';
            $this->report->warn($row, "{$column} no es {$what} ({$raw}); se guarda como texto");

            return $raw;
        }
    }

    private static function amount(?string $decimal): ?float
    {
        return $decimal === null ? null : (float) $decimal;
    }
}
