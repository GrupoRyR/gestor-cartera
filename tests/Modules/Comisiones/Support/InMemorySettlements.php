<?php

namespace Tests\Modules\Comisiones\Support;

use App\Modules\Comisiones\Application\Ports\Settlements;
use App\Modules\Comisiones\Domain\Settlement\Settlement;
use App\Modules\Comisiones\Domain\Shared\Period;
use LogicException;

/**
 * Keeps every saved version of each period in memory, the way the
 * infrastructure must: a version is replaced while it is open and a closed
 * version is never overwritten (§6 step 7).
 */
final class InMemorySettlements implements Settlements
{
    /** @var array<string, array<int, Settlement>> Period key → version → settlement. */
    private array $versions = [];

    public function latest(Period $period): ?Settlement
    {
        $versions = $this->versions[$period->key()] ?? [];

        return $versions === [] ? null : $versions[max(array_keys($versions))];
    }

    public function save(Settlement $settlement): void
    {
        $stored = $this->versions[$settlement->period->key()][$settlement->version] ?? null;

        if ($stored !== null && ! $stored->isOpen()) {
            throw new LogicException("Version {$settlement->version} of {$settlement->period->key()} is closed; it cannot be overwritten.");
        }

        $this->versions[$settlement->period->key()][$settlement->version] = $settlement;
    }

    /**
     * Every stored version of the period, oldest first.
     *
     * @return list<Settlement>
     */
    public function versions(Period $period): array
    {
        $versions = $this->versions[$period->key()] ?? [];

        ksort($versions);

        return array_values($versions);
    }
}
