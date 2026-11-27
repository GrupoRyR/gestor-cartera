<?php

namespace App\Modules\Cartera\Application\Calls;

use App\Models\User;
use App\Modules\Cartera\Domain\Calls\CallAgreement;
use App\Modules\Cartera\Domain\Calls\CallOutcome;
use App\Modules\Cartera\Domain\Commitments\CommitmentWriting;
use App\Modules\Cartera\Infrastructure\Persistence\CallRecord;
use App\Modules\Core\Contracts\AvisoService;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\Data\ClientDetail;
use App\Modules\Core\Contracts\Data\Invoice;
use App\Modules\Core\Contracts\Data\NewActivity;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use App\Modules\Core\Contracts\GestionLogger;
use App\Support\Formatting\DateFormat;
use App\Support\Formatting\Money;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Logs a call to a client: how it went (channel, phone, contact, outcome,
 * agreement), the open invoices it was about and the day to call again.
 *
 * Only the portfolio team (cartera.manage) logs calls, on any client the
 * CarteraRepository scope lets the user see; a client out of reach is
 * RecordNotFound. A payment commitment agreement is allowed only to whoever
 * writes the commitments of the client's company (CommitmentWriting and
 * cartera.commitments.write: the SDC analyst); the call stores no amount
 * or date, the commitment itself goes through CompromisoService.
 *
 * Everything happens in one transaction: the call and copies of its
 * invoices, the entry in the client's log (GestionLogger, as the
 * authenticated user) and the closing of the client's earlier open
 * follow-ups, with their bell notices resolved. When anything fails,
 * nothing is kept.
 */
