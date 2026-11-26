<?php

namespace App\Modules\Ingesta\Application\Watch;

use App\Modules\Core\Contracts\Data\Aviso;
use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\LoadStatus;
use App\Modules\Core\Contracts\Exceptions\MailNotSent;
use App\Modules\Core\Contracts\MailSender;
use App\Modules\Ingesta\Application\Failures\LoadNotices;
use App\Modules\Ingesta\Application\Failures\SlotAlerts;
use App\Modules\Ingesta\Domain\Failures\LoadAlertKind;
use App\Modules\Ingesta\Domain\Watch\LoadSlot;
use App\Modules\Ingesta\Domain\Watch\LoadSlots;
use App\Modules\Ingesta\Domain\Watch\WatchOutcome;
use App\Modules\Ingesta\Infrastructure\Persistence\IngestaLoad;
use App\Modules\Ingesta\Infrastructure\Persistence\LoadFailureRecord;
use App\Support\Formatting\DateFormat;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

/**
 * The load watch (ingesta:vigilar-carga), run ingesta.load_slots'
 * vigilante_min minutes after each slot: for a company of
 * ingesta.watch_companies, alerts when, since the slot, neither an
 * accepted cartera load of any source (robot, manual Excel, later the
 * Bases SAP reader) nor a final failure report of that slot arrived. The
 * failure report already has its own alert (SendLoadFailureAlert). A
 * duplicate of an accepted batch counts as arrived: the robot sends the
 * same batch while SAP does not change, and the sink moves the load's
 * last_seen_at forward.
 *
 * The alert goes like the failure alert, through SlotAlerts: first the
 * bell, under the same "campana" claim, so a slot gets one bell notice
 * whatever the reason, and resolved by the next accepted load; then the
 * mail to ingesta.alert_recipients from the Segurex mailbox, claimed as
 * "no_llego" in one transaction with the send. A refused mail
 * (MailNotSent) rolls the claim back and is rethrown, so the scheduler
 * records the failure and the next run can still send. Without recipients
 * it logs a warning; without MailSender, an error. Running it again for
 * the same slot sends nothing new.
 */
final class WatchLoadSlot
{
    /** The watched dataset. */
    public const Dataset DATASET = Dataset::Receivables;

    public function __construct(
        private readonly SlotAlerts $alerts,
        private readonly LoadNotices $notices,
    ) {}

    /**
     * The slots of ingesta.load_slots (etl/horarios.json), read now; the
     * defaults when the file is missing.
     *
     * @throws UnexpectedValueException When the file is invalid.
     */
    public function slots(): LoadSlots
    {
        return LoadSlots::fromFile((string) config('ingesta.load_slots'));
    }

