<?php

namespace App\Modules\Comisiones\Infrastructure\Ingest;

use App\Modules\Comisiones\Infrastructure\Audit\AuditTrail;
use App\Modules\Comisiones\Infrastructure\Persistence\ClientAssignmentRecord;

/**
 * Keeps the history of the seller assigned to each client (Report 5) from
 * the successive client masters, so a crossing goes to the seller in force
 * on its date (§5.4):
 *
 * - a client seen for the first time gets its seller from FIRST_SIGHTING on,
 *   so the first master also covers the past;
 * - a client whose seller changed gets a new assignment from its modification
 *   date, or from the load's cutoff date when SAP gives none;
 * - a client with the same seller adds nothing.
 *
 * History is never rewritten: a change dated on or before the start of the
 * assignment in force starts at the later of the day after that start and
 * the cutoff of the load that brings it, so the seller in force keeps its
 * crossings up to the day the change was seen. It is audited as
 * comisiones.assignment.start_moved with the date SAP gave and the one
 * applied. Any other split is corrected by hand with support (§5.4).
 */
final class AssignmentHistory
{
    /** Start of the first assignment of a client. */
    public const string FIRST_SIGHTING = '2000-01-01';

    public function __construct(
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @param  list<array<string, scalar|null>>  $clients  comisiones_clientes rows (StagingRows::client()).
     * @param  string  $cutoff  The load's cutoff date, "Y-m-d".
     */
    public function record(array $clients, string $cutoff, int $loadId): void
    {
        $current = $this->current(array_map(fn (array $client): string => (string) $client['codigo_cliente'], $clients));
        $started = [];

        foreach ($clients as $client) {
            $code = (string) $client['codigo_cliente'];
            $seller = (int) $client['slp_code'];
            $inForce = $current[$code] ?? null;

            if ($inForce === null) {
                $started[] = $this->start($code, $seller, self::FIRST_SIGHTING, $loadId);

                continue;
            }

            if ($inForce->slp_code === $seller) {
                continue;
            }

            $from = (string) ($client['fecha_modificacion'] ?? $cutoff);

            if ($from <= $inForce->vigente_desde->format('Y-m-d')) {
                $moved = max($inForce->vigente_desde->addDay()->format('Y-m-d'), $cutoff);
                $this->audit->record(
                    event: 'comisiones.assignment.start_moved',
                    subjectType: 'cliente',
                    subjectId: $code,
                    changes: ['slp_code' => ['old' => $inForce->slp_code, 'new' => $seller]],
                    context: ['fecha_sap' => $from, 'vigente_desde' => $moved, 'load_id' => $loadId],
                    period: substr($moved, 0, 7),
                );
                $from = $moved;
            }

            $started[] = $this->start($code, $seller, $from, $loadId);
        }

        foreach (array_chunk($started, 500) as $chunk) {
            ClientAssignmentRecord::query()->insert($chunk);
        }
    }

    /**
     * The assignment in force (latest start) of each client.
     *
     * @param  list<string>  $codes
     * @return array<string, ClientAssignmentRecord>
     */
    private function current(array $codes): array
    {
        $current = [];

        $rows = ClientAssignmentRecord::query()
            ->whereIn('codigo_cliente', $codes)
            ->orderBy('vigente_desde')
            ->get();

        foreach ($rows as $row) {
            $current[$row->codigo_cliente] = $row;
        }

        return $current;
    }

    /**
     * A new assignment row, inserted in bulk.
     *
     * @return array<string, scalar>
     */
    private function start(string $code, int $seller, string $from, int $loadId): array
    {
        $now = now()->format('Y-m-d H:i:s');

        return [
            'codigo_cliente' => $code,
            'slp_code' => $seller,
            'vigente_desde' => $from,
            'load_id' => $loadId,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
