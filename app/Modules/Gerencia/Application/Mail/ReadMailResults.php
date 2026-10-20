<?php

namespace App\Modules\Gerencia\Application\Mail;

use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\Company;
use App\Modules\Core\Contracts\Enums\MailRunKind;
use App\Modules\Core\Contracts\MailboxReader;
use App\Modules\Core\Contracts\MailRunResultsProvider;
use App\Modules\Gerencia\Domain\Mail\MailRunTotals;
use App\Modules\Gerencia\Domain\Period\ReportPeriod;

/**
 * Reads the results of the weekly collection and the circularization runs
 * scheduled in the period, for each company in the user's current view.
 *
 * MailRunResultsProvider is not user-scoped, so only the companies of
 * CurrentCompany::scope() are ever asked for: one call per company and kind.
 */
final class ReadMailResults
{
    public function __construct(
        private readonly MailRunResultsProvider $runs,
        private readonly MailboxReader $mailbox,
        private readonly CurrentCompany $company,
    ) {}

    public function handle(ReportPeriod $period): MailRunsReport
    {
        $companies = $this->company->scope();

        $kinds = array_map(function (MailRunKind $kind) use ($companies, $period): MailKindResults {
            $perCompany = array_map(
                fn (Company $company): MailRunTotals => MailRunTotals::of(
                    $this->runs->results($company->code, $kind, $period->from, $period->to),
                ),
                $companies,
            );

            return new MailKindResults($kind, MailRunTotals::sum($perCompany), $perCompany);
        }, MailRunKind::cases());

        return new MailRunsReport(
            kinds: $kinds,
            unread: $this->mailbox->unreadCount(),
        );
    }
}
