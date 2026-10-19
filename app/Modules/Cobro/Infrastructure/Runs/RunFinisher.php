<?php

namespace App\Modules\Cobro\Infrastructure\Runs;

use App\Modules\Cobro\Domain\Runs\ItemStatus;
use App\Modules\Cobro\Domain\Runs\RunStatus;
use App\Modules\Cobro\Infrastructure\Persistence\Run;
use App\Modules\Cobro\Infrastructure\Persistence\RunItem;
use Carbon\CarbonImmutable;

/**
 * Closes a run once none of its items waits for a send job, and closes the
 * items a killed worker left enviando.
 */
final class RunFinisher
{
    /**
     * Minutes an item may stay enviando: a send takes seconds, so an older
     * one belongs to a worker the hosting killed.
     */
    public const int STALE_MINUTES = 30;

    /**
     * Marks fallido every item enviando since before STALE_MINUTES (the mail
     * may or may not have left: the report says so) and closes their runs.
     *
     * @return int How many items were closed.
     */
    public function expireStale(?CarbonImmutable $now = null): int
    {
        $limit = ($now ?? CarbonImmutable::now())->subMinutes(self::STALE_MINUTES);

        $stale = RunItem::query()
            ->where('status', ItemStatus::Sending)
            ->where('updated_at', '<', $limit)
            ->get(['id', 'run_id']);

        if ($stale->isEmpty()) {
            return 0;
        }

        RunItem::query()
            ->whereKey($stale->pluck('id')->all())
            ->where('status', ItemStatus::Sending)
            ->update(['status' => ItemStatus::Failed, 'error' => __('cobro::envios.errores.interrumpido')]);

        foreach ($stale->pluck('run_id')->unique() as $runId) {
            $this->finishIfDone((int) $runId);
        }

        return $stale->count();
    }

    public function finishIfDone(int $runId): void
    {
        $open = RunItem::query()
            ->where('run_id', $runId)
            ->whereIn('status', [ItemStatus::Pending, ItemStatus::Sending])
            ->exists();

        if ($open) {
            return;
        }

        Run::query()
            ->whereKey($runId)
            ->where('status', RunStatus::Sending)
            ->update(['status' => RunStatus::Completed, 'finished_at' => CarbonImmutable::now()]);
    }
}
