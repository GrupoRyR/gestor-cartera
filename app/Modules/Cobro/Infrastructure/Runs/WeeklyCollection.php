<?php

namespace App\Modules\Cobro\Infrastructure\Runs;

use App\Modules\Cobro\Application\Runs\RunDefinition;
use App\Modules\Cobro\Domain\Runs\Candidate;
use App\Modules\Cobro\Domain\Runs\NoticeData;
use App\Modules\Cobro\Domain\Runs\RenderedMail;
use App\Modules\Cobro\Domain\Runs\RunCalendar;
use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Cobro\Infrastructure\Mail\NoticeRenderer;
use App\Modules\Cobro\Infrastructure\Templates\TemplateStore;
use App\Modules\Cobro\Providers\CobroServiceProvider;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Data\Company;
use App\Modules\Core\Contracts\Data\Invoice;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;

/**
 * 4.1: every Wednesday at 17:00 (config cobro.weekly), each client with
 * overdue invoices (daysOverdue > 0 in the latest snapshot) gets the
 * statement of its open invoices, the overdue ones highlighted, with the
 * company's editable template. The run's amount and invoice count are the
 * overdue ones.
 */
final class WeeklyCollection implements RunDefinition
{
    public function __construct(
        private readonly CarteraRepository $cartera,
        private readonly TemplateStore $templates,
        private readonly NoticeRenderer $renderer,
    ) {}

    public function kind(): RunKind
    {
        return RunKind::Weekly;
    }

    public function companies(): array
    {
        $configured = array_map('strval', (array) config('cobro.weekly.companies', []));

        return array_values(array_filter(CompanyCode::cases(), fn (CompanyCode $company): bool => in_array($company->value, $configured, true)));
    }

    public function sendsOnSchedule(): bool
    {
        return true;
    }

    public function nextRunAt(CarbonImmutable $now): CarbonImmutable
    {
        return RunCalendar::nextWeekly(
            $now->setTimezone(CobroServiceProvider::TIMEZONE),
            (int) config('cobro.weekly.day', 3),
            (string) config('cobro.weekly.time', '17:00'),
        );
    }

    public function candidates(CompanyCode $company): iterable
    {
        $cartera = $this->cartera->asSystem();

        foreach ($cartera->allClients(new ClientQuery(companies: [$company], onlyOverdue: true)) as $client) {
            $invoices = self::overdue($cartera->openInvoices($client->id));

            if ($invoices === []) {
                continue;
            }

            yield new Candidate(
                $client,
                $cartera->client($client->id)?->emails() ?? [],
                array_sum(array_map(fn (Invoice $invoice): float => $invoice->balance, $invoices)),
                count($invoices),
            );
        }
    }

    /**
     * Every open invoice of the client (the overdue ones are highlighted
     * in the mail), when at least one is overdue.
     */
    public function notice(int $clientId): ?NoticeData
    {
        $cartera = $this->cartera->asSystem();
        $client = $cartera->client($clientId);
        $open = $client === null ? [] : array_values(array_filter(
            $cartera->openInvoices($clientId),
            fn (Invoice $invoice): bool => $invoice->balance > 0,
        ));

        if ($client === null || self::overdue($open) === []) {
            return null;
        }

        $summary = $client->summary;
        $advisorNames = [];

        foreach ($cartera->advisors() as $advisor) {
            if ($advisor->company === $summary->company) {
                $advisorNames[$advisor->code] = $advisor->name;
            }
        }

        if ($summary->advisor !== null) {
            $advisorNames[$summary->advisor->code] ??= $summary->advisor->name;
        }

        return new NoticeData(
            clientName: $summary->name,
            taxId: $summary->taxId,
            invoices: $open,
            cutoffDate: $cartera->totals(new ClientQuery(companies: [$summary->company]))->cutoffDate,
            clientCode: $summary->code,
            group: $client->group,
            paymentTerms: $client->paymentTerms,
            advisorNames: $advisorNames,
            advisorCode: $summary->advisor?->code,
        );
    }

    public function render(Company $company, NoticeData $notice, bool $test = false): RenderedMail
    {
        return $this->renderer->render(
            'cobro::correos.cobro-semanal',
            $company,
            $notice,
            $this->templates->for($company->code, RunKind::Weekly),
            test: $test,
        );
    }

    /**
     * @param  list<Invoice>  $invoices
     * @return list<Invoice>
     */
    private static function overdue(array $invoices): array
    {
        return array_values(array_filter($invoices, fn (Invoice $invoice): bool => $invoice->daysOverdue > 0 && $invoice->balance > 0));
    }
}
