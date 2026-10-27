<?php

namespace App\Modules\Cobro\Infrastructure\Templates;

use App\Models\User;
use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Exceptions\MailNotSent;
use App\Modules\Core\Contracts\MailSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Mails cobro.template_alert_recipients (IT and internal control) the
 * differences of a template change, so someone other than the editor sees
 * what the scheduled run will send to every overdue client (audit S-08).
 * Queued by TemplateStore after an effective save or reset; it goes out from
 * the company's own mailbox (category "alerta").
 *
 * It runs once ($tries = 1): a retry after a send Graph accepted would mail
 * the notice twice. Without recipients, or when Graph does not take the
 * mail, it only logs: the template is already saved either way.
 */
final class SendTemplateChangeNotice implements ShouldQueue
{
    use Queueable;

    public const string UPDATED = 'updated';

    public const string RESET = 'reset';

    public int $tries = 1;

    /**
     * @param  string  $change  self::UPDATED or self::RESET.
     * @param  string  $changedAt  ISO 8601 moment of the change.
     * @param  array<string, array{old: string, new: string}>  $changes  The changed fields (subject, intro, closing).
     */
    public function __construct(
        public readonly CompanyCode $company,
        public readonly RunKind $kind,
        public readonly string $change,
        public readonly ?int $userId,
        public readonly string $changedAt,
        public readonly array $changes,
    ) {}

    public function handle(MailSender $sender): void
    {
        /** @var list<string> $recipients */
        $recipients = (array) config('cobro.template_alert_recipients', []);
        $context = ['company' => $this->company->value, 'kind' => $this->kind->value, 'change' => $this->change];

        if ($recipients === []) {
            Log::warning('Cobro: a template changed and no notice recipients are configured.', $context);

            return;
        }

        try {
            $sender->send(new OutgoingMail(
                company: $this->company,
                to: $recipients,
                subject: __('cobro::plantillas.aviso.asunto', ['empresa' => $this->company->label()]),
                html: view('cobro::correos.plantilla-cambiada', [
                    'company' => $this->company,
                    'change' => $this->change,
                    'editor' => $this->userId === null ? null : User::query()->withTrashed()->find($this->userId),
                    'changedAt' => $this->changedAt,
                    'changes' => $this->changes,
                ])->render(),
                category: 'alerta',
            ));
        } catch (MailNotSent $e) {
            Log::error('Cobro: the template change notice could not be sent.', [...$context, 'exception' => $e]);
        }
    }
}
