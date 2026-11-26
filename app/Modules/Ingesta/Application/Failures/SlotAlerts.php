<?php

namespace App\Modules\Ingesta\Application\Failures;

use App\Modules\Core\Contracts\AvisoService;
use App\Modules\Core\Contracts\Data\Aviso;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Ingesta\Domain\Failures\LoadAlertKind;
use App\Modules\Ingesta\Infrastructure\Persistence\LoadAlertRecord;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The alert claims of a load slot (company, dataset and scheduled time) in
 * ingesta_load_alerts, and its bell step, shared by SendLoadFailureAlert
 * (a final failure report) and WatchLoadSlot (no load and no report).
 *
 * Both ring the bell under the same claim (kind "campana"), so a slot gets
 * one bell notice whatever the reason, and both use LoadNotices, so the
 * next accepted load of the company and dataset resolves it.
 */
final class SlotAlerts
{
    public function __construct(
        private readonly LoadNotices $notices,
    ) {}

    /**
     * Writes the slot's alert row of $kind with an insert-or-ignore; false
     * when it already exists. Times are stored in the app timezone.
     */
    public function claim(CompanyCode $company, Dataset $dataset, CarbonImmutable $slotAt, LoadAlertKind $kind, ?int $failureId = null): bool
    {
        $timezone = (string) config('app.timezone');

        return LoadAlertRecord::query()->insertOrIgnore([
            'company' => $company->value,
            'dataset' => $dataset->value,
            'slot_at' => $slotAt->setTimezone($timezone)->format('Y-m-d H:i:s'),
            'kind' => $kind->value,
            'load_failure_id' => $failureId,
            'created_at' => CarbonImmutable::now($timezone)->format('Y-m-d H:i:s'),
        ]) === 1;
    }

    /**
     * Sends the Aviso that $notice builds for the bell recipients, once per
     * slot: the claim and AvisoService::send() in their own transaction, so
     * the bell does not wait for the mail and a refused mail does not undo
     * it. A bell that fails is logged and rolled back; with no user to
     * notify, or no AvisoService, it only logs and claims nothing.
     *
     * @param  Closure(list<int>): Aviso  $notice
     * @param  array<string, mixed>  $context  For the log lines.
     */
    public function ringBell(CompanyCode $company, Dataset $dataset, CarbonImmutable $slotAt, ?int $failureId, Closure $notice, array $context): void
    {
        if (! app()->bound(AvisoService::class)) {
            Log::error('Ingesta: the load notice could not be sent: AvisoService has no implementation.', $context);

            return;
        }

        $recipients = $this->notices->recipients();

        if ($recipients === []) {
            Log::info('Ingesta: a load notice is due and there is nobody to notify in the bell.', $context);

            return;
        }

        try {
            DB::transaction(function () use ($company, $dataset, $slotAt, $failureId, $notice, $recipients, $context): void {
                if (! $this->claim($company, $dataset, $slotAt, LoadAlertKind::Campana, $failureId)) {
                    Log::info('Ingesta: the load notice of this slot is already in the bell.', $context);

                    return;
                }

                app(AvisoService::class)->send($notice($recipients));
            });
        } catch (Throwable $e) {
            Log::error('Ingesta: the load notice could not be sent to the bell.', [...$context, 'exception' => $e]);
        }
    }
}
