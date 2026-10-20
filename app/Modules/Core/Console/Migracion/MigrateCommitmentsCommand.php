<?php

namespace App\Modules\Core\Console\Migracion;

use App\Modules\Core\Application\Commitments\BreakOverdueCommitments;
use App\Modules\Core\Application\Commitments\CommitmentActivity;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Domain\Commitments\CommitmentRules;
use App\Modules\Core\Infrastructure\Persistence\CommitmentRecord;
use Carbon\CarbonImmutable;

/**
 * core:migrar-compromisos: imports the payment commitments of Cartera Fase 1
 * (the fase1_actions.csv rows with a commitment date, or of type
 * compromiso) and of Supabase (the supabase_gestiones.csv rows of type
 * compromiso) into "commitments", each with the log entry the app writes
 * when a commitment is created (CommitmentActivity::created()), dated when
 * the legacy commitment was created.
 *
 * Fase 1 has no closing status: a commitment due today or later (Bogotá) is
 * pending, an older one is cancelled. Supabase statuses map one to one; an
 * open commitment already past due is broken, as the daily task would have
 * done. The client's status is never touched: core:migrar-estados brings the
 * manual statuses. The author must be an app user (commitments.created_by).
 */
final class MigrateCommitmentsCommand extends MigrationCommand
{
    private const string USERS = 'fase1_users.csv';

    private const string CLIENTS = 'fase1_clients.csv';

    private const string CODES = 'fase1_client_codes.csv';

    private const string ACTIONS = 'fase1_actions.csv';

    private const string NOTES = 'supabase_gestiones.csv';

    private const string NOT_A_COMMITMENT = 'no es un compromiso';

    private const string FASE1_CANCELLATION = 'Importado de Cartera Fase 1 sin estado de cierre.';

    private const string SUPERSEDED = 'Reemplazado por un compromiso posterior en Cartera Fase 1.';

    private const string PORTAL_CANCELLATION = 'Anulado en el portal de asesores.';

    /**
     * @var string
     */
    protected $name = 'core:migrar-compromisos';

    /**
     * @var string
     */
    protected $description = 'Importa los compromisos de pago de Cartera Fase 1 y de Supabase desde los CSV exportados';

    private LegacyClients $clients;

    private LegacyAuthors $authors;

    private LegacyActivities $activities;

    /**
     * Today in the calendar of the due dates, at the start of the run.
     */
    private CarbonImmutable $today;

    /**
     * @var array<int, int> The live Fase 1 promise of each Fase 1 client (latestPromises()).
     */
    private array $latestPromises = [];

    protected function files(): array
    {
        return [
            self::USERS => ['id', 'email'],
            self::CLIENTS => ['id', 'empresa', 'nit'],
            self::CODES => ['client_id', 'codigo_sn'],
            self::ACTIONS => ['id', 'client_id', 'empresa', 'user_id', 'tipo', 'resultado', 'detalle', 'compromiso_fecha', 'compromiso_valor', 'descuento', 'created_at'],
            self::NOTES => ['id', 'codigo_sn', 'slp_code', 'tipo', 'nota', 'fecha_compromiso', 'valor_compromiso', 'estado', 'creado_por', 'creado_en', 'actualizado_en'],
        ];
    }

    protected function checkFiles(array $files): void
    {
        if (! isset($files[self::ACTIONS]) && ! isset($files[self::NOTES])) {
            throw new InvalidCsvFile('No encontré '.self::ACTIONS.' ni '.self::NOTES.': no hay compromisos que importar.');
        }

        if (isset($files[self::ACTIONS]) && ! isset($files[self::CLIENTS])) {
            throw new InvalidCsvFile('Para importar '.self::ACTIONS.' hace falta '.self::CLIENTS.' en la misma carpeta.');
        }
    }

