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
 * queued jobs.
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
     * The clients of $company this mail is about, by name.
     *
     * @return iterable<Candidate>
     */
    public function candidates(CompanyCode $company): iterable;

    /**
     * What the client's mail shows right now; null when there is nothing to
     * mail anymore (e.g. the client paid since the run was planned).
     */
    public function notice(int $clientId): ?NoticeData;

    public function render(Company $company, NoticeData $notice, bool $test = false): RenderedMail;
}
