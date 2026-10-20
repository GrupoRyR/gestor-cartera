<?php

namespace App\Modules\Comisiones\Infrastructure\Captures;

use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Infrastructure\Persistence\AdjustmentRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\CrossingRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ObrRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ReassignmentRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The manual captures of the «Ajustes y cruces» screen (§7.4) that need an
 * approval by another user. The value is the {tipo} of their routes and
 * the subject type of their audit events.
 *
 * Each belongs to a period: OBR sales and adjustments by their "periodo",
 * a manual crossing by the month of its crossing date and a reassignment
 * by the month of the crossing it corrects.
 */
enum CaptureKind: string
{
    case Obr = 'obr';
    case Adjustment = 'ajuste';
    case Crossing = 'cruce';
    case Reassignment = 'reasignacion';

    /** The tab of the screen that lists this kind. */
    public function tab(): string
    {
        return match ($this) {
            self::Obr => 'obr',
            self::Adjustment => 'reintegros',
            self::Crossing => 'cruces',
            self::Reassignment => 'reasignaciones',
        };
    }

    public static function fromTab(string $tab): self
    {
        foreach (self::cases() as $kind) {
            if ($kind->tab() === $tab) {
                return $kind;
            }
        }

        return self::Obr;
    }

    /**
     * @return list<string>
     */
    public static function tabs(): array
    {
        return array_map(fn (self $kind): string => $kind->tab(), self::cases());
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $kind): string => $kind->value, self::cases());
    }

    /**
     * The captures of this kind in the period, newest first. Manual
     * crossings only: SAP crossings are not captures.
     *
     * @return Builder<ObrRecord>|Builder<AdjustmentRecord>|Builder<CrossingRecord>|Builder<ReassignmentRecord>
     */
    public function inPeriod(Period $period): Builder
    {
        $start = $period->start()->format('Y-m-d');
        $end = $period->end()->format('Y-m-d');

        $query = match ($this) {
            self::Obr => ObrRecord::query()->where('periodo', $period->key()),
            self::Adjustment => AdjustmentRecord::query()->where('periodo', $period->key()),
            self::Crossing => CrossingRecord::query()->where('origen', CrossingRecord::MANUAL)->whereBetween('fecha_cruce', [$start, $end]),
            self::Reassignment => ReassignmentRecord::query()
                ->whereIn('clave_cruce', CrossingRecord::query()->select('clave')->whereBetween('fecha_cruce', [$start, $end])),
        };

        return $query->with(['creator', 'reviewer'])->orderByDesc('id');
    }

    /**
     * One capture of this kind, or null (a SAP crossing is not one).
     */
    public function find(int $id): ObrRecord|AdjustmentRecord|CrossingRecord|ReassignmentRecord|null
    {
        return match ($this) {
            self::Obr => ObrRecord::query()->find($id),
            self::Adjustment => AdjustmentRecord::query()->find($id),
            self::Crossing => CrossingRecord::query()->where('origen', CrossingRecord::MANUAL)->find($id),
            self::Reassignment => ReassignmentRecord::query()->find($id),
        };
    }

    /**
     * The period the capture belongs to; null for a reassignment whose
     * crossing no longer exists.
     */
    public function periodOf(Model $capture): ?Period
    {
        if ($capture instanceof ObrRecord || $capture instanceof AdjustmentRecord) {
            return Period::fromString($capture->periodo);
        }

        if ($capture instanceof CrossingRecord) {
            return Period::of($capture->fecha_cruce->year, $capture->fecha_cruce->month);
        }

        $crossing = $capture instanceof ReassignmentRecord
            ? CrossingRecord::query()->where('clave', $capture->clave_cruce)->first()
            : null;

        return $crossing === null ? null : Period::of($crossing->fecha_cruce->year, $crossing->fecha_cruce->month);
    }
}
