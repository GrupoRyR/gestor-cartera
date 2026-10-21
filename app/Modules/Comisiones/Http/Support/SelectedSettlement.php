<?php

namespace App\Modules\Comisiones\Http\Support;

use App\Modules\Comisiones\Domain\Settlement\Settlement;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Infrastructure\Persistence\SettlementRecord;
use App\Modules\Comisiones\Infrastructure\Settlement\CorruptSettlement;
use App\Modules\Comisiones\Infrastructure\Settlement\EloquentSettlements;
use Illuminate\Http\Request;

/**
 * The version of a period a screen shows: ?version=N, or the latest one.
 * Only the latest version takes the flow's steps; an older one is read only.
 */
final readonly class SelectedSettlement
{
    public function __construct(
        public Period $period,
        public ?Settlement $settlement,
        public ?int $latestVersion,
    ) {}

    /**
     * @throws CorruptSettlement when the stored result fails its check.
     */
    public static function from(Request $request, Period $period, EloquentSettlements $settlements): self
    {
        $latest = SettlementRecord::query()->where('periodo', $period->key())->max('version');
        $latest = $latest === null ? null : (int) $latest;
        $asked = $request->query('version');

        if ($asked === null || $asked === '') {
            return new self($period, $latest === null ? null : $settlements->version($period, $latest), $latest);
        }

        abort_unless(is_string($asked) && ctype_digit($asked), 404);

        $settlement = $settlements->version($period, (int) $asked);
        abort_if($settlement === null, 404);

        return new self($period, $settlement, $latest);
    }

    /**
     * The version asked for, or null when its stored result fails its
     * check: the error is reported and the caller sends the user to the
     * period screen, which says so.
     */
    public static function readable(Request $request, Period $period, EloquentSettlements $settlements): ?self
    {
        try {
            return self::from($request, $period, $settlements);
        } catch (CorruptSettlement $corrupt) {
            report($corrupt);

            return null;
        }
    }

    /**
     * readable() for the screens that need a settlement: a period never
     * calculated answers 404.
     */
    public static function calculated(Request $request, Period $period, EloquentSettlements $settlements): ?self
    {
        $selected = self::readable($request, $period, $settlements);
        abort_if($selected !== null && $selected->settlement === null, 404);

        return $selected;
    }

    /** Whether the version shown is the latest one, the one the flow moves. */
    public function isLatest(): bool
    {
        return $this->settlement !== null && $this->settlement->version === $this->latestVersion;
    }

    /**
     * The ?version= to keep in links, null for the latest one.
     */
    public function versionQuery(): ?int
    {
        return $this->settlement === null || $this->isLatest() ? null : $this->settlement->version;
    }
}
