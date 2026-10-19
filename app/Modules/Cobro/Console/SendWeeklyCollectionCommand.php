<?php

namespace App\Modules\Cobro\Console;

use App\Modules\Cobro\Application\Runs\RunDefinition;
use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Cobro\Infrastructure\Runs\RunDefinitions;
use App\Modules\Cobro\Providers\CobroServiceProvider;
use Carbon\CarbonImmutable;

/**
 * cobro:cobro-semanal: starts the weekly collection of overdue invoices
 * (4.1) for each company of config cobro.weekly.companies. The slot is
 * today at cobro.weekly.time (Bogotá), so the scheduled Wednesday run and
 * any manual run of the same day are the same run.
 */
final class SendWeeklyCollectionCommand extends StartRunCommand
{
    /**
     * @var string
     */
    protected $signature = 'cobro:cobro-semanal {--empresa=* : Solo estas empresas (SGX, SDC)}';

    /**
     * @var string
     */
    protected $description = 'Envía a cada cliente con facturas vencidas el correo de cobro semanal';

    protected function definition(): RunDefinition
    {
        return app(RunDefinitions::class)->for(RunKind::Weekly);
    }

    protected function slot(CarbonImmutable $now): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', (string) config('cobro.weekly.time', '17:00')));

        return $now->setTimezone(CobroServiceProvider::TIMEZONE)->setTime($hour, $minute);
    }
}
