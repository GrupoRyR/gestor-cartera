<?php

namespace App\Modules\Core\Application\Portfolio;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * The one lock per company shared by the queued jobs that write its client
 * statuses: the receivables import (ImportReceivablesSnapshot) and the
 * recompute after a parameter change (RecomputeCompanyStatuses). Both
 * recompute every client of the company, so they never run at once for the
 * same company; different companies still run in parallel.
 *
 * A job that finds the lock taken is released back to the queue and tried
 * again RELEASE_AFTER seconds later, for up to RETRY_MINUTES (its
 * retryUntil()). The lock expires after EXPIRE_AFTER seconds, above the
 * 300-second timeout of both jobs, so a worker killed mid-job never keeps it
 * for ever.
 */
final class CompanyStatusesLock
{
    /** Prefix of the WithoutOverlapping key, followed by the company code. */
    public const string KEY_PREFIX = 'company-statuses:';

    /** Seconds a job that found the lock taken waits before its next try. */
    public const int RELEASE_AFTER = 60;

    /** Seconds before the lock expires on its own. */
    public const int EXPIRE_AFTER = 900;

    /** Minutes after it is queued during which a job keeps retrying. */
    public const int RETRY_MINUTES = 30;

    /**
     * The middleware that runs a job of $company only while it holds the
     * company's lock.
     */
    public static function middleware(CompanyCode $company): WithoutOverlapping
    {
        return (new WithoutOverlapping(self::KEY_PREFIX.$company->value))
            ->shared()
            ->releaseAfter(self::RELEASE_AFTER)
            ->expireAfter(self::EXPIRE_AFTER);
    }

    /**
     * The cache key of the lock of $company, e.g.
     * "laravel-queue-overlap:company-statuses:SGX". A shared key does not
     * depend on the job, so none is needed.
     */
    public static function key(CompanyCode $company): string
    {
        return self::middleware($company)->getLockKey(null);
    }
}
