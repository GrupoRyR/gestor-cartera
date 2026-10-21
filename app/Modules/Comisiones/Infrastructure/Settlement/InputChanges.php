<?php

namespace App\Modules\Comisiones\Infrastructure\Settlement;

use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Infrastructure\Persistence\AuditRecord;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whether what a settlement of the period reads changed after it was
 * calculated, so that approving or closing it would sign off figures that
 * leave out an approved capture, a budget change or a new parameter version.
 *
 * The audit trail already records each of those changes with its moment:
 *
 * - the budget of the period (loaded, edited or deleted) and the manual
 *   captures of the period once approved; pending and rejected captures
 *   never enter a calculation, so they do not count;
 * - a parameter version starting on or before the end of the period: a new
 *   version is always the latest of its key and open-ended, so it covers
 *   the period from its start on.
 *
 * SAP loads are not counted: the robot refreshes the month every day, and
 * the analyst calculates on the definitive extraction (§6 steps 2–4).
 */
final class InputChanges
{
    /** Changes of the period's own data, audited with the period. */
    private const array PERIOD_EVENTS = [
        'comisiones.budget.updated',
        'comisiones.budget.imported',
        'comisiones.budget.deleted',
        'comisiones.capture.approved',
    ];

    private const string PARAMETER_EVENT = 'comisiones.parameter.version_added';

    /** Whether any of those changes was recorded after $calculatedAt. */
    public function since(Period $period, DateTimeImmutable $calculatedAt): bool
    {
        $after = CarbonImmutable::instance($calculatedAt)->setTimezone((string) config('app.timezone'));

        return AuditRecord::query()
            ->where('created_at', '>', $after)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $own) => $own->where('periodo', $period->key())->whereIn('evento', self::PERIOD_EVENTS))
                ->orWhere(fn (Builder $parameters) => $parameters->where('evento', self::PARAMETER_EVENT)
                    ->where('contexto->vigente_desde', '<=', $period->end()->format('Y-m-d'))))
            ->exists();
    }
}
