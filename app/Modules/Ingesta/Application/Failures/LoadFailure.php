<?php

namespace App\Modules\Ingesta\Application\Failures;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Ingesta\Domain\Failures\FailureCause;
use App\Modules\Ingesta\Domain\Failures\FailureSource;
use App\Modules\Ingesta\Domain\Failures\FailureStage;
use Carbon\CarbonImmutable;

/**
 * One failed load attempt, as a reporter (the robot through the report
 * endpoint, or the Bases SAP reader) hands it to RecordLoadFailure. The
 * message comes as reported; RecordLoadFailure cleans it.
 */
final readonly class LoadFailure
{
    public function __construct(
        public FailureSource $source,
        /** The reporter's idempotency key, a UUID. */
        public string $reportId,
        /** The run of the reporter, a UUID shared by its attempts. */
        public string $runId,
        public CompanyCode $company,
        public Dataset $dataset,
        /** The scheduled time of the load that failed. */
        public CarbonImmutable $slotAt,
        public FailureStage $stage,
        public FailureCause $cause,
        /** Whether a retry may succeed. */
        public bool $transient,
        /** The attempt that failed, from 1. */
        public int $attempt,
        public int $maxAttempts,
        /** True on the last attempt: the load will not be retried. */
        public bool $final,
        public string $message,
        public CarbonImmutable $occurredAt,
    ) {}
}