    protected function import(array $files, ImportReport $report): void
    {
        $this->today = CarbonImmutable::now(BreakOverdueCommitments::TIMEZONE);
        $this->clients = LegacyClients::load($files[self::CLIENTS] ?? null, $files[self::CODES] ?? null, $report);
        $this->authors = LegacyAuthors::load($files[self::USERS] ?? null, $report);
        $this->activities = new LegacyActivities(LegacyKeys::ofActivities());

        if (isset($files[self::ACTIONS])) {
            $this->latestPromises = $this->latestPromises($files[self::ACTIONS]);
            $this->eachRow($files[self::ACTIONS], $report, fn (CsvRow $row): bool => $this->fromAction($row));
        }

        if (isset($files[self::NOTES])) {
            $this->eachRow($files[self::NOTES], $report, fn (CsvRow $row): bool => $this->fromNote($row));
        }

        $this->activities->advanceLastActivity();
    }

    /**
     * The Fase 1 promise of each Fase 1 client that is still alive: Fase 1
     * only honoured a client's latest promise, so among the commitment rows
     * due today or later only the one created last (then the highest id)
     * stays pending. Rows that cannot be imported do not count.
     *
     * @return array<int, int> Fase 1 client id => Fase 1 action id.
     */
    private function latestPromises(CsvFile $actions): array
    {
        $latest = [];

        foreach ($actions->rows() as $row) {
            try {
                $row->assertReadable();
                $action = $this->parseAction($row);
            } catch (RowRejected|RowSkipped) {
                continue;
            }

            if (CommitmentRules::isBroken(CommitmentStatus::Pending, $action['dueDate'], $this->today)) {
                continue;
            }

            $current = $latest[$action['legacyClient']] ?? null;
            $later = $current === null
                || $action['createdAt']->greaterThan($current['createdAt'])
                || ($action['createdAt']->equalTo($current['createdAt']) && $action['id'] > $current['id']);

            if ($later) {
                $latest[$action['legacyClient']] = ['createdAt' => $action['createdAt'], 'id' => $action['id']];
            }
        }

        return array_map(fn (array $promise): int => $promise['id'], $latest);
    }

    /**
     * @throws RowRejected
     * @throws RowSkipped
     */
    private function fromAction(CsvRow $row): bool
    {
        $action = $this->parseAction($row);
        $key = LegacyKeys::key(LegacySource::Fase1, 'compromiso', (string) $action['id']);
        $this->skipImported($key);

        $closing = match (true) {
            CommitmentRules::isBroken(CommitmentStatus::Pending, $action['dueDate'], $this->today) => self::FASE1_CANCELLATION,
            ($this->latestPromises[$action['legacyClient']] ?? null) !== $action['id'] => self::SUPERSEDED,
            default => null,
        };

        $this->save($key, $action['client'], $action['author'], $action['createdAt'], [
            'advisor_code' => $action['client']->advisorCode,
            'amount' => $action['amount'],
            'due_date' => $action['dueDate']->toDateString(),
            'status' => $closing === null ? CommitmentStatus::Pending : CommitmentStatus::Cancelled,
            'notes' => $action['notes'],
            'cancelled_at' => $closing === null ? null : now(),
            'cancellation_reason' => $closing,
        ], $action['discount']);

        return true;
    }

    /**
     * Reads a fase1_actions.csv row as a commitment, without writing.
     *
     * @return array{id: int, legacyClient: int, client: TargetClient, author: int, createdAt: CarbonImmutable, dueDate: CarbonImmutable, amount: string, notes: string|null, discount: float|null}
     *
     * @throws RowRejected
     * @throws RowSkipped When the row is not a commitment.
     */
    private function parseAction(CsvRow $row): array
    {
        $type = mb_strtolower($row->optionalString('tipo') ?? '');
        // In Fase 1 a compromiso_fecha means a commitment, so here a date that
        // cannot be read rejects the row (core:migrar-gestiones keeps it as text).
        $dueDate = $row->optionalDate('compromiso_fecha');

        if ($type !== 'compromiso' && $dueDate === null) {
            throw new RowSkipped(self::NOT_A_COMMITMENT);
        }

        $id = $row->int('id');

        if ($dueDate === null) {
            throw new RowRejected('compromiso sin fecha');
        }

        $amount = self::amount($row->optionalDecimal('compromiso_valor'));
        $createdAt = $row->timestamp('created_at');
        $client = $this->clients->ofAction($row);
        $author = self::person($this->authors->fase1($row->optionalInt('user_id')));
        $result = $row->optionalString('resultado');

        return [
            'id' => $id,
            'legacyClient' => $row->int('client_id'),
            'client' => $client,
            'author' => $author,
            'createdAt' => $createdAt,
            'dueDate' => $dueDate,
            'amount' => $amount,
            'notes' => $type === 'llamada' && $result !== null
                ? implode("\n", array_filter(["Llamada: {$result}", $row->optionalString('detalle')]))
                : $row->optionalString('detalle'),
            'discount' => self::discount($row->optionalDecimal('descuento')),
        ];
    }

