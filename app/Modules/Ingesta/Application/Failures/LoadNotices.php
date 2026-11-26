<?php

namespace App\Modules\Ingesta\Application\Failures;

use App\Models\User;
use App\Modules\Core\Contracts\AvisoService;
use App\Modules\Core\Contracts\Data\Aviso;
use App\Modules\Core\Contracts\Data\Notice;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Contracts\Events\BatchAccepted;
use App\Modules\Ingesta\Domain\Failures\LoadAlertKind;
use App\Modules\Ingesta\Infrastructure\Persistence\LoadFailureRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The bell notice of a load that failed for good (kind "ingesta.carga"):
 * «Falló la carga de cartera de SGX de las 05:00», with the cause in plain
 * words as its body; or of a load that did not arrive at all («No llegó
 * la carga…», forMissing). SendLoadFailureAlert and WatchLoadSlot send
 * them, through SlotAlerts; IngestaServiceProvider
 * registers this class as a BatchAccepted listener and as the link resolver
 * of the kind.
 *
 * Its key groups the notices of one company and dataset, whatever the slot,
 * so any accepted load of them (robot batch, manual upload, later the Bases
 * SAP reader) resolves every open one: the data is current again.
 *
 * It goes to the active users whose e-mail is in ingesta.alert_recipients
 * (compared normalized, like User::normalizeEmail()) and to the active
 * admins, once each.
 */
final class LoadNotices
{
    public const string KIND = 'ingesta.carga';

    /**
     * The key of the notices of one company and dataset.
     */
    public static function key(CompanyCode $company, Dataset $dataset): string
    {
        return self::KIND.":{$company->value}:{$dataset->value}";
    }

    /**
     * Ids of the users who get the notice, in id order.
     *
     * @return list<int>
     */
    public function recipients(): array
    {
        $emails = array_map(
            fn (mixed $email): string => User::normalizeEmail(is_string($email) ? $email : ''),
            (array) config('ingesta.alert_recipients', []),
        );

        /** @var list<int> */
        return User::query()
            ->where('is_active', true)
            ->where(fn (Builder $users) => $users
                ->whereIn('email', array_values(array_filter($emails, fn (string $email): bool => $email !== '')))
                ->orWhereHas('roles', fn (Builder $roles) => $roles->where('name', Role::Admin->value)))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * The notice of the final $failure, with $explanation (the cause in
     * plain words) as its body.
     *
     * @param  list<int>  $recipients
     */
    public function forFailure(LoadFailureRecord $failure, string $explanation, array $recipients): Aviso
    {
        $slot = $failure->slot_at->setTimezone(SendLoadFailureAlert::TIMEZONE);
        $title = __('ingesta::alerta.campana.titulo', [
            'lote' => mb_strtolower($failure->dataset->label()),
            'empresa' => $failure->company->value,
            'hora' => $slot->format('H:i'),
        ]);

        return new Aviso(
            recipients: $recipients,
            kind: self::KIND,
            title: is_string($title) ? $title : self::KIND,
            body: $explanation,
            company: $failure->company->value,
            subjectType: 'load_failure',
            subjectId: $failure->id,
            key: self::key($failure->company, $failure->dataset),
            data: [
                'dataset' => $failure->dataset->value,
                'slot' => $slot->toIso8601String(),
                'cause' => $failure->cause->value,
                'stage' => $failure->stage->value,
            ],
        );
    }

    /**
     * The notice of a slot for which neither a load nor a final report
     * arrived (WatchLoadSlot): «No llegó la carga de cartera de SGX de las
     * 05:00», with $explanation (who did not run) as its body.
     *
     * @param  list<int>  $recipients
     */
    public function forMissing(CompanyCode $company, Dataset $dataset, CarbonImmutable $slotAt, string $explanation, array $recipients): Aviso
    {
        $slot = $slotAt->setTimezone(SendLoadFailureAlert::TIMEZONE);
        $title = __('ingesta::alerta.campana.no_llego', [
            'lote' => mb_strtolower($dataset->label()),
            'empresa' => $company->value,
            'hora' => $slot->format('H:i'),
        ]);

        return new Aviso(
            recipients: $recipients,
            kind: self::KIND,
            title: is_string($title) ? $title : self::KIND,
            body: $explanation,
            company: $company->value,
            subjectType: 'load_slot',
            key: self::key($company, $dataset),
            data: [
                'dataset' => $dataset->value,
                'slot' => $slot->toIso8601String(),
                'reason' => LoadAlertKind::NoLlego->value,
            ],
        );
    }

    /**
     * Resolves the open notices of the company and dataset; 0 while
     * AvisoService has no implementation.
     */
    public function resolve(CompanyCode $company, Dataset $dataset): int
    {
        return app()->bound(AvisoService::class)
            ? app(AvisoService::class)->resolve(self::key($company, $dataset))
            : 0;
    }

    /**
     * BatchAccepted listener: the accepted load closes the notices of its
     * company and dataset. One UPDATE, so it is not queued. It runs after
     * the load is stored, so a failure here is logged and swallowed: the
     * load stays accepted and its caller gets no error for a notice.
     */
    public function handle(BatchAccepted $event): void
    {
        try {
            $this->resolve($event->load->company, $event->load->dataset);
        } catch (Throwable $e) {
            Log::error('Ingesta: could not resolve the load failure notices of an accepted load.', [
                'company' => $event->load->company->value,
                'dataset' => $event->load->dataset->value,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Link resolver: the load history filtered to the notice's dataset and
     * company, for whoever may open it; null for anyone else.
     */
    public function link(Notice $notice): ?string
    {
        $user = Auth::user();

        if ($user === null || ! $user->can('ingesta.view')) {
            return null;
        }

        $dataset = $notice->data['dataset'] ?? null;

        return route('ingesta.index', array_filter([
            'lote' => is_string($dataset) ? Dataset::tryFrom($dataset)?->value : null,
            'empresa' => CompanyCode::tryFrom((string) $notice->company)?->value,
        ]));
    }
}
