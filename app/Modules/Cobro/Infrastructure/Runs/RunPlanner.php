<?php

namespace App\Modules\Cobro\Infrastructure\Runs;

use App\Modules\Cobro\Application\Runs\RunDefinition;
use App\Modules\Cobro\Domain\Runs\ItemStatus;
use App\Modules\Cobro\Domain\Runs\PlannedItem;
use App\Modules\Cobro\Domain\Runs\RunStatus;
use App\Modules\Cobro\Infrastructure\Exclusions\ExclusionList;
use App\Modules\Cobro\Infrastructure\Persistence\Run;
use App\Modules\Cobro\Infrastructure\Persistence\RunItem;
use App\Modules\Cobro\Providers\CobroServiceProvider;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Plans and starts runs.
 *
 * plan() is what a run would do right now (the preview): each candidate of
 * the definition, excluded when the exclusion list says so, without e-mail
 * when the client has no contact address, else pending.
 *
 * start() saves that plan as a run with one item per client and queues one
 * SendRunItem job per pending item. There is at most one run per company,
 * kind and scheduled moment: starting it again does nothing.
 */
final class RunPlanner
{
    public function __construct(
        private readonly ExclusionList $exclusions,
        private readonly CarteraRepository $cartera,
        private readonly RunFinisher $finisher,
        private readonly ConnectionInterface $database,
    ) {}

    /**
     * @return list<PlannedItem>
     */
    public function plan(RunDefinition $definition, CompanyCode $company, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now(CobroServiceProvider::TIMEZONE)->startOfDay();
        $excluded = $this->exclusions->activeFor($company, $today);
        $planned = [];

        foreach ($definition->candidates($company) as $candidate) {
            $planned[] = PlannedItem::for($candidate, $excluded[$candidate->client->id]->reason ?? null);
        }

        return $planned;
    }

    /**
     * Saves and queues the run of $company at $scheduledFor; null when that
     * run already exists.
     */
    public function start(RunDefinition $definition, CompanyCode $company, CarbonImmutable $scheduledFor, ?int $userId = null): ?Run
    {
        $scheduledFor = $scheduledFor->setTimezone((string) config('app.timezone'))->startOfMinute();

        if ($this->exists($definition, $company, $scheduledFor)) {
            return null;
        }

        $planned = $this->plan($definition, $company, $scheduledFor->setTimezone(CobroServiceProvider::TIMEZONE)->startOfDay());

        try {
            $run = $this->database->transaction(function () use ($definition, $company, $scheduledFor, $userId, $planned): Run {
                $run = Run::query()->create([
                    'company' => $company,
                    'kind' => $definition->kind(),
                    'status' => RunStatus::Sending,
                    'scheduled_for' => $scheduledFor,
                    'cutoff_date' => $this->cartera->asSystem()->totals(new ClientQuery(companies: [$company]))->cutoffDate,
                    'triggered_by' => $userId,
                    'started_at' => CarbonImmutable::now(),
                ]);

                foreach ($planned as $item) {
                    $client = $item->candidate->client;

                    RunItem::query()->create([
                        'run_id' => $run->id,
                        'client_id' => $client->id,
                        'client_code' => mb_substr($client->code, 0, 50),
                        'client_name' => mb_substr($client->name, 0, 255),
                        'tax_id' => mb_substr($client->taxId, 0, 50),
                        'recipients' => $item->candidate->emails,
                        'amount' => number_format($item->candidate->amount, 2, '.', ''),
                        'invoice_count' => $item->candidate->invoiceCount,
                        'status' => $item->status,
                        'exclusion_reason' => $item->exclusionReason === null ? null : mb_substr($item->exclusionReason, 0, 500),
                    ]);
                }

                return $run;
            });
        } catch (UniqueConstraintViolationException) {
            // Another process started the same run a moment ago.
            return null;
        }

        $pending = RunItem::query()->where('run_id', $run->id)->where('status', ItemStatus::Pending)->orderBy('id')->pluck('id');
        $perMinute = max(1, (int) config('cobro.send_per_minute', 25));

        // Spread the jobs at the send rate, so the worker does not keep
        // picking up and releasing jobs the rate limiter would hold back;
        // the limiter stays as the safety net.
        foreach ($pending->values() as $position => $itemId) {
            SendRunItem::dispatch((int) $itemId)->delay(now()->addMinutes(intdiv((int) $position, $perMinute)));
        }

        $this->finisher->finishIfDone($run->id);

        return $run->refresh();
    }

    private function exists(RunDefinition $definition, CompanyCode $company, CarbonImmutable $scheduledFor): bool
    {
        return Run::query()
            ->where('company', $company)
            ->where('kind', $definition->kind())
            ->where('scheduled_for', $scheduledFor)
            ->exists();
    }
}
