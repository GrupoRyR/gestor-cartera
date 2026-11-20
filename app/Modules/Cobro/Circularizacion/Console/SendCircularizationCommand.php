<?php

namespace App\Modules\Cobro\Circularizacion\Console;

use App\Modules\Cobro\Application\Runs\RunDefinition;
use App\Modules\Cobro\Circularizacion\CircularizationRun;
use App\Modules\Cobro\Console\StartRunCommand;
use App\Modules\Cobro\Domain\Runs\RunCalendar;
use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Cobro\Infrastructure\Runs\RunDefinitions;
use App\Modules\Cobro\Infrastructure\Runs\RunPlanner;
use App\Modules\Cobro\Providers\CobroServiceProvider;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Support\Calendar\ColombianHolidays;
use App\Support\Formatting\DateFormat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * cobro:circularizacion: starts the monthly circularization (4.2) for
 * each company of config cobro.circularizacion.companies. The slot is the
 * configured business day of the current month (business_day, 5) at the
 * configured time (Bogotá), so the scheduled run and any manual run of the
 * month are the same run.
 *
 * It sends the closing snapshot of the month before the slot
 * (CarteraRepository::closingSnapshot). A company whose snapshot has not
 * arrived is held back (no run is saved, so the slot stays free for a
 * manual run once it arrives), with a console and log warning.
 * --con-corte-actual skips that check and sends with the latest load.
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
        {--con-corte-actual : Envía con la última carga, sin esperar la foto del cierre del mes}
        {--aun-en-borrador : Envía aunque el formato sea un borrador}';

    /**
     * @var string
     */
    protected $description = 'Envía a cada cliente con saldo el estado de su cartera al cierre del mes';

    /**
     * Starts the run of the companies whose closing snapshot arrived;
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

        $slot = $this->slot(CarbonImmutable::now());
        $ready = array_values(array_filter($companies, fn (CompanyCode $company): bool => $this->hasClose($definition, $company, $slot)));

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

    /**
     * The configured business day of the current month (Bogotá) at the
     * configured time.
     */
    protected function slot(CarbonImmutable $now): CarbonImmutable
    {
        return RunCalendar::businessDayOfMonth(
            $now->setTimezone(CobroServiceProvider::TIMEZONE),
            CircularizationRun::businessDay(),
            (string) config('cobro.circularizacion.time', '09:00'),
            app(ColombianHolidays::class),
        );
    }

    protected function sendDraft(): bool
    {
        return (bool) $this->option('aun-en-borrador');
    }

    /**
     * --con-corte-actual reads the latest load; otherwise the closing
     * snapshot the definition chose.
     */
    protected function cutoff(RunDefinition $definition, CompanyCode $company, CarbonImmutable $slot): ?CarbonImmutable
    {
        return $this->option('con-corte-actual') ? null : parent::cutoff($definition, $company, $slot);
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
     * Whether the closing snapshot of the month before $slot arrived for
     * $company; warns when it did not.
     */
    private function hasClose(RunDefinition $definition, CompanyCode $company, CarbonImmutable $slot): bool
    {
        if ($definition->cutoffFor($company, $slot) !== null) {
            return true;
        }

        $close = CircularizationRun::closeBefore($slot);

        $this->warn(__('cobro::circularizacion.consola.sin_cierre', [
            'empresa' => $company->label(),
            'codigo' => $company->value,
            'cierre' => DateFormat::date($close),
        ]));

        Log::warning('Circularization held: the closing snapshot of the month has not arrived.', [
            'company' => $company->value,
            'close' => $close->toDateString(),
        ]);

        return false;
    }
}
