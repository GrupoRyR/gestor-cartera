<?php

namespace App\Modules\Cobro\Circularizacion\Console;

use App\Modules\Cobro\Application\Runs\RunDefinition;
use App\Modules\Cobro\Console\StartRunCommand;
use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Cobro\Infrastructure\Runs\RunDefinitions;
use App\Modules\Cobro\Infrastructure\Runs\RunPlanner;
use App\Modules\Cobro\Providers\CobroServiceProvider;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Support\Formatting\DateFormat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * cobro:circularizacion: starts the monthly circularization (4.2) for
 * each company of config cobro.circularizacion.companies. The slot is the
 * configured day of the current month at the configured time (Bogotá), so
 * the scheduled run and any manual run of the month are the same run.
 *
 * It waits for the cartera of the month close: a company whose latest
 * cutoff is not the last day of the month before the slot is held back
 * (no run is saved, so the slot stays free for a manual run once the close
 * is loaded), with a console and log warning. --con-corte-actual skips
 * that check and sends with the latest cutoff.
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
        {--con-corte-actual : Envía aunque la cartera cargada no sea la del cierre del mes}
        {--aun-en-borrador : Envía aunque el formato sea un borrador}';

    /**
     * @var string
     */
    protected $description = 'Envía a cada cliente con saldo el estado de su cartera al cierre del mes';

    /**
     * Starts the run of the companies whose cartera is the month close;
     * FAILURE when every company asked for was held back.
     */
    public function handle(RunPlanner $planner): int
    {
        $definition = $this->definition();
        $companies = $this->requested($definition);

        // The draft notice and an invalid --empresa are the parent's.
        $draftHold = ! $definition->sendsOnSchedule() && ! $this->sendDraft();

        if ($draftHold || $companies === null || $this->option('con-corte-actual')) {
            return parent::handle($planner);
        }

        $close = $this->slot(CarbonImmutable::now())->startOfMonth()->subDay();
        $ready = array_values(array_filter($companies, fn (CompanyCode $company): bool => $this->hasClose($company, $close)));

        if ($ready === []) {
            return self::FAILURE;
        }

        $this->input->setOption('empresa', array_map(fn (CompanyCode $company): string => $company->value, $ready));

        return parent::handle($planner);
    }

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

    /**
     * The companies this run covers (--empresa, else the definition's);
     * null when --empresa names one without the circularization.
     *
     * @return list<CompanyCode>|null
     */
    private function requested(RunDefinition $definition): ?array
    {
        $available = $definition->companies();
        $codes = array_map(fn (mixed $code): string => mb_strtoupper(trim((string) $code)), (array) $this->option('empresa'));

        if ($codes === []) {
            return $available;
        }

        $selected = [];

        foreach ($codes as $code) {
            $company = CompanyCode::tryFrom($code);

            if ($company === null || ! in_array($company, $available, true)) {
                return null;
            }

            $selected[] = $company;
        }

        return array_values(array_unique($selected, SORT_REGULAR));
    }

    /**
     * Whether the latest cartera of $company is the month close, compared
     * as Bogotá calendar days; warns when it is not.
     */
    private function hasClose(CompanyCode $company, CarbonImmutable $close): bool
    {
        $cutoff = app(CarteraRepository::class)->asSystem()->totals(new ClientQuery(companies: [$company]))->cutoffDate;
        $cutoffDay = $cutoff?->setTimezone(CobroServiceProvider::TIMEZONE)->toDateString();

        if ($cutoffDay === $close->toDateString()) {
            return true;
        }

        $replace = [
            'empresa' => $company->label(),
            'codigo' => $company->value,
            'corte' => DateFormat::date($cutoff),
            'cierre' => DateFormat::date($close),
        ];
        $this->warn(__($cutoff === null ? 'cobro::circularizacion.consola.sin_cartera' : 'cobro::circularizacion.consola.sin_cierre', $replace));

        Log::warning('Circularization held: the loaded cartera is not the month close.', [
            'company' => $company->value,
            'cutoff' => $cutoffDay,
            'expected_close' => $close->toDateString(),
        ]);

        return false;
    }
}
