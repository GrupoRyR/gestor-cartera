<?php

namespace App\Modules\Cobro\Database\Seeders;

use App\Modules\Cobro\Domain\Runs\ItemStatus;
use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Cobro\Domain\Runs\RunStatus;
use App\Modules\Cobro\Infrastructure\Persistence\Exclusion;
use App\Modules\Cobro\Infrastructure\Persistence\InboundMessage;
use App\Modules\Cobro\Infrastructure\Persistence\Run;
use App\Modules\Cobro\Infrastructure\Persistence\RunItem;
use App\Modules\Cobro\Infrastructure\Persistence\SentMessage;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Data\ClientSummary;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Fictitious delivery history for local development: the last weekly
 * collections and circularizations, already finished, with items in every
 * closed status (sent, bounced, without e-mail, excluded, without balance,
 * failed), the sent mails, client replies (read and unread) and bounces, and
 * a short exclusion list. Clients come from Core through CarteraRepository,
 * so it needs the Core demo data first; without clients it does nothing.
 *
 * No item stays pending: nothing is queued and no mail is ever sent. The
 * runs record no user (as scheduled runs do). DatabaseSeeder runs it only
 * with APP_ENV=local; it does nothing when there are runs already.
 */
final class CobroDemoSeeder extends Seeder
{
    private const string TIMEZONE = 'America/Bogota';

    private const int WEEKLY_RUNS = 3;

    private const int CIRCULARIZATIONS = 2;

    /** @var array<int, Exclusion> By client id. */
    private array $exclusions = [];

    private int $messages = 0;

    public function run(CarteraRepository $cartera): void
    {
        if (Run::query()->exists()) {
            return;
        }

        $cartera = $cartera->asSystem();
        $today = CarbonImmutable::today(self::TIMEZONE);
        $emails = [];

        foreach (CompanyCode::cases() as $company) {
            $clients = [...$cartera->allClients(new ClientQuery(companies: [$company], sort: 'balance', direction: 'desc'))];

            if ($clients === []) {
                continue;
            }

            foreach ($clients as $client) {
                $emails[$client->id] = $cartera->client($client->id)?->emails() ?? [];
            }

            $this->exclude($company, $clients, $today);

            if (in_array($company->value, (array) config('cobro.weekly.companies'), true)) {
                for ($back = self::WEEKLY_RUNS; $back >= 1; $back--) {
                    $scheduledFor = $today->subWeeks($back - 1)->previous(CarbonImmutable::WEDNESDAY)->setTime(17, 0);
                    $owing = array_values(array_filter($clients, fn (ClientSummary $client): bool => $client->overdue > 0));
                    $this->deliver($company, RunKind::Weekly, $scheduledFor, $owing, $emails, $back);
                }
            }

            if (in_array($company->value, (array) config('cobro.circularizacion.companies'), true)) {
                for ($back = self::CIRCULARIZATIONS; $back >= 1; $back--) {
                    $scheduledFor = $today->startOfMonth()->subMonths($back - 1)->setTime(9, 0);
                    $owing = array_values(array_filter($clients, fn (ClientSummary $client): bool => $client->balance > 0));
                    $this->deliver($company, RunKind::Circularization, $scheduledFor, $owing, $emails, $back);
                }
            }
        }
    }

    /**
     * Two clients kept out of the mails: one until a date, one for good.
     *
     * @param  list<ClientSummary>  $clients
     */
    private function exclude(CompanyCode $company, array $clients, CarbonImmutable $today): void
    {
        $reasons = [
            ['Acuerdo de pago vigente hasta fin de mes', $today->endOfMonth()],
            ['Cliente en proceso jurídico: no enviar correos', null],
        ];

        foreach ($reasons as $n => [$reason, $expiresOn]) {
            $client = $clients[3 + $n * 4] ?? null;

            if ($client === null) {
                continue;
            }

            $this->exclusions[$client->id] = Exclusion::query()->create([
                'company' => $company,
                'client_id' => $client->id,
                'client_code' => $client->code,
                'client_name' => $client->name,
                'tax_id' => $client->taxId,
                'reason' => $reason,
                'expires_on' => $expiresOn?->toDateString(),
                'created_by' => null,
            ]);
        }
    }

