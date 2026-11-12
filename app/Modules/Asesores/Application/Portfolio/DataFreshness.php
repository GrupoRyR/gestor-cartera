<?php

namespace App\Modules\Asesores\Application\Portfolio;

use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\LoadRecord;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\IngestaSink;
use Carbon\CarbonImmutable;

/**
 * When the portfolio on screen was last refreshed: the moment Ingesta
 * received the cartera batch behind the cutoff.
 *
 * IngestaSink is not user-scoped, so only the companies of the current view
 * (CurrentCompany::scope()) are read. With several companies the one with
 * the oldest cutoff decides, as it decides the cutoff of the group view;
 * between equal cutoffs, the batch received first.
 */
final readonly class DataFreshness
{
    public function __construct(
        private IngestaSink $ingesta,
        private CurrentCompany $company,
    ) {}

    /**
     * The moment, in the application timezone; null without a cartera load.
     */
    public function updatedAt(): ?CarbonImmutable
    {
        $chosen = null;

        foreach ($this->company->scope() as $company) {
            $load = $this->ingesta->latest(Dataset::Receivables, $company->code);

            if ($load !== null && ($chosen === null || $this->isOlder($load, $chosen))) {
                $chosen = $load;
            }
        }

        return $chosen?->receivedAt->setTimezone(config()->string('app.timezone'));
    }

    private function isOlder(LoadRecord $load, LoadRecord $than): bool
    {
        return [$load->cutoffDate->toDateString(), $load->receivedAt->getTimestamp()]
            < [$than->cutoffDate->toDateString(), $than->receivedAt->getTimestamp()];
    }
}
