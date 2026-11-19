<?php

namespace App\Modules\Cobro\Application\Runs;

use App\Modules\Cobro\Domain\Runs\Candidate;
use App\Modules\Cobro\Domain\Runs\NoticeData;
use App\Modules\Cobro\Domain\Runs\RenderedMail;
use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Core\Contracts\Data\Company;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;

/**
 * One kind of collection mail sent in batches (runs): which clients it
 * mails, what each mail says and when it runs. The run engine (RunPlanner,
 * SendRunItem) is the same for every kind: exclusion list, delivery report
 * and replies are shared.
 *
 * Implementations read the cartera without user scope
 * (CarteraRepository::asSystem()): runs happen in scheduled commands and
 * queued jobs. A run reads one snapshot from planning to the last send:
 * the cutoff cutoffFor() chose, saved on the run and given back to
 * candidates() and notice().
 */
interface RunDefinition
{
    public function kind(): RunKind;

    /**
     * Companies this mail is sent for, in CompanyCode order.
     *
     * @return list<CompanyCode>
     */
    public function companies(): array;

    /**
     * Whether scheduled runs send (a draft format can still be previewed
     * and tested, but its schedule sends nothing).
     */
    public function sendsOnSchedule(): bool;

    /**
     * The next scheduled run at or after $now, in America/Bogota.
     */
    public function nextRunAt(CarbonImmutable $now): CarbonImmutable;

    /**
     * The cutoff of the snapshot a run of $company at $runAt must read; null
     * when it reads the latest load (or, for a mail that needs a closing
     * snapshot, when that snapshot has not arrived yet).
     */
    public function cutoffFor(CompanyCode $company, CarbonImmutable $runAt): ?CarbonImmutable;

    /**
     * The clients of $company this mail is about, by name.
     *
     * @param  CarbonImmutable|null  $cutoff  The cutoff the run reads (cutoffFor(), saved as Run::$cutoff_date); null = the latest load.
     * @return iterable<Candidate>
     */
    public function candidates(CompanyCode $company, ?CarbonImmutable $cutoff = null): iterable;

    /**
     * What the client's mail shows now, from the snapshot of $cutoff (as
     * in candidates()); null when there is nothing to mail anymore (e.g.
     * the client paid since the run was planned).
     */
    public function notice(int $clientId, ?CarbonImmutable $cutoff = null): ?NoticeData;

    public function render(Company $company, NoticeData $notice, bool $test = false): RenderedMail;
}
