<?php

namespace App\Modules\Cobro\Infrastructure\Exclusions;

use App\Modules\Cobro\Infrastructure\Persistence\Exclusion;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\Data\ClientSummary;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * The exclusion list: adds, updates and removes clients, writing each
 * change to the audit log ("cobro.exclusion.created|updated|deleted"), and
 * answers which clients are excluded on a day. Callers authorize and check
 * the client scope; this class trusts the clients it receives.
 */
final class ExclusionList
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ConnectionInterface $database,
    ) {}

    /**
     * Excludes each client with this reason and expiry; a client already in
     * the list gets the new reason and expiry.
     *
     * @param  list<ClientSummary>  $clients
     * @return int How many clients were added or updated.
     */
    public function exclude(array $clients, string $reason, ?CarbonImmutable $expiresOn, ?int $userId): int
    {
        $reason = trim($reason);

        return $this->database->transaction(function () use ($clients, $reason, $expiresOn, $userId): int {
            foreach ($clients as $client) {
                $exclusion = Exclusion::query()->firstOrNew(['client_id' => $client->id]);
                $before = $exclusion->exists ? ['reason' => $exclusion->reason, 'expires_on' => $exclusion->expires_on?->format('Y-m-d')] : null;

                $exclusion->fill([
                    'company' => $client->company,
                    'client_code' => mb_substr($client->code, 0, 50),
                    'client_name' => mb_substr($client->name, 0, 255),
                    'tax_id' => mb_substr($client->taxId, 0, 50),
                    'reason' => $reason,
                    'expires_on' => $expiresOn,
                    'created_by' => $before === null ? $userId : $exclusion->created_by,
                ])->save();

                $after = ['reason' => $reason, 'expires_on' => $expiresOn?->format('Y-m-d')];

                $this->audit->record(
                    $before === null ? 'cobro.exclusion.created' : 'cobro.exclusion.updated',
                    'cobro_exclusion',
                    $exclusion->id,
                    $before === null ? $after : self::changes($before, $after),
                    ['client_id' => $client->id, 'company' => $client->company->value],
                );
            }

            return count($clients);
        });
    }

    /**
     * Removes these exclusions, limited to the given companies.
     *
     * @param  list<int>  $exclusionIds
     * @param  list<CompanyCode>  $companies
     * @return int How many were removed.
     */
    public function remove(array $exclusionIds, array $companies): int
    {
        return $this->database->transaction(function () use ($exclusionIds, $companies): int {
            $exclusions = Exclusion::query()
                ->whereKey($exclusionIds)
                ->whereIn('company', array_map(fn (CompanyCode $company): string => $company->value, $companies))
                ->get();

            foreach ($exclusions as $exclusion) {
                $exclusion->delete();

                $this->audit->record('cobro.exclusion.deleted', 'cobro_exclusion', $exclusion->id, [
                    'reason' => $exclusion->reason,
                    'expires_on' => $exclusion->expires_on?->format('Y-m-d'),
                ], ['client_id' => $exclusion->client_id, 'company' => $exclusion->company->value]);
            }

            return $exclusions->count();
        });
    }

    /**
     * Client id => exclusion of the clients of $company excluded on $today.
     *
     * @return array<int, Exclusion>
     */
    public function activeFor(CompanyCode $company, CarbonImmutable $today): array
    {
        return Exclusion::query()
            ->where('company', $company)
            ->activeOn($today)
            ->get()
            ->keyBy('client_id')
            ->all();
    }

    /**
     * @param  array<string, string|null>  $before
     * @param  array<string, string|null>  $after
     * @return array<string, array{old: string|null, new: string|null}>
     */
    private static function changes(array $before, array $after): array
    {
        $changes = [];

        foreach ($after as $field => $value) {
            if ($before[$field] !== $value) {
                $changes[$field] = ['old' => $before[$field], 'new' => $value];
            }
        }

        return $changes;
    }
}
