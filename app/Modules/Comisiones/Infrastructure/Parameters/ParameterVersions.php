<?php

namespace App\Modules\Comisiones\Infrastructure\Parameters;

use App\Models\User;
use App\Modules\Comisiones\Domain\Shared\Dates;
use App\Modules\Comisiones\Infrastructure\Audit\AuditTrail;
use App\Modules\Comisiones\Infrastructure\Persistence\ParameterRecord;
use App\Modules\Comisiones\Infrastructure\Settlement\EloquentSettlements;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Adds a version of a parameter (§7.2): the only way the stored book
 * changes after the seed.
 *
 * - The motive is required.
 * - The version starts after the last closed period: a closed settlement's
 *   parameters are frozen, and so is the book for its days.
 * - A version of the same key that starts later refuses the change; add it
 *   from that date on instead.
 * - A version of the same key that starts the same day is replaced: it is a
 *   correction. Otherwise the version in force on that day ends the day before.
 * - The whole book is rebuilt with the change before anything is written,
 *   so the domain checks every value (tranches, percentages, overlaps).
 *
 * Every version added is audited with the old and the new values.
 */
final class ParameterVersions
{
    public function __construct(
        private readonly ParameterMapper $mapper,
        private readonly EloquentSettlements $settlements,
        private readonly AuditTrail $audit,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * @param  string  $type  codigo | tramos | equipo | derivada | opciones.
     * @param  string  $key  The code, the team key or the derived key; ignored for tramos and opciones.
     * @param  string  $from  First day of the version, "Y-m-d".
     * @param  array<string, mixed>  $data  The values, as ParameterMapper describes them.
     *
     * @throws ParameterChangeRefused with the Spanish reason to show.
     */
    public function add(string $type, string $key, string $from, array $data, string $reason, User $user): ParameterRecord
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ParameterChangeRefused::reasonRequired();
        }

        if (! in_array($type, ParameterMapper::TYPES, true)) {
            throw ParameterChangeRefused::unknownType($type);
        }

        $start = $this->start($from);
        $key = in_array($type, [ParameterMapper::TRANCHES, ParameterMapper::OPTIONS], true) ? $type : trim($key);

        return $this->db->transaction(function () use ($type, $key, $start, $data, $reason, $user): ParameterRecord {
            $this->assertAfterClosedPeriods($start);

            $day = $start->format('Y-m-d');
            $versions = ParameterRecord::query()->where('tipo', $type)->where('clave', $key)
                ->orderBy('vigente_desde')->lockForUpdate()->get();

            $later = $versions->first(fn (ParameterRecord $version): bool => $version->vigente_desde->format('Y-m-d') > $day);

            if ($later !== null) {
                throw ParameterChangeRefused::laterVersion($later->vigente_desde->format('d/m/Y'));
            }

            $same = $versions->first(fn (ParameterRecord $version): bool => $version->vigente_desde->format('Y-m-d') === $day);
            $inForce = $same === null ? $versions->first(fn (ParameterRecord $version): bool => $version->vigente_hasta === null
                || $version->vigente_hasta->format('Y-m-d') >= $day) : null;
            $cutAt = $start->sub(new DateInterval('P1D'))->format('Y-m-d');

            $new = $this->normalized($type, $key, $day, $same?->vigente_hasta?->format('Y-m-d'), $data);
            $this->assertBookStillValid($new, $same, $inForce, $cutAt);

            $previous = $same ?? $inForce;
            $previousUntil = $inForce?->vigente_hasta?->format('Y-m-d');

            if ($inForce !== null) {
                $inForce->update(['vigente_hasta' => $cutAt]);
            }

            $attributes = ['datos' => $new['datos'], 'motivo' => $reason, 'created_by' => $user->id];
            if ($same === null) {
                $record = ParameterRecord::query()->create([...$new, ...$attributes]);
            } else {
                $same->update($attributes);
                $record = $same;
            }

            $this->audit->record(
                'comisiones.parameter.version_added',
                subjectType: 'comisiones_parametro',
                subjectId: $record->id,
                changes: array_filter([
                    'datos' => ['old' => $previous?->datos, 'new' => $new['datos']],
                    'vigente_hasta_anterior' => $inForce === null ? null : ['old' => $previousUntil, 'new' => $cutAt],
                ]),
                context: ['tipo' => $type, 'clave' => $key, 'vigente_desde' => $day],
                user: $user,
                reason: $reason,
            );

            return $record;
        });
    }

    /**
     * @throws ParameterChangeRefused when the date is not a real "Y-m-d" day.
     */
    private function start(string $from): DateTimeImmutable
    {
        try {
            return Dates::day(trim($from));
        } catch (InvalidArgumentException) {
            throw ParameterChangeRefused::invalidDate($from);
        }
    }

    /**
     * @throws ParameterChangeRefused when the version would start in or before the last closed period.
     */
    private function assertAfterClosedPeriods(DateTimeImmutable $start): void
    {
        $closed = $this->settlements->lastClosedPeriod();

        if ($closed !== null && $start <= $closed->end()) {
            throw ParameterChangeRefused::closedPeriod($closed->key(), $closed->next()->start()->format('d/m/Y'));
        }
    }

    /**
     * The row of the new version, with its data as the mapper writes it.
     *
     * @param  array<string, mixed>  $data
     * @return array{tipo: string, clave: string, vigente_desde: string, vigente_hasta: string|null, datos: array<string, mixed>}
     *
     * @throws ParameterChangeRefused when the data is malformed or the domain rejects it.
     */
    private function normalized(string $type, string $key, string $from, ?string $until, array $data): array
    {
        try {
            return $this->mapper->toRecord($this->mapper->fromRecord($type, $key, $from, $until, $data));
        } catch (InvalidArgumentException $invalid) {
            throw ParameterChangeRefused::invalidValues($invalid);
        }
    }

    /**
     * Rebuilds the whole book with the change applied.
     *
     * @param  array{tipo: string, clave: string, vigente_desde: string, vigente_hasta: string|null, datos: array<string, mixed>}  $new
     *
     * @throws ParameterChangeRefused when the book would not be valid.
     */
    private function assertBookStillValid(array $new, ?ParameterRecord $replaced, ?ParameterRecord $cut, string $cutAt): void
    {
        $records = [];

        foreach (ParameterRecord::query()->orderBy('id')->get() as $row) {
            $record = $row->toMapperRecord();

            if ($replaced !== null && $row->id === $replaced->id) {
                $record = $new;
            } elseif ($cut !== null && $row->id === $cut->id) {
                $record['vigente_hasta'] = $cutAt;
            }

            $records[] = $record;
        }

        if ($replaced === null) {
            $records[] = $new;
        }

        try {
            $this->mapper->book($records);
        } catch (InvalidArgumentException $invalid) {
            throw ParameterChangeRefused::invalidValues($invalid);
        }
    }
}