final readonly class LogCall
{
    private const string TIMEZONE = 'America/Bogota';

    /**
     * How many days back, from the start of today in Bogota, a call may be logged.
     */
    private const int MAX_DAYS_BACK = 30;

    public function __construct(
        private CarteraRepository $cartera,
        private GestionLogger $log,
        private Translator $translator,
        private Container $container,
    ) {}

    /**
     * The key of the bell notices that remind the client's follow-up.
     */
    public static function followUpKey(int $clientId): string
    {
        return "cartera.call.follow_up:{$clientId}";
    }

    /**
     * @throws AuthorizationException When the user may not log calls or agree this commitment.
     * @throws RecordNotFound When the client does not exist or is out of the user's scope.
     * @throws ValidationException With the fields ocurrio, seguimiento, acuerdo and facturas.
     */
    public function log(NewCall $call, User $user): CallRecord
    {
        return DB::transaction(function () use ($call, $user): CallRecord {
            if (! $user->can('cartera.manage')) {
                throw new AuthorizationException('Only the portfolio team logs calls.');
            }

            $client = $this->cartera->client($call->clientId) ?? throw RecordNotFound::for('client', $call->clientId);

            if ($call->agreement === CallAgreement::PaymentCommitment && ! $this->writesCommitments($client, $user)) {
                throw new AuthorizationException('Only who writes the commitments of the company agrees one on a call.');
            }

            // Eloquent writes a date's wall-clock time as given, so it goes in the app timezone.
            $occurredAt = ($call->occurredAt ?? CarbonImmutable::now())->setTimezone((string) config('app.timezone'));
            $invoices = $this->validated($call, $occurredAt);

            $record = $this->save($call, $client, $occurredAt, $invoices, $user);
            $activity = $this->log->log($this->activity($record, $invoices));
            $record->update(['activity_id' => $activity->id]);

            $this->closeFollowUps($record);

            return $record;
        });
    }

    private function writesCommitments(ClientDetail $client, User $user): bool
    {
        return CommitmentWriting::byCollector($client->summary->company) && $user->can('cartera.commitments.write');
    }

    /**
     * Checks the rules that need the clock and the portfolio, and returns the
     * chosen open invoices, oldest due first.
     *
     * @return list<Invoice>
     *
     * @throws ValidationException
     */
    private function validated(NewCall $call, CarbonImmutable $occurredAt): array
    {
        $errors = [];
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();

        if ($occurredAt->isAfter(CarbonImmutable::now())) {
            $errors['ocurrio'] = [$this->text('validacion.futura')];
        } elseif ($occurredAt->isBefore($today->subDays(self::MAX_DAYS_BACK))) {
            $errors['ocurrio'] = [$this->text('validacion.antigua', ['dias' => self::MAX_DAYS_BACK])];
        }

        if ($call->followUpOn === null && $call->outcome === CallOutcome::CallBack) {
            $errors['seguimiento'] = [$this->text('validacion.seguimiento_requerido')];
        } elseif ($call->followUpOn !== null && $call->followUpOn->format('Y-m-d') < $today->format('Y-m-d')) {
            $errors['seguimiento'] = [$this->text('validacion.seguimiento_pasado')];
        }

        if ($call->agreement !== CallAgreement::None && $call->outcome !== CallOutcome::Answered) {
            $errors['acuerdo'] = [$this->text('validacion.acuerdo_sin_respuesta')];
        }

        $chosen = array_unique($call->invoiceIds);
        $invoices = $chosen === [] ? [] : array_values(array_filter(
            $this->cartera->openInvoices($call->clientId),
            fn (Invoice $invoice): bool => in_array($invoice->id, $chosen, true),
        ));

        if (count($invoices) !== count($chosen)) {
            $errors['facturas'] = [$this->text('validacion.facturas_cerradas')];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $invoices;
    }

    /**
     * @param  list<Invoice>  $invoices
     */
    private function save(NewCall $call, ClientDetail $client, CarbonImmutable $occurredAt, array $invoices, User $user): CallRecord
    {
        $record = CallRecord::query()->create([
            'company' => $client->summary->company,
            'client_id' => $client->summary->id,
            'user_id' => $user->getKey(),
            'occurred_at' => $occurredAt,
            'channel' => $call->channel,
            'phone' => trim($call->phone),
            'contact_name' => $this->clean($call->contactName),
            'outcome' => $call->outcome,
            'notes' => $this->clean($call->notes),
            'agreement' => $call->agreement,
            'follow_up_on' => $call->followUpOn?->format('Y-m-d'),
        ]);

        foreach ($invoices as $invoice) {
            $record->invoices()->create([
                'invoice_id' => $invoice->id,
                'document_number' => $invoice->documentNumber,
                'document_type' => $invoice->documentType,
                'due_at' => $invoice->dueAt->format('Y-m-d'),
                'balance' => number_format($invoice->balance, 2, '.', ''),
            ]);
        }

        return $record;
    }

    /**
     * The log entry: «Llamada de WhatsApp a Juan Pérez (3001234567)», the
     * notes and one line per invoice, and «Contestó · Compromiso de pago».
     *
     * @param  list<Invoice>  $invoices
     */
    private function activity(CallRecord $record, array $invoices): NewActivity
    {
        $summary = $record->contact_name === null
            ? $this->text('resumen.sin_contacto', ['canal' => $record->channel->label(), 'telefono' => $record->phone])
            : $this->text('resumen.con_contacto', [
                'canal' => $record->channel->label(),
                'contacto' => $record->contact_name,
                'telefono' => $record->phone,
            ]);

        $lines = $record->notes === null ? [] : [$record->notes];

        foreach ($invoices as $invoice) {
            $lines[] = $this->text('factura', [
                'tipo' => $invoice->documentType,
                'numero' => $invoice->documentNumber,
                'fecha' => DateFormat::date($invoice->dueAt),
                'saldo' => Money::format($invoice->balance),
            ]);
        }

        $result = $record->outcome->label();

        if ($record->agreement !== CallAgreement::None) {
            $result .= ' · '.$record->agreement->label();
        }

        return new NewActivity(
            clientId: $record->client_id,
            type: ActivityType::Call,
            summary: $summary,
            details: $lines === [] ? null : implode("\n", $lines),
            result: $result,
            occurredAt: $record->occurred_at,
            metadata: [
                'call_id' => $record->id,
                'channel' => $record->channel->value,
                'outcome' => $record->outcome->value,
                'agreement' => $record->agreement->value,
            ],
        );
    }

    /**
     * A new call answers the follow-ups of the client's calls that happened
     * before it (or at the same time): they are closed. A call logged late
     * leaves open the follow-ups of later calls. The bell notices, one key
     * per client, are resolved for every recipient only when no follow-up
     * of the client is left open.
     */
    private function closeFollowUps(CallRecord $record): void
    {
        $open = fn () => CallRecord::query()
            ->where('client_id', $record->client_id)
            ->whereKeyNot($record->id)
            ->whereNotNull('follow_up_on')
            ->whereNull('follow_up_closed_at');

        $open()->where('occurred_at', '<=', $record->occurred_at)
            ->update(['follow_up_closed_at' => CarbonImmutable::now()]);

        if (! $open()->exists() && $this->container->bound(AvisoService::class)) {
            $this->container->make(AvisoService::class)->resolve(self::followUpKey($record->client_id));
        }
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private function text(string $key, array $replace = []): string
    {
        return $this->translator->get("cartera::llamadas.{$key}", $replace);
    }

    private function clean(?string $text): ?string
    {
        $text = $text === null ? '' : trim($text);

        return $text === '' ? null : $text;
    }
}
