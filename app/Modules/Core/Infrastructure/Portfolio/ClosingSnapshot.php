<?php

namespace App\Modules\Core\Infrastructure\Portfolio;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Infrastructure\Persistence\PortfolioLoadRecord;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Which receivables snapshot is the closing of a month: the only place that
 * decides it (CarteraRepository::closingSnapshot(), docs/arquitectura.md
 * §6.4).
 *
 * The SAP robot runs at 00:00, 05:00 and 12:00 and dates each load with the
 * day it runs. The photo of the end of month M is therefore the load of
 * 00:00 on the first day of M+1: cut on that day, but taken before anyone
 * works it. The 12:00 load of the last day misses the afternoon payments.
 *
 * Rule: the earliest load of the company cut on the first day of the next
 * month and imported (portfolio_loads.created_at, the moment Core received
 * it) before 03:00 in Bogotá. Core retries an import for at most 30 minutes
 * after the load arrives (CompanyStatusesLock::RETRY_MINUTES), so the 00:00
 * load is imported before 00:30 or fails. A load imported later that day,
 * such as one reapplied by hand, already reflects the new month or cannot
 * be told apart from one that does: there is no closing snapshot then, and
 * the circularization holds back and warns, which is the safe outcome.
 *
 * When the source moves to the SAP views (v_cartera_fotos dates the photo
 * on the last day of the month), this rule changes here.
 */
final class ClosingSnapshot
{
    /**
     * The calendar of the cutoff dates and the deadline.
     */
    public const string TIMEZONE = 'America/Bogota';

    /**
     * Hour of the first day of the next month, in TIMEZONE, before which the
     * closing load must have been imported.
     */
    public const int RECEIVED_BEFORE_HOUR = 3;

    /**
     * The closing snapshot of $company for $month of $year, or null.
     *
     * @throws InvalidArgumentException When $month is not 1 to 12.
     */
    public static function find(CompanyCode $company, int $year, int $month): ?PortfolioLoadRecord
    {
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException("The month must be between 1 and 12, {$month} given.");
        }

        $firstOfNext = CarbonImmutable::create($year, $month, 1, 0, 0, 0, self::TIMEZONE)->addMonthNoOverflow();
        // Stored times are in the application time zone.
        $deadline = $firstOfNext->setTime(self::RECEIVED_BEFORE_HOUR, 0)->setTimezone(date_default_timezone_get());

        return PortfolioLoadRecord::query()
            ->where('company', $company)
            ->whereDate('cutoff_date', $firstOfNext->toDateString())
            ->where('created_at', '<', $deadline)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first(['id', 'company', 'cutoff_date', 'created_at']);
    }

    /**
     * The day a closing snapshot stands for: the last day of the month, the
     * day before its cutoff date.
     */
    public static function closingDate(PortfolioLoadRecord $snapshot): CarbonImmutable
    {
        return $snapshot->cutoff_date->subDay();
    }
}