    /**
     * A finished run of $kind over $clients, with its mails and replies.
     *
     * @param  list<ClientSummary>  $clients
     * @param  array<int, list<string>>  $emails  Contact e-mails by client id.
     */
    private function deliver(CompanyCode $company, RunKind $kind, CarbonImmutable $scheduledFor, array $clients, array $emails, int $back): void
    {
        $run = Run::query()->create([
            'company' => $company,
            'kind' => $kind,
            'status' => RunStatus::Completed,
            'scheduled_for' => $scheduledFor,
            'cutoff_date' => $scheduledFor->subDay()->toDateString(),
            'triggered_by' => null,
            'started_at' => $scheduledFor,
            'finished_at' => $scheduledFor->addMinutes(12),
        ]);

        foreach ($clients as $i => $client) {
            $recipients = $emails[$client->id] ?? [];
            $status = match (true) {
                isset($this->exclusions[$client->id]) => ItemStatus::Excluded,
                $recipients === [] => ItemStatus::NoEmail,
                ($i + $back) % 9 === 4 => ItemStatus::Bounced,
                ($i + $back) % 11 === 7 => ItemStatus::Failed,
                ($i + $back) % 13 === 9 => ItemStatus::Skipped,
                default => ItemStatus::Sent,
            };
            $sentAt = $scheduledFor->addMinutes(1 + intdiv($i, 3));
            $sent = in_array($status, [ItemStatus::Sent, ItemStatus::Bounced], true)
                ? $this->sentMessage($company, $kind, $client, $recipients, $sentAt)
                : null;

            RunItem::query()->create([
                'run_id' => $run->id,
                'client_id' => $client->id,
                'client_code' => $client->code,
                'client_name' => $client->name,
                'tax_id' => $client->taxId,
                'recipients' => $recipients,
                'amount' => sprintf('%.2f', $kind === RunKind::Weekly ? $client->overdue : $client->balance),
                'invoice_count' => 1 + $i % 4,
                'status' => $status,
                'exclusion_reason' => $this->exclusions[$client->id]->reason ?? null,
                'sent_message_id' => $sent?->id,
                'internet_message_id' => $sent?->internet_message_id,
                'sent_at' => $sent === null ? null : $sentAt,
                'error' => $status === ItemStatus::Failed ? 'El servidor de correo no respondió (503). Se puede reenviar.' : null,
                'bounce_reason' => $status === ItemStatus::Bounced ? 'El buzón del destinatario no existe.' : null,
                'bounced_recipient' => $status === ItemStatus::Bounced ? $recipients[0] : null,
                'bounced_at' => $status === ItemStatus::Bounced ? $sentAt->addMinutes(3) : null,
            ]);

            if ($sent === null) {
                continue;
            }

            if ($status === ItemStatus::Bounced) {
                $this->bounce($company, $client, $sent, $recipients[0], $sentAt->addMinutes(3));
            } elseif ($i % 3 === 0) {
                // Older replies were read; those of the latest run wait.
                $this->reply($company, $client, $sent, $recipients[0], $sentAt->addHours(2 + $i), $back > 1 || $i % 2 === 0);
            }
        }
    }

    /**
     * @param  list<string>  $recipients
     */
    private function sentMessage(CompanyCode $company, RunKind $kind, ClientSummary $client, array $recipients, CarbonImmutable $sentAt): SentMessage
    {
        $number = ++$this->messages;

        return SentMessage::query()->create([
            'company' => $company,
            'mailbox' => (string) config("cobro.mailboxes.{$company->value}"),
            'client_id' => $client->id,
            'category' => $kind->value,
            'subject' => ($kind === RunKind::Weekly ? 'Estado de cuenta' : 'Circularización de saldos')." — {$client->name}",
            'recipients' => array_map(mb_strtolower(...), $recipients),
            'internet_message_id' => "<demo-{$number}@ryr.test>",
            'conversation_id' => "demo-conversacion-{$number}",
            'sent_at' => $sentAt,
        ]);
    }

    private function reply(CompanyCode $company, ClientSummary $client, SentMessage $sent, string $from, CarbonImmutable $at, bool $read): void
    {
        $preview = $sent->category === RunKind::Weekly->value
            ? 'Buenas tardes. El pago de las facturas vencidas sale esta semana; adjunto el soporte en cuanto lo tengamos.'
            : 'Confirmamos el saldo de la circularización. Quedamos atentos a cualquier diferencia.';

        InboundMessage::query()->create([
            'company' => $company,
            'mailbox' => $sent->mailbox,
            'graph_id' => "demo-respuesta-{$sent->id}",
            'internet_message_id' => "<demo-respuesta-{$sent->id}@example.com>",
            'conversation_id' => $sent->conversation_id,
            'from_address' => $from,
            'from_name' => null,
            'subject' => "RE: {$sent->subject}",
            'preview' => $preview,
            'body' => "<p>{$preview}</p>",
            'received_at' => $at,
            'is_bounce' => false,
            'sent_message_id' => $sent->id,
            'client_id' => $client->id,
            'client_name' => $client->name,
            'read_at' => $read ? $at->addHour() : null,
            'read_by' => null,
        ]);
    }

    private function bounce(CompanyCode $company, ClientSummary $client, SentMessage $sent, string $recipient, CarbonImmutable $at): void
    {
        InboundMessage::query()->create([
            'company' => $company,
            'mailbox' => $sent->mailbox,
            'graph_id' => "demo-rebote-{$sent->id}",
            'internet_message_id' => "<demo-rebote-{$sent->id}@ryr.test>",
            'conversation_id' => $sent->conversation_id,
            'from_address' => 'postmaster@ryr.test',
            'from_name' => 'Microsoft Outlook',
            'subject' => "No se puede entregar: {$sent->subject}",
            'preview' => "No se encontró el destinatario {$recipient}.",
            'body' => null,
            'received_at' => $at,
            'is_bounce' => true,
            'bounce_reason' => 'El buzón del destinatario no existe.',
            'bounced_recipient' => $recipient,
            'sent_message_id' => $sent->id,
            'client_id' => $client->id,
            'client_name' => $client->name,
            'read_at' => null,
            'read_by' => null,
        ]);
    }
}
