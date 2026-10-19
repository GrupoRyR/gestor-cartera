<?php

namespace App\Modules\Cobro\Console;

use App\Modules\Cobro\Application\Runs\RunDefinition;
use App\Modules\Cobro\Domain\Runs\ItemStatus;
use App\Modules\Cobro\Infrastructure\Persistence\RunItem;
use App\Modules\Cobro\Infrastructure\Runs\RunPlanner;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Shared body of the commands that start a run (cobro:cobro-semanal and
 * cobro:circularizacion): for each company of the definition (or of
 * --empresa), saves the run of the current schedule slot and queues its
 * mails. Running it again for the same slot does nothing, so a manual run
 * after the scheduled one never mails a client twice.
 */
abstract class StartRunCommand extends Command
{
    abstract protected function definition(): RunDefinition;

    /**
     * The slot this run belongs to; one run per company and slot.
     */
    abstract protected function slot(CarbonImmutable $now): CarbonImmutable;

    /**
     * Whether to send a definition whose format is still a draft (only a
     * command that offers the option says yes).
     */
    protected function sendDraft(): bool
    {
        return false;
    }

    public function handle(RunPlanner $planner): int
    {
        $definition = $this->definition();
        $kind = $definition->kind();

        if (! $definition->sendsOnSchedule() && ! $this->sendDraft()) {
            $this->warn(__('cobro::envios.consola.borrador', ['tipo' => $kind->label()]));

            return self::SUCCESS;
        }

        $companies = $this->companies($definition);

        if ($companies === null) {
            return self::FAILURE;
        }

        $slot = $this->slot(CarbonImmutable::now());

        foreach ($companies as $company) {
            $name = ['empresa' => $company->label(), 'tipo' => $kind->label()];
            $run = $planner->start($definition, $company, $slot);

            if ($run === null) {
                $this->info(__('cobro::envios.consola.ya_existe', $name));

                continue;
            }

            $counts = RunItem::query()->where('run_id', $run->id)
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');
            $withoutEmail = (int) ($counts[ItemStatus::NoEmail->value] ?? 0);
            $excluded = (int) ($counts[ItemStatus::Excluded->value] ?? 0);

            $this->info(__('cobro::envios.consola.iniciado', $name + [
                'en_cola' => (int) $counts->sum() - $withoutEmail - $excluded,
                'sin_correo' => $withoutEmail,
                'excluidos' => $excluded,
            ]));
        }

        return self::SUCCESS;
    }

    /**
     * The companies to run: the definition's, narrowed by --empresa; null
     * (after printing the error) when --empresa names another company.
     *
     * @return list<CompanyCode>|null
     */
    private function companies(RunDefinition $definition): ?array
    {
        /** @var list<string> $requested */
        $requested = array_map(fn (mixed $code): string => mb_strtoupper(trim((string) $code)), (array) $this->option('empresa'));
        $available = $definition->companies();

        if ($requested === []) {
            return $available;
        }

        $selected = [];

        foreach ($requested as $code) {
            $company = CompanyCode::tryFrom($code);

            if ($company === null || ! in_array($company, $available, true)) {
                $this->error(__('cobro::envios.consola.empresa_invalida', [
                    'empresa' => $code,
                    'validas' => implode(', ', array_map(fn (CompanyCode $valid): string => $valid->value, $available)),
                ]));

                return null;
            }

            $selected[] = $company;
        }

        return array_values(array_unique($selected, SORT_REGULAR));
    }
}
