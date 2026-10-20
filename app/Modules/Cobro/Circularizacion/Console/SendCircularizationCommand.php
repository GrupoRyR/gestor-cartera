<?php

namespace App\Modules\Cobro\Circularizacion\Console;

use App\Modules\Cobro\Application\Runs\RunDefinition;
use App\Modules\Cobro\Console\StartRunCommand;
use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Cobro\Infrastructure\Runs\RunDefinitions;
use App\Modules\Cobro\Providers\CobroServiceProvider;
use Carbon\CarbonImmutable;

/**
 * cobro:circularizacion: starts the monthly circularization (4.2) for
 * each company of config cobro.circularizacion.companies. The slot is the
 * configured day of the current month at the configured time (Bogotá), so
 * the scheduled run and any manual run of the month are the same run.
 *
 * Only if cobro.circularizacion.draft is turned on again (a future format
 * under review) does it send nothing, unless --aun-en-borrador asks for it
 * (to exercise the engine; the mails then say "Borrador").
 */
final class SendCircularizationCommand extends StartRunCommand
{
    /**
     * @var string
     */
    protected $signature = 'cobro:circularizacion
        {--empresa=* : Solo estas empresas (SGX, SDC)}
        {--aun-en-borrador : Envía aunque el formato sea un borrador}';

    /**
     * @var string
     */
    protected $description = 'Envía a cada cliente con saldo el estado de su cartera al cierre del mes';

    protected function definition(): RunDefinition
    {
        return app(RunDefinitions::class)->for(RunKind::Circularization);
    }

    protected function slot(CarbonImmutable $now): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', (string) config('cobro.circularizacion.time', '09:00')));
        $now = $now->setTimezone(CobroServiceProvider::TIMEZONE);

        return $now->setDate($now->year, $now->month, (int) config('cobro.circularizacion.day', 1))->setTime($hour, $minute);
    }

    protected function sendDraft(): bool
    {
        return (bool) $this->option('aun-en-borrador');
    }
}
