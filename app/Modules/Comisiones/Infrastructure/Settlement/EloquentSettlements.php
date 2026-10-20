<?php

namespace App\Modules\Comisiones\Infrastructure\Settlement;

use App\Modules\Comisiones\Application\Ports\Settlements;
use App\Modules\Comisiones\Domain\Calculation\PersonSettlement;
use App\Modules\Comisiones\Domain\Settlement\Settlement;
use App\Modules\Comisiones\Domain\Settlement\SettlementLocked;
use App\Modules\Comisiones\Domain\Settlement\SettlementStatus;
use App\Modules\Comisiones\Domain\Settlement\StaleSettlement;
use App\Modules\Comisiones\Domain\Shared\Money;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Infrastructure\Persistence\SettlementRecord;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The settlements in comisiones_liquidaciones, one row per version.
 *
 * save() inserts a new version, or replaces the stored copy of an open one
 * with a single conditional UPDATE (same id, stored revision not higher,
 * not closed), so the optimistic check and the write are atomic on every
 * engine. A refused write is re-read to tell a closed version
 * (SettlementLocked) from a newer revision (StaleSettlement).
 *
 * The helpers below the port serve the screens: every version of a period,
 * one version, its row (for its id and dates) and the last closed period.
 */
final class EloquentSettlements implements Settlements
{
    public function __construct(
        private readonly ResultSerializer $serializer,
    ) {}

    public function latest(Period $period): ?Settlement
    {
        $row = SettlementRecord::query()->where('periodo', $period->key())->orderByDesc('version')->first();

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @throws StaleSettlement when the stored copy has a higher revision.
     * @throws SettlementLocked when the stored copy is closed.
     */
    public function save(Settlement $settlement): void
    {
        $attributes = $this->attributes($settlement);
        $stored = $this->find($settlement->period, $settlement->version);

        if ($stored === null) {
            try {
                SettlementRecord::query()->create($attributes);

                return;
            } catch (UniqueConstraintViolationException) {
                // Another request stored this version meanwhile: go through the check below.
                $stored = $this->find($settlement->period, $settlement->version);
            }
        }

        $updated = $stored === null ? 0 : SettlementRecord::query()
            ->whereKey($stored->id)
            ->where('revision', '<=', $settlement->revision)
            ->where('estado', '!=', SettlementStatus::Closed->value)
            ->update(self::columns($attributes));

        if ($updated === 1) {
            return;
        }

        $current = $this->find($settlement->period, $settlement->version);

        if ($current !== null && $current->estado === SettlementStatus::Closed->value) {
            throw SettlementLocked::closed($settlement->period, $settlement->version);
        }

        throw new StaleSettlement($settlement->period, $settlement->version, $settlement->revision, $current->revision ?? 0);
    }

    /**
     * Every version of the period, oldest first.
     *
     * @return list<Settlement>
     */
    public function versions(Period $period): array
    {
        return SettlementRecord::query()
            ->where('periodo', $period->key())
            ->orderBy('version')
            ->get()
            ->map(fn (SettlementRecord $row): Settlement => $this->hydrate($row))
            ->values()
            ->all();
    }

    public function version(Period $period, int $version): ?Settlement
    {
        $row = $this->find($period, $version);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * The stored row of a version, for its id and timestamps.
     */
    public function record(Period $period, int $version): ?SettlementRecord
    {
        return $this->find($period, $version);
    }

    /**
     * The latest period with a closed version, or null when none was closed.
     * Its data and parameters are frozen from then on.
     */
    public function lastClosedPeriod(): ?Period
    {
        $key = SettlementRecord::query()
            ->where('estado', SettlementStatus::Closed->value)
            ->max('periodo');

        return is_string($key) ? Period::fromString($key) : null;
    }

    /**
     * Rebuilds the Settlement of a row through its public constructor,
     * which rejects a state the flow cannot reach.
     *
     * @throws CorruptSettlement when the stored result fails its check.
     */
    public function hydrate(SettlementRecord $row): Settlement
    {
        return new Settlement(
            period: Period::fromString($row->periodo),
            version: $row->version,
            revision: $row->revision,
            status: SettlementStatus::from($row->estado),
            result: $this->serializer->decode($row->resultado, $row->resultado_sha256, $row->periodo, $row->version),
            calculatedBy: $row->calculado_por,
            calculatedAt: $row->calculado_en,
            approvedBy: $row->aprobado_por,
            approvedAt: $row->aprobado_en,
            closedBy: $row->cerrado_por,
            closedAt: $row->cerrado_en,
            reason: $row->motivo,
            previousVersion: $row->version_anterior,
            justifications: $row->justificaciones,
        );
    }

    private function find(Period $period, int $version): ?SettlementRecord
    {
        return SettlementRecord::query()->where('periodo', $period->key())->where('version', $version)->first();
    }

    /**
     * The model attributes of a version.
     *
     * @return array<string, mixed>
     */
    private function attributes(Settlement $settlement): array
    {
        [$payload, $sha256] = $this->serializer->encode($settlement->result);
        $payroll = Money::sum(array_map(fn (PersonSettlement $person): Money => $person->total, $settlement->result->persons()));

        return [
            'periodo' => $settlement->period->key(),
            'version' => $settlement->version,
            'revision' => $settlement->revision,
            'estado' => $settlement->status->value,
            'calculado_por' => $settlement->calculatedBy,
            'calculado_en' => self::local($settlement->calculatedAt),
            'aprobado_por' => $settlement->approvedBy,
            'aprobado_en' => self::local($settlement->approvedAt),
            'cerrado_por' => $settlement->closedBy,
            'cerrado_en' => self::local($settlement->closedAt),
            'motivo' => $settlement->reason,
            'version_anterior' => $settlement->previousVersion,
            'justificaciones' => $settlement->justifications,
            'resultado' => $payload,
            'resultado_sha256' => $sha256,
            'total_nomina' => $payroll->toCents()->amount(),
            'alertas_bloqueantes' => count($settlement->result->blockingAlerts()),
        ];
    }

    /**
     * The attributes as raw column values for a query-builder update, which
     * applies no casts.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function columns(array $attributes): array
    {
        $columns = [];

        foreach ($attributes as $column => $value) {
            $columns[$column] = match (true) {
                $value instanceof DateTimeImmutable => $value->format('Y-m-d H:i:s'),
                is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                default => $value,
            };
        }

        return $columns;
    }

    /** The moment in the application timezone, as the datetime columns keep it. */
    private static function local(?DateTimeImmutable $at): ?CarbonImmutable
    {
        return $at === null ? null : CarbonImmutable::instance($at)->setTimezone((string) config('app.timezone'));
    }
}
