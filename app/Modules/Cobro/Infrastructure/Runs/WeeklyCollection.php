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
 * overdue invoices (daysOverdue > 0 in the latest snapshot) gets the list
 * of those invoices, with the company's editable template.
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

    public function notice(int $clientId): ?NoticeData
    {
        $cartera = $this->cartera->asSystem();
        $client = $cartera->client($clientId);
        $invoices = $client === null ? [] : self::overdue($cartera->openInvoices($clientId));

        if ($client === null || $invoices === []) {
            return null;
        }

        return new NoticeData(
            $client->summary->name,
            $client->summary->taxId,
            $invoices,
            $cartera->totals(new ClientQuery(companies: [$client->summary->company]))->cutoffDate,
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
