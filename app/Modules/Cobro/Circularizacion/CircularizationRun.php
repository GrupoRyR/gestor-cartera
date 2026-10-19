<?php

namespace App\Modules\Cobro\Circularizacion;

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
 * 4.2, engine only: once a month at close (config cobro.circularizacion),
 * each client with a balance gets the statement of every open invoice.
 *
 * The mail format is PROVISIONAL (cobro::circularizacion.borrador, marked
 * "Borrador" in the subject and the body): the real one is the report the
 * clients get today and arrives later (docs/prompt-gestor-cartera.md,
 * Part D, item 1). While cobro.circularizacion.draft is true the scheduled
 * run sends nothing; the preview and the test send work. The
 * "Circularización" session (phase 2) replaces the view and turns the
 * draft flag off.
 */
final class CircularizationRun implements RunDefinition
{
    public const string VIEW = 'cobro::circularizacion.borrador';

    public function __construct(
        private readonly CarteraRepository $cartera,
        private readonly TemplateStore $templates,
        private readonly NoticeRenderer $renderer,
    ) {}

    public function kind(): RunKind
    {
        return RunKind::Circularization;
    }

    public function companies(): array
    {
        $configured = array_map('strval', (array) config('cobro.circularizacion.companies', []));

        return array_values(array_filter(CompanyCode::cases(), fn (CompanyCode $company): bool => in_array($company->value, $configured, true)));
    }

    public function sendsOnSchedule(): bool
    {
        return ! $this->isDraft();
    }

    public function isDraft(): bool
    {
        return (bool) config('cobro.circularizacion.draft', true);
    }

    public function nextRunAt(CarbonImmutable $now): CarbonImmutable
    {
        return RunCalendar::nextMonthly(
            $now->setTimezone(CobroServiceProvider::TIMEZONE),
            (int) config('cobro.circularizacion.day', 1),
            (string) config('cobro.circularizacion.time', '09:00'),
        );
    }

    public function candidates(CompanyCode $company): iterable
    {
        $cartera = $this->cartera->asSystem();

        foreach ($cartera->allClients(new ClientQuery(companies: [$company])) as $client) {
            if ($client->balance <= 0) {
                continue;
            }

            $invoices = self::open($cartera->openInvoices($client->id));

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
        $invoices = $client === null ? [] : self::open($cartera->openInvoices($clientId));

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
            self::VIEW,
            $company,
            $notice,
            $this->templates->for($company->code, RunKind::Circularization),
            test: $test,
            draft: $this->isDraft(),
        );
    }

    /**
     * @param  list<Invoice>  $invoices
     * @return list<Invoice>
     */
    private static function open(array $invoices): array
    {
        return array_values(array_filter($invoices, fn (Invoice $invoice): bool => $invoice->balance > 0));
    }
}
