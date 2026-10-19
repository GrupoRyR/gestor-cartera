<?php

namespace App\Modules\Ingesta\Console;

use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Exceptions\MailNotSent;
use App\Modules\Core\Contracts\IngestaSink;
use App\Modules\Core\Contracts\MailSender;
use App\Modules\Ingesta\Infrastructure\DailyCarteraCheck;
use App\Support\Formatting\DateFormat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ingesta:verificar-carga: once ingesta.alert_deadline (Bogotá) has
 * passed, checks for each company of ingesta.alert_companies that an
 * accepted robot cartera load with today's cutoff arrived; when it did
 * not, mails ingesta.alert_recipients through MailSender (category
 * "alerta"). Before the deadline it checks nothing, sends nothing and
 * succeeds. IngestaServiceProvider schedules it daily at the deadline;
 * running it by hand is safe.
 *
 * Without recipients the missing load is only logged as a warning. It
 * fails when MailSender has no implementation yet (Cobro provides it) or
 * refuses the mail, so the scheduler records the failure.
 */
final class CheckDailyLoadCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ingesta:verificar-carga';

    /**
     * @var string
     */
    protected $description = 'Avisa por correo si el robot no publicó la cartera del día a la hora límite';

    public function handle(DailyCarteraCheck $check, IngestaSink $sink): int
    {
        $now = CarbonImmutable::now(DailyCarteraCheck::TIMEZONE);

        if (! $check->deadlinePassed($now)) {
            $this->info(__('ingesta::alerta.consola.antes_del_limite', ['hora' => $check->deadline($now)->format('H:i')]));

            return self::SUCCESS;
        }

        /** @var list<string> $recipients */
        $recipients = (array) config('ingesta.alert_recipients', []);
        $failed = false;

        foreach ($check->companies() as $company) {
            $name = ['empresa' => $company->label()];

            if ($check->robotArrived($company, $now)) {
                $this->info(__('ingesta::alerta.consola.llego', $name));

                continue;
            }

            if ($recipients === []) {
                Log::warning('Ingesta: the robot cartera load is missing and no alert recipients are configured.', ['company' => $company->value]);
                $this->warn(__('ingesta::alerta.consola.sin_destinatarios', $name));

                continue;
            }

            if (! app()->bound(MailSender::class)) {
                Log::error('no se pudo enviar la alerta: MailSender sin implementación', ['company' => $company->value]);
                $this->error(__('ingesta::alerta.consola.sin_mail_sender', $name));

                return self::FAILURE;
            }

            try {
                app(MailSender::class)->send($this->mail($company, $recipients, $now, $check, $sink));
            } catch (MailNotSent $e) {
                Log::error('Ingesta: the missing-load alert could not be sent.', ['company' => $company->value, 'exception' => $e]);
                $this->error(__('ingesta::alerta.consola.no_enviada', $name));
                $failed = true;

                continue;
            }

            $this->warn(__('ingesta::alerta.consola.enviada', $name + ['destinatarios' => implode(', ', $recipients)]));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<string>  $recipients
     */
    private function mail(CompanyCode $company, array $recipients, CarbonImmutable $now, DailyCarteraCheck $check, IngestaSink $sink): OutgoingMail
    {
        $deadline = $check->deadline($now);

        return new OutgoingMail(
            company: $company,
            to: $recipients,
            subject: __('ingesta::alerta.asunto', ['empresa' => $company->label(), 'fecha' => DateFormat::date($now)]),
            html: view('ingesta::alerta.correo', [
                'company' => $company,
                'deadline' => $deadline,
                'latest' => $sink->latest(Dataset::Receivables, $company),
                'rejected' => $check->rejectedRobotToday($company, $now),
                'manual' => $check->manualToday($company, $now),
                'url' => route('ingesta.index'),
            ])->render(),
            category: 'alerta',
        );
    }
}