    /**
     * @throws RowRejected
     * @throws RowSkipped
     */
    private function fromNote(CsvRow $row): bool
    {
        if ($row->oneOf('tipo', ['nota', 'compromiso']) !== 'compromiso') {
            throw new RowSkipped(self::NOT_A_COMMITMENT);
        }

        $key = LegacyKeys::key(LegacySource::Supabase, 'compromiso', $row->string('id'));
        $this->skipImported($key);

        $dueDate = $row->optionalDate('fecha_compromiso') ?? throw new RowRejected('compromiso sin fecha');
        $amount = self::amount($row->optionalDecimal('valor_compromiso'));
        $client = $this->clients->supabase($row->string('codigo_sn'));
        $createdAt = $row->timestamp('creado_en');
        $createdBy = $row->optionalString('creado_por');
        $author = self::person($createdBy === null ? LegacyAuthor::unknown('sin autor') : $this->authors->email($createdBy));

        $closing = match ($row->oneOf('estado', ['abierto', 'cumplido', 'cancelado'])) {
            'abierto' => CommitmentRules::isBroken(CommitmentStatus::Pending, $dueDate, $this->today)
                ? ['status' => CommitmentStatus::Broken, 'broken_at' => now()]
                : ['status' => CommitmentStatus::Pending],
            'cumplido' => ['status' => CommitmentStatus::Fulfilled, 'fulfilled_at' => $row->timestamp('actualizado_en')],
            default => [
                'status' => CommitmentStatus::Cancelled,
                'cancelled_at' => $row->timestamp('actualizado_en'),
                'cancellation_reason' => self::PORTAL_CANCELLATION,
            ],
        };

        $this->save($key, $client, $author, $createdAt, [
            'advisor_code' => $row->optionalInt('slp_code'),
            'amount' => $amount,
            'due_date' => $dueDate->toDateString(),
            'notes' => $row->optionalString('nota'),
            ...$closing,
        ]);

        return true;
    }

    /**
     * Creates the commitment, dated $createdAt, and its log entry by $author.
     * No side effect on the client's status.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function save(string $key, TargetClient $client, int $author, CarbonImmutable $createdAt, array $attributes, ?float $discount = null): void
    {
        $commitment = new CommitmentRecord([
            'company' => $client->company,
            'client_id' => $client->id,
            'created_by' => $author,
            ...$attributes,
        ]);
        $commitment->created_at = $createdAt;
        $commitment->save();

        $entry = CommitmentActivity::created($commitment);

        $this->activities->save(new LegacyActivity(
            key: $key,
            client: $client,
            type: $entry->type,
            summary: $entry->summary,
            details: $entry->details,
            result: $entry->result,
            author: LegacyAuthor::user($author),
            occurredAt: $createdAt,
            metadata: [...$entry->metadata, 'discount_amount' => $discount],
        ));
    }

    /**
     * The app user who created the commitment; commitments.created_by is
     * never empty, so a legacy author the app does not have rejects the row.
     *
     * @throws RowRejected
     */
    private static function person(LegacyAuthor $author): int
    {
        return $author->userId ?? throw new RowRejected(
            'el autor no existe en la app: '.($author->legacy ?? 'sin autor').'; corre primero core:migrar-usuarios',
        );
    }

    /**
     * @throws RowRejected When there is no amount above zero.
     */
    private static function amount(?string $amount): string
    {
        if ($amount === null || (float) $amount <= 0) {
            throw new RowRejected('compromiso sin valor');
        }

        return $amount;
    }

    private static function discount(?string $discount): ?float
    {
        return $discount === null ? null : (float) $discount;
    }

    /**
     * @throws RowSkipped When the row with $key is already in the app.
     */
    private function skipImported(string $key): void
    {
        if ($this->activities->has($key)) {
            throw new RowSkipped('ya importado');
        }
    }
}
