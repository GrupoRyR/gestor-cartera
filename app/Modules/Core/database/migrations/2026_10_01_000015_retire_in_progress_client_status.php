<?php

use App\Modules\Core\Application\Portfolio\RecomputeClientStatuses;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Infrastructure\Persistence\ActivityRecord;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retires the client status «En gestión» (en_gestion): every client that has
 * it, soft-deleted ones included, moves to its automatic status from the
 * latest snapshot of its company (RecomputeClientStatuses::automaticStatus(),
 * resolved as from a manual status, so a client that owes nothing becomes
 * «Pago recibido»).
 *
 * Each move is written like any other status change by the system: status
 * and status_changed_at, a «Cambio de estado» entry in the client's log with
 * no user (inserted directly, so it does not move the client's last
 * activity) and the audit entry core.client.status_changed, recorded as the
 * system because a migration runs without a signed-in user. Existing audit
 * rows, log entries and their texts stay as they are.
 *
 * The status is read and compared as raw text, never through the
 * ClientStatus case, which a later change removes. A client is moved only
 * while it still has en_gestion, under a lock of its row, so running the
 * migration again changes nothing.
 */
return new class extends Migration
{
    private const string RETIRED = 'en_gestion';

    /** The label the status had, kept here once the case is gone. */
    private const string RETIRED_LABEL = 'En gestión';

    private const string SOURCE = 'retire_in_progress';

    private const int CHUNK = 500;

    public function up(): void
    {
        $statuses = app(RecomputeClientStatuses::class);
        $audit = app(AuditLogger::class);

        ClientRecord::withTrashed()
            ->where('status', self::RETIRED)
            ->select('id')
            ->chunkById(self::CHUNK, function ($clients) use ($statuses, $audit): void {
                foreach ($clients as $client) {
                    DB::transaction(fn () => $this->retire((int) $client->id, $statuses, $audit));
                }
            });
    }

    /**
     * Nothing to undo: which clients had en_gestion is in the log and the
     * audit trail, and the status no longer exists to go back to.
     */
    public function down(): void {}

    private function retire(int $clientId, RecomputeClientStatuses $statuses, AuditLogger $audit): void
    {
        $client = ClientRecord::withTrashed()
            ->whereKey($clientId)
            ->where('status', self::RETIRED)
            ->lockForUpdate()
            ->first(['id', 'company']);

        if ($client === null) {
            return;
        }

        $status = $statuses->automaticStatus($client);
        $now = now();

        ClientRecord::withTrashed()->whereKey($clientId)->update(['status' => $status->value, 'status_changed_at' => $now]);

        // StatusChangeSummary::manual() wording («De «En gestión» a «…»»), from the kept label.
        $key = 'core::bitacora.resumen.estado_manual';
        $summary = __($key, ['anterior' => self::RETIRED_LABEL, 'nuevo' => $status->label()]);

        // A bulk insert, like RecomputeClientStatuses: a system entry never moves last_activity_at.

        ActivityRecord::query()->insert([
            'company' => $client->company->value,
            'client_id' => $clientId,
            'type' => ActivityType::StatusChange->value,
            'summary' => is_string($summary) ? $summary : $key,
            'user_id' => null,
            'occurred_at' => $now,
            'metadata' => json_encode(['from' => self::RETIRED, 'to' => $status->value, 'source' => self::SOURCE], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $audit->record(
            'core.client.status_changed',
            'client',
            $clientId,
            ['status' => ['old' => self::RETIRED, 'new' => $status->value]],
            ['source' => self::SOURCE],
        );
    }
};
