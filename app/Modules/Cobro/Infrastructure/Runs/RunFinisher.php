<?php

namespace App\Modules\Cobro\Infrastructure\Runs;

use App\Modules\Cobro\Domain\Runs\ItemStatus;
use App\Modules\Cobro\Domain\Runs\RunStatus;
use App\Modules\Cobro\Infrastructure\Persistence\Run;
use App\Modules\Cobro\Infrastructure\Persistence\RunItem;
use Carbon\CarbonImmutable;

/**
 * Closes a run once none of its items waits for a send job.
 */
final class RunFinisher
{
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
