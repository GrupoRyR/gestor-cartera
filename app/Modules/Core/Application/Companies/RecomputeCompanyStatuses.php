<?php

namespace App\Modules\Core\Application\Companies;

use App\Modules\Core\Application\Portfolio\CompanyStatusesLock;
use App\Modules\Core\Application\Portfolio\RecomputeClientStatuses;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Recomputes the status of every client of a company from its latest
 * snapshot, after someone edits the company's parameters. Queued, so the
 * save answers at once; the worker reads the parameters again when the job
 * runs, so it always applies the latest ones.
 *
 * Runs only while it holds the company's CompanyStatusesLock, shared with
 * the receivables import, so the two never recompute the same company at
 * once. A recompute is pure (it reads the latest snapshot and writes only
 * what changes), so running it again after a release is safe.
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

    /**
     * A run killed at the timeout fails instead of retrying until
     * retryUntil(): only waiting for the lock is retried.
     */
    public bool $failOnTimeout = true;

    /**
     * A real exception fails the job at once and keeps it in failed_jobs;
     * queue:retry runs it again safely. Waiting for the lock is a release,
     * not an exception: it retries until retryUntil().
     */
    public int $maxExceptions = 1;

    public function __construct(
        public readonly CompanyCode $company,
    ) {}

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [CompanyStatusesLock::middleware($this->company)];
    }

    /**
     * How long a job that finds the company's lock taken keeps retrying.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(CompanyStatusesLock::RETRY_MINUTES);
    }

    public function handle(RecomputeClientStatuses $statuses): void
    {
        $statuses->forCompany($this->company, source: self::SOURCE);
    }
}
