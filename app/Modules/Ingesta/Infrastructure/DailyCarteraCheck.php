<?php

namespace App\Modules\Ingesta\Infrastructure;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\LoadSource;
use App\Modules\Core\Contracts\Enums\LoadStatus;
use App\Modules\Ingesta\Infrastructure\Persistence\IngestaLoad;
use Carbon\CarbonImmutable;
use UnexpectedValueException;

/**
 * Whether the robot published today's cartera of each company expected
 * every day (config ingesta.alert_companies) by the deadline (config
 * ingesta.alert_deadline). Days are calendar days in America/Bogota. The
 * history shows the warning and ingesta:verificar-carga mails it.
 *
 * Only an accepted robot load counts: a manual upload keeps the platform
 * going, but the robot still needs a look.
 */
final class DailyCarteraCheck
{
    public const string TIMEZONE = 'America/Bogota';

    /**
     * The companies whose robot cartera is expected every day, in the
     * configured order; unknown codes are ignored.
     *
     * @return list<CompanyCode>
     */
    public function companies(): array
    {
        $codes = (array) config('ingesta.alert_companies', []);

        return array_values(array_filter(array_map(
            fn (mixed $code): ?CompanyCode => is_string($code) ? CompanyCode::tryFrom($code) : null,
            $codes,
        )));
    }

    /**
     * Today's deadline in Bogota.
     *
     * @throws UnexpectedValueException When ingesta.alert_deadline is not a HH:MM time.
     */
    public function deadline(?CarbonImmutable $now = null): CarbonImmutable
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone(self::TIMEZONE);
        $time = (string) config('ingesta.alert_deadline');

        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $parts) !== 1) {
            throw new UnexpectedValueException("ingesta.alert_deadline [{$time}] is not a HH:MM time.");
        }

        return $now->setTime((int) $parts[1], (int) $parts[2]);
    }

    public function deadlinePassed(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return $now->greaterThanOrEqualTo($this->deadline($now));
    }

    /**
     * Whether an accepted robot cartera load of $company with today's
     * cutoff was received by $now.
     */
    public function robotArrived(CompanyCode $company, ?CarbonImmutable $now = null): bool
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone(self::TIMEZONE);

        return IngestaLoad::query()
            ->where('dataset', Dataset::Receivables)
            ->where('company', $company)
            ->where('source', LoadSource::Robot)
            ->where('status', LoadStatus::Accepted)
            ->whereDate('cutoff_date', $now->format('Y-m-d'))
            // received_at is stored in the application timezone.
            ->where('received_at', '<=', $now->setTimezone((string) config('app.timezone')))
            ->exists();
    }

    /**
     * The latest rejected robot cartera load of $company received today
     * (by $now), for the alert to say why the robot failed.
     */
    public function rejectedRobotToday(CompanyCode $company, ?CarbonImmutable $now = null): ?IngestaLoad
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone((string) config('app.timezone'));
        $startOfDay = $now->setTimezone(self::TIMEZONE)->startOfDay()->setTimezone((string) config('app.timezone'));

        return IngestaLoad::query()
            ->where('dataset', Dataset::Receivables)
            ->where('company', $company)
            ->where('source', LoadSource::Robot)
            ->where('status', LoadStatus::Rejected)
            ->whereBetween('received_at', [$startOfDay, $now])
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Whether an accepted manual cartera load of $company with today's
     * cutoff exists.
     */
    public function manualToday(CompanyCode $company, ?CarbonImmutable $now = null): bool
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone(self::TIMEZONE);

        return IngestaLoad::query()
            ->where('dataset', Dataset::Receivables)
            ->where('company', $company)
            ->where('source', LoadSource::Manual)
            ->where('status', LoadStatus::Accepted)
            ->whereDate('cutoff_date', $now->format('Y-m-d'))
            ->exists();
    }

    /**
     * The companies still missing today's robot cartera once the deadline
     * has passed; empty before it.
     *
     * @return list<CompanyCode>
     */
    public function missing(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        if (! $this->deadlinePassed($now)) {
            return [];
        }

        return array_values(array_filter(
            $this->companies(),
            fn (CompanyCode $company): bool => ! $this->robotArrived($company, $now),
        ));
    }
}
