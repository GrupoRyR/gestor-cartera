<?php

namespace App\Modules\Core\Infrastructure\Auth;

use App\Models\User;
use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Exceptions\MailNotSent;
use App\Modules\Core\Contracts\MailSender;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Mails core.audit.alert_recipients (IT and internal control) that a sign-in
 * made someone "asesor" on its own, because their e-mail matched SAP
 * advisors (OSLP.Email). Whoever can edit that e-mail in SAP decides who
 * sees an advisor's clients, so a person checks every such role (audit
 * S-17). Queued by SignInWithMicrosoft once the sign-in commits; it goes out
 * from the Segurex mailbox (category "alerta").
 *
 * It runs once ($tries = 1): a retry after a send Graph accepted would mail
 * the notice twice. Without recipients, without a MailSender or when Graph
 * does not take the mail, it only logs: the role is already given either way.
 */
final class SendAdvisorRoleNotice implements ShouldQueue
{
    use Queueable;

    private const string TIMEZONE = 'America/Bogota';

    public int $tries = 1;

    /**
     * @param  list<string>  $companies  The codes of the companies the role covers.
     * @param  list<array{company: string, code: int}>  $advisors  The SAP advisors this sign-in linked.
     * @param  string  $assignedAt  ISO 8601 moment of the sign-in.
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $companies,
        public readonly array $advisors,
        public readonly string $assignedAt,
    ) {}

    public function handle(): void
    {
        /** @var list<string> $recipients */
        $recipients = (array) config('core.audit.alert_recipients', []);
        $context = ['user_id' => $this->userId];

        if ($recipients === []) {
            Log::warning('Core: a sign-in gave the advisor role and no notice recipients are configured.', $context);

            return;
        }

        if (! app()->bound(MailSender::class)) {
            Log::error('Core: the advisor role notice could not be sent: MailSender has no implementation.', $context);

            return;
        }

        $user = User::query()->withTrashed()->findOrFail($this->userId);

        // The advisors in the order of the companies line: CompanyCode, then code.
        $order = array_flip(array_map(fn (CompanyCode $code): string => $code->value, CompanyCode::cases()));
        $advisors = $this->advisors;
        usort($advisors, fn (array $a, array $b): int => [$order[$a['company']], $a['code']] <=> [$order[$b['company']], $b['code']]);

        try {
            app(MailSender::class)->send(new OutgoingMail(
                company: CompanyCode::SGX,
                to: $recipients,
                subject: __('core::ingreso.aviso_rol.asunto', ['nombre' => $user->name]),
                html: view('core::auth.aviso-rol', [
                    'user' => $user,
                    'at' => CarbonImmutable::parse($this->assignedAt)->setTimezone(self::TIMEZONE),
                    'companies' => array_map(fn (string $code): string => CompanyCode::from($code)->label(), $this->companies),
                    'advisors' => array_map(
                        fn (array $advisor): string => CompanyCode::from($advisor['company'])->label().' '.$advisor['code'],
                        $advisors,
                    ),
                ])->render(),
                category: 'alerta',
            ));
        } catch (MailNotSent $e) {
            Log::error('Core: the advisor role notice could not be sent.', [...$context, 'exception' => $e]);
        }
    }
}
