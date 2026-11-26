<?php

namespace App\Modules\Ingesta\Console;

use App\Modules\Core\Contracts\Exceptions\MailNotSent;
use App\Modules\Ingesta\Application\Watch\WatchLoadSlot;
use App\Modules\Ingesta\Domain\Watch\LoadSlot;
use App\Modules\Ingesta\Domain\Watch\WatchOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * ingesta:vigilar-carga {hora}: the load watch of one slot of
 * etl/horarios.json (WatchLoadSlot), with a line per watched company.
 * IngestaServiceProvider schedules it vigilante_min minutes after each
 * slot; running it by hand is safe, it never alerts twice for a slot.
 *
 * It fails for a time that is not a slot, and when the alert could not be
 * mailed (no MailSender, or Graph refused it), so the scheduler records
 * the failure. On a day the slot does not run, or before the slot, it
 * checks nothing and succeeds.
 */
final class WatchLoadCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ingesta:vigilar-carga {hora : Hora programada de la carga (HH:MM), una de etl/horarios.json}';

    /**
     * @var string
     */
    protected $description = 'Avisa por correo y en la campana si no llegó la carga ni un reporte de falla de una hora programada';

    public function handle(WatchLoadSlot $watch): int
    {
        $slots = $watch->slots();
        $time = (string) $this->argument('hora');
        $slot = $slots->find($time);

        if ($slot === null) {
            $this->error(__('ingesta::alerta.no_llego.consola.no_programada', [
                'hora' => $time,
                'horas' => implode(', ', array_map(fn (LoadSlot $slot): string => $slot->time, $slots->slots)),
            ]));

            return self::FAILURE;
        }

        $now = CarbonImmutable::now($slots->timezone);
        $slotAt = $watch->slotAt($slot, $slots, $now);

        if (! $slot->runsOn($now)) {
            $this->info(__('ingesta::alerta.no_llego.consola.no_hoy', ['hora' => $time]));

            return self::SUCCESS;
        }

        if ($now->lessThan($slotAt)) {
            $this->info(__('ingesta::alerta.no_llego.consola.antes_de_la_hora', ['hora' => $time]));

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($watch->companies() as $company) {
            $names = ['empresa' => $company->label(), 'hora' => $time];

            try {
                $outcome = $watch->check($company, $slot, $slotAt, $now);
            } catch (MailNotSent) {
                $this->error(__('ingesta::alerta.no_llego.consola.no_enviada', $names));
                $failed = true;

                continue;
            }

            $line = __('ingesta::alerta.no_llego.consola.'.$outcome->value, $names + [
                'destinatarios' => implode(', ', (array) config('ingesta.alert_recipients', [])),
            ]);
            $line = is_string($line) ? $line : $outcome->value;

            match ($outcome) {
                WatchOutcome::Arrived, WatchOutcome::Reported => $this->info($line),
                WatchOutcome::NoMailer => $this->error($line),
                default => $this->warn($line),
            };

            $failed = $failed || $outcome === WatchOutcome::NoMailer;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
