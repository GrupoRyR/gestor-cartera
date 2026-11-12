<?php

namespace App\Modules\Core\Infrastructure\Ingest;

use App\Modules\Core\Contracts\Data\LoadRecord;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Events\BatchAccepted;
use App\Modules\Core\Contracts\IngestaSink;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Persistence\ContactRecord;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Log;

/**
 * Imports a contactos batch accepted by Ingesta: the SAP contacts (OCPR) and
 * the general row (OCRD) of each client of the batch's company, linked by
 * (company, code).
 *
 * The SAP contacts of the company's live clients are replaced as a whole;
 * the ones made on the platform (source other than sap) and the ones of
 * soft-deleted clients are never touched. Rows of unknown or soft-deleted
 * clients are skipped and logged once per batch.
 *
 * Idempotent: the same batch twice leaves the same rows. A batch that is no
 * longer the company's latest (an older one retried late) is skipped, and so
 * is an empty one, a failed extraction rather than clients without contacts.
 */
final class ImportContacts implements ShouldQueue
{
    /** Rows written per statement. */
    private const int CHUNK = 500;

    /** At most this many skipped client codes go to the log. */
    private const int LOGGED_CODES = 20;

    public function __construct(
        private readonly IngestaSink $sink,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * Only contactos batches are queued.
     */
    public function shouldQueue(BatchAccepted $event): bool
    {
        return $event->load->dataset === Dataset::Contacts;
    }

    public function handle(BatchAccepted $event): void
    {
        if (! $this->shouldQueue($event) || ! $this->isLatest($event->load)) {
            return;
        }

        $company = $event->load->company;
        $rows = $this->rowsByClient($event->load);

        if ($rows === []) {
            return;
        }

        $clientIds = ClientRecord::query()->where('company', $company)->pluck('id', 'code')->all();
        $contacts = [];
        $skipped = [];
        $now = now();

        foreach ($rows as $code => $clientRows) {
            $clientId = $clientIds[$code] ?? null;

            if ($clientId === null) {
                $skipped[$code] = count($clientRows);

                continue;
            }

            foreach (ContactRow::resolve($clientRows) as $contact) {
                $contacts[] = ['client_id' => $clientId, 'source' => 'sap', 'created_at' => $now, 'updated_at' => $now] + $contact;
            }
        }

        $this->db->transaction(function () use ($clientIds, $contacts): void {
            foreach (array_chunk(array_values($clientIds), self::CHUNK) as $ids) {
                ContactRecord::query()->whereIn('client_id', $ids)->where('source', 'sap')->delete();
            }

            foreach (array_chunk($contacts, self::CHUNK) as $chunk) {
                ContactRecord::query()->insert($chunk);
            }
        });

        $this->logSkipped($company, $skipped);
    }

    /**
     * Whether $load is still the company's current contactos snapshot.
     */
    private function isLatest(LoadRecord $load): bool
    {
        return $this->sink->latest(Dataset::Contacts, $load->company)?->id === $load->id;
    }

    /**
     * The active rows of the batch, by client code, in batch order.
     *
     * @return array<string, list<ContactRow>>
     */
    private function rowsByClient(LoadRecord $load): array
    {
        $rows = [];

        foreach ($this->sink->rows($load->id) as $row) {
            $contact = ContactRow::read($row);

            if ($contact !== null) {
                $rows[$contact->clientCode][] = $contact;
            }
        }

        return $rows;
    }

    /**
     * One warning per batch with the rows whose client is unknown or
     * soft-deleted, and the first of their codes.
     *
     * @param  array<string, int>  $skipped  Skipped rows by client code.
     */
    private function logSkipped(CompanyCode $company, array $skipped): void
    {
        if ($skipped === []) {
            return;
        }

        $codes = array_map('strval', array_keys($skipped));
        sort($codes);

        Log::warning('Contactos rows skipped: their client is unknown or removed.', [
            'company' => $company->value,
            'skipped_rows' => array_sum($skipped),
            'client_codes' => array_slice($codes, 0, self::LOGGED_CODES),
        ]);
    }
}
