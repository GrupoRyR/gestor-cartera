<?php

namespace App\Modules\Core\Infrastructure\Ingest;

use App\Models\User;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Events\BatchAccepted;
use App\Modules\Core\Contracts\IngestaSink;
use App\Modules\Core\Infrastructure\Persistence\AdvisorRecord;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\ConnectionInterface;

/**
 * Imports an asesores batch accepted by Ingesta: the SAP sales employees
 * (OSLP) of the batch's company, by (company, code). Each one gets its name,
 * its e-mail (trimmed and in lowercase; blank is none) and whether it is
 * active ("activo" = Y). The advisors of that company missing from the
 * batch become inactive.
 *
 * Never touches user_id: sign-in and the Equipo screen link advisors to
 * users. Idempotent: the same batch twice leaves the same rows.
 */
final class ImportAdvisors implements ShouldQueue
{
    /** Rows written per statement. */
    private const int CHUNK = 500;

    public function __construct(
        private readonly IngestaSink $sink,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * Only asesores batches are queued.
     */
    public function shouldQueue(BatchAccepted $event): bool
    {
        return $event->load->dataset === Dataset::Advisors;
    }

    public function handle(BatchAccepted $event): void
    {
        if (! $this->shouldQueue($event)) {
            return;
        }

        $company = $event->load->company;
        $advisors = [];

        foreach ($this->sink->rows($event->load->id) as $row) {
            $code = $row['slp_code'] ?? null;

            // SAP uses -1 for "no sales employee".
            if ($code === null || (int) $code < 0) {
                continue;
            }

            $code = (int) $code;
            $name = trim((string) ($row['nombre'] ?? ''));
            $email = trim((string) ($row['email'] ?? ''));

            $advisors[$code] = [
                'company' => $company->value,
                'code' => $code,
                'name' => $name === '' ? "Asesor {$code}" : $name,
                'email' => $email === '' ? null : User::normalizeEmail($email),
                'active' => strtoupper(trim((string) ($row['activo'] ?? ''))) === 'Y',
            ];
        }

        // A batch without a single advisor is a failed extraction, not a
        // company that lost every salesperson: deactivating them all would
        // follow from whereNotIn('code', []), which matches every row.
        if ($advisors === []) {
            return;
        }

        $this->db->transaction(function () use ($company, $advisors): void {
            foreach (array_chunk(array_values($advisors), self::CHUNK) as $chunk) {
                AdvisorRecord::query()->upsert($chunk, ['company', 'code'], ['name', 'email', 'active']);
            }

            AdvisorRecord::query()
                ->where('company', $company)
                ->whereNotIn('code', array_keys($advisors))
                ->where('active', true)
                ->update(['active' => false]);
        });
    }
}
