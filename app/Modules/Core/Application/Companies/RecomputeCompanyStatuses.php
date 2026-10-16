<?php

namespace App\Modules\Core\Application\Companies;

use App\Modules\Core\Application\Portfolio\RecomputeClientStatuses;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Recomputes the status of every client of a company from its latest
 * snapshot, after someone edits the company's parameters. Queued, so the
 * save answers at once; the worker reads the parameters again when the job
 * runs, so it always applies the latest ones.
 *
 * Status changes are audited and logged with source "parameters"
 * (RecomputeClientStatuses::forCompany()).
 */
final class RecomputeCompanyStatuses implements ShouldQueue
{
    use Queueable;

    /** Why the statuses change, in the audit context and the log metadata. */
    public const string SOURCE = 'parameters';

    /** Seconds a large company may take before the worker stops it. */
    public int $timeout = 300;

    /** A failed recompute is kept in failed_jobs; queue:retry runs it again safely. */
    public int $tries = 1;

    public function __construct(
        public readonly CompanyCode $company,
    ) {}

    public function handle(RecomputeClientStatuses $statuses): void
    {
        $statuses->forCompany($this->company, source: self::SOURCE);
    }
}