    /**
     * The watched companies, in the configured order; unknown codes are ignored.
     *
     * @return list<CompanyCode>
     */
    public function companies(): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $code): ?CompanyCode => is_string($code) ? CompanyCode::tryFrom($code) : null,
            (array) config('ingesta.watch_companies', []),
        )));
    }

    /**
     * The instant of $slot on the day of $now, in the slots' timezone. The
     * watch runs on the slot's own day (LoadSlots rejects a watch past
     * midnight), so 00:30 checks the 00:00 of that same day.
     */
    public function slotAt(LoadSlot $slot, LoadSlots $slots, CarbonImmutable $now): CarbonImmutable
    {
        return $now->setTimezone($slots->timezone)->setTime($slot->hour(), $slot->minute());
    }

    /**
     * Checks $company for the slot at $slotAt and alerts when nothing arrived.
     *
     * @throws MailNotSent When Graph refuses the mail; the mail claim is rolled back.
     */
    public function check(CompanyCode $company, LoadSlot $slot, CarbonImmutable $slotAt, CarbonImmutable $now): WatchOutcome
    {
        $stored = $slotAt->setTimezone((string) config('app.timezone'));

        $arrived = IngestaLoad::query()
            ->where('company', $company)
            ->where('dataset', self::DATASET)
            ->where('status', LoadStatus::Accepted)
            // last_seen_at moves with every duplicate of the batch; null on older loads means received_at.
            ->where(fn (Builder $query) => $query
                ->where('last_seen_at', '>=', $stored)
                ->orWhere(fn (Builder $older) => $older->whereNull('last_seen_at')->where('received_at', '>=', $stored)))
            ->exists();

        if ($arrived) {
            return WatchOutcome::Arrived;
        }

        $reported = LoadFailureRecord::query()
            ->where('company', $company)
            ->where('dataset', self::DATASET)
            ->where('slot_at', $stored)
            ->where('final', true)
            ->exists();

        if ($reported) {
            return WatchOutcome::Reported;
        }

        $context = ['company' => $company->value, 'dataset' => self::DATASET->value, 'slot' => $slot->time];
        $explanation = $this->explanation($slot);

        $this->alerts->ringBell(
            $company,
            self::DATASET,
            $slotAt,
            null,
            fn (array $recipients): Aviso => $this->notices->forMissing($company, self::DATASET, $slotAt, $explanation, $recipients),
            $context,
        );

        /** @var list<string> $recipients */
        $recipients = (array) config('ingesta.alert_recipients', []);

        if ($recipients === []) {
            Log::warning('Ingesta: a load did not arrive and no alert recipients are configured.', $context);

            return WatchOutcome::NoRecipients;
        }

        if (! app()->bound(MailSender::class)) {
            Log::error('Ingesta: the missing-load alert could not be sent: MailSender has no implementation.', $context);

            return WatchOutcome::NoMailer;
        }

        $mail = $this->mail($company, $slotAt, $now, $explanation, $recipients);

        return DB::transaction(function () use ($company, $slotAt, $mail, $context): WatchOutcome {
            if (! $this->alerts->claim($company, self::DATASET, $slotAt, LoadAlertKind::NoLlego)) {
                Log::info('Ingesta: the missing-load alert of this slot was already sent.', $context);

                return WatchOutcome::AlreadyAlerted;
            }

            try {
                app(MailSender::class)->send($mail);
            } catch (MailNotSent $e) {
                Log::error('Ingesta: the missing-load alert could not be sent.', [...$context, 'exception' => $e]);

                throw $e;
            }

            return WatchOutcome::Alerted;
        });
    }

    /**
     * Who did not run, by ingesta.expected_source, and what waits on the
     * load when the slot says so: the mail's explanation and the bell's body.
     */
    private function explanation(LoadSlot $slot): string
    {
        $source = config('ingesta.expected_source') === 'bases_sap' ? 'bases_sap' : 'robot';
        $text = __("ingesta::alerta.no_llego.causas.{$source}", ['hora' => $slot->time]);
        $text = is_string($text) ? $text : '';

        if ($slot->before !== null) {
            $before = __('ingesta::alerta.no_llego.antes_de', ['antes_de' => $slot->before]);
            $text .= is_string($before) ? ' '.$before : '';
        }

        return $text;
    }

    /**
     * @param  list<string>  $recipients
     */
    private function mail(CompanyCode $company, CarbonImmutable $slotAt, CarbonImmutable $now, string $explanation, array $recipients): OutgoingMail
    {
        $names = [
            'lote' => mb_strtolower(self::DATASET->label()),
            'empresa' => $company->label(),
            'hora' => $slotAt->format('H:i'),
            'fecha' => DateFormat::date($slotAt),
            'revision' => $now->setTimezone($slotAt->getTimezone())->format('H:i'),
        ];

        return new OutgoingMail(
            company: CompanyCode::SGX,
            to: $recipients,
            subject: __('ingesta::alerta.no_llego.asunto', $names),
            html: view('ingesta::alerta.no-llego', [
                'names' => $names,
                'explanation' => $explanation,
                'url' => route('ingesta.index', ['lote' => self::DATASET->value, 'empresa' => $company->value]),
            ])->render(),
            category: 'alerta',
        );
    }
}
