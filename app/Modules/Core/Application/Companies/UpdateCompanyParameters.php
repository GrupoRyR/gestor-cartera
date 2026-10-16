<?php

namespace App\Modules\Core\Application\Companies;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Domain\Status\StatusParameters;
use App\Modules\Core\Infrastructure\Companies\CompanyCatalog;
use App\Modules\Core\Infrastructure\Persistence\CompanyRecord;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * Saves the parameters of a company from the Parámetros screen: its grace
 * days and its due-soon window.
 */
final class UpdateCompanyParameters
{
    public function __construct(
        private readonly CompanyCatalog $catalog,
        private readonly AuditLogger $audit,
        private readonly ConnectionInterface $db,
        private readonly Dispatcher $bus,
    ) {}

    /**
     * Saves $parameters as those of $company. When something changes:
     *
     * 1. updates the company's row, with the row locked (a missing row is
     *    created with the CompanyCode label as its name);
     * 2. audits core.company.updated (subject company, id = code) with only
     *    the changed fields, as field => ['old' => …, 'new' => …];
     * 3. refreshes CompanyCatalog, so the rest of the request reads the new
     *    values;
     * 4. queues RecomputeCompanyStatuses for the company.
     *
     * When nothing changes it writes, audits and queues nothing.
     *
     * @return bool Whether anything changed.
     */
    public function update(CompanyCode $company, StatusParameters $parameters): bool
    {
        $changed = $this->db->transaction(function () use ($company, $parameters): bool {
            $row = CompanyRecord::query()->lockForUpdate()->find($company->value);
            $current = $row === null
                ? $this->catalog->parameters($company)
                : new StatusParameters($row->grace_days, $row->due_soon_days);

            $changes = array_filter([
                'grace_days' => [$current->graceDays, $parameters->graceDays],
                'due_soon_days' => [$current->dueSoonDays, $parameters->dueSoonDays],
            ], fn (array $values): bool => $values[0] !== $values[1]);

            if ($changes === []) {
                return false;
            }

            $row ??= new CompanyRecord(['code' => $company, 'name' => $company->label()]);
            $row->fill(['grace_days' => $parameters->graceDays, 'due_soon_days' => $parameters->dueSoonDays])->save();

            $this->audit->record(
                'core.company.updated',
                'company',
                $company->value,
                array_map(fn (array $values): array => ['old' => $values[0], 'new' => $values[1]], $changes),
            );

            return true;
        });

        if ($changed) {
            // Refreshed before the job is queued: with the sync queue (tests) it runs right away.
            $this->catalog->refresh();
            $this->bus->dispatch(new RecomputeCompanyStatuses($company));
        }

        return $changed;
    }
}
