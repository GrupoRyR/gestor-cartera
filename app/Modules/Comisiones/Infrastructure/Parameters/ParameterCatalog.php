<?php

namespace App\Modules\Comisiones\Infrastructure\Parameters;

use App\Modules\Comisiones\Domain\Parameters\OptionsVersion;
use App\Modules\Comisiones\Domain\Parameters\SettlementOptions;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Domain\Shared\Validity;
use App\Modules\Comisiones\Infrastructure\Persistence\ParameterRecord;
use App\Modules\Comisiones\Infrastructure\Settlement\EloquentSettlements;
use DateTimeImmutable;

/**
 * The stored parameter versions as the parameter screen reads them (§7.2):
 * what is in force on a day, the history of a key and the version a new
 * one starts from. Days are "Y-m-d" strings, compared as text.
 */
final class ParameterCatalog
{
    public function __construct(
        private readonly EloquentSettlements $settlements,
        private readonly ParameterMapper $mapper,
    ) {}

    /**
     * Each key of the type with the version in force on the day and the next
     * one after it. Keys whose every version ended before the day are left
     * out. Codes are sorted by number; the other keys keep the order they
     * were first stored in.
     *
     * @return list<ParameterEntry>
     */
    public function entries(string $type, string $day): array
    {
        /** @var array<string, list<ParameterRecord>> $byKey */
        $byKey = [];

        foreach (ParameterRecord::query()->where('tipo', $type)->orderBy('id')->get() as $row) {
            $byKey[$row->clave][] = $row;
        }

        $entries = [];

        foreach ($byKey as $key => $versions) {
            $current = null;
            $next = null;

            foreach ($versions as $version) {
                $from = $version->vigente_desde->format('Y-m-d');

                if (self::inForce($version, $day)) {
                    $current = $version;
                } elseif ($from > $day && ($next === null || $from < $next->vigente_desde->format('Y-m-d'))) {
                    $next = $version;
                }
            }

            if ($current !== null || $next !== null) {
                $entries[] = new ParameterEntry((string) $key, $current, $next);
            }
        }

        if ($type === ParameterMapper::CODE) {
            usort($entries, fn (ParameterEntry $a, ParameterEntry $b): int => (int) $a->key <=> (int) $b->key);
        }

        return $entries;
    }

    /**
     * Every version of a key, the newest first, with its author.
     *
     * @return list<ParameterRecord>
     */
    public function history(string $type, string $key): array
    {
        return array_values(ParameterRecord::query()
            ->with('creator')
            ->where('tipo', $type)
            ->where('clave', $key)
            ->orderByDesc('vigente_desde')
            ->get()
            ->all());
    }

    /**
     * The version a new one is prefilled from: the one in force on the day,
     * else the latest one; null for a key without versions.
     */
    public function basis(string $type, string $key, string $day): ?ParameterRecord
    {
        $versions = $this->history($type, $key);

        foreach ($versions as $version) {
            if (self::inForce($version, $day)) {
                return $version;
            }
        }

        return $versions[0] ?? null;
    }

    /**
     * The values of the settlement options when none is stored: the
     * SettlementOptions defaults, which reproduce the Excel workbook.
     *
     * @return array<string, mixed>
     */
    public function defaultOptions(): array
    {
        return $this->mapper->toRecord(new OptionsVersion(Validity::from('2000-01-01'), new SettlementOptions))['datos'];
    }

    /** The last period whose latest settlement version is closed. */
    public function lastClosedPeriod(): ?Period
    {
        return $this->settlements->lastClosedPeriod();
    }

    /**
     * The first day a new version may start: the day after the last closed
     * period; null when no period is closed.
     */
    public function firstOpenDay(): ?DateTimeImmutable
    {
        return $this->lastClosedPeriod()?->next()->start();
    }

    private static function inForce(ParameterRecord $version, string $day): bool
    {
        return $version->vigente_desde->format('Y-m-d') <= $day
            && ($version->vigente_hasta === null || $version->vigente_hasta->format('Y-m-d') >= $day);
    }
}
