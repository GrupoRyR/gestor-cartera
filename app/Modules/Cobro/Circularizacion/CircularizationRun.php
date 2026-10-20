<?php

namespace App\Modules\Cobro\Circularizacion;

use App\Modules\Cobro\Application\Runs\RunDefinition;
use App\Modules\Cobro\Domain\Copies\CopyRecipients;
use App\Modules\Cobro\Domain\Runs\Candidate;
use App\Modules\Cobro\Domain\Runs\NoticeData;
use App\Modules\Cobro\Domain\Runs\RenderedMail;
use App\Modules\Cobro\Domain\Runs\RunCalendar;
use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Cobro\Infrastructure\Copies\CopyStore;
use App\Modules\Cobro\Infrastructure\Mail\NoticeRenderer;
use App\Modules\Cobro\Infrastructure\Templates\TemplateStore;
use App\Modules\Cobro\Providers\CobroServiceProvider;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\Data\Advisor;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Data\Company;
use App\Modules\Core\Contracts\Data\Invoice;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;

/**
 * 4.2: once a month at close (config cobro.circularizacion: the 1st at
 * 09:00, Bogotá time), each client with a balance gets the statement of
 * every open item at the cutoff of the latest snapshot, in the format its
 * company sends today (cobro::circularizacion.estado-cartera). The mail
 * copies (Cc) the same recipients as the company's weekly collection
 * (CopyStore); a test send copies nobody.
 *
 * The draft mechanism stays for a future format change: while
 * cobro.circularizacion.draft is true the scheduled run sends nothing and
 * the mails say "Borrador".
 */
final class CircularizationRun implements RunDefinition
{
    public const string VIEW = 'cobro::circularizacion.estado-cartera';

    public function __construct(
        private readonly CarteraRepository $cartera,
        private readonly TemplateStore $templates,
        private readonly NoticeRenderer $renderer,
        private readonly CopyStore $copies,
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
        return (bool) config('cobro.circularizacion.draft', false);
    }

    public function nextRunAt(CarbonImmutable $now): CarbonImmutable
    {
        return RunCalendar::nextMonthly(
            $now->setTimezone(CobroServiceProvider::TIMEZONE),
            (int) config('cobro.circularizacion.day', 1),
            (string) config('cobro.circularizacion.time', '09:00'),
        );
    }

    /**
     * Each client with a balance whose open items still add up to a debt;
     * the amount is that net total.
     */
    public function candidates(CompanyCode $company): iterable
    {
        $cartera = $this->cartera->asSystem();
        $copies = $this->copies->for($company);

        foreach ($cartera->allClients(new ClientQuery(companies: [$company])) as $client) {
            if ($client->balance <= 0) {
                continue;
            }

            $items = self::open($cartera->openInvoices($client->id));

            if (! self::owes($items)) {
                continue;
            }

            $detail = $cartera->client($client->id);
            $emails = $detail?->emails() ?? [];

            yield new Candidate(
                $client,
                $emails,
                self::total($items),
                count($items),
                CopyRecipients::resolve($copies, self::advisorEmail($client->advisor), $detail?->group, $emails),
            );
        }
    }

    /**
     * Every open item of the client, credit notes included so the total is
     * what the client owes (the weekly mail's rule), while that total is a
     * debt.
     */
    public function notice(int $clientId): ?NoticeData
    {
        $cartera = $this->cartera->asSystem();
        $client = $cartera->client($clientId);
        $open = $client === null ? [] : self::open($cartera->openInvoices($clientId));

        if ($client === null || ! self::owes($open)) {
            return null;
        }

        $summary = $client->summary;
        $advisorNames = $summary->advisor === null ? [] : [$summary->advisor->code => $summary->advisor->name];
        $others = array_diff(array_filter(array_map(fn (Invoice $invoice): ?int => $invoice->advisorCode, $open)), array_keys($advisorNames));

        // The advisor list is read only when an invoice belongs to another
        // advisor than the client's.
        if ($others !== []) {
            foreach ($cartera->advisors() as $advisor) {
                if ($advisor->company === $summary->company) {
                    $advisorNames[$advisor->code] ??= $advisor->name;
                }
            }
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
            advisorEmail: self::advisorEmail($summary->advisor),
        );
    }

    /**
     * The mail with its carbon copies; a test send goes to the user only,
     * so it copies nobody.
     */
    public function render(Company $company, NoticeData $notice, bool $test = false): RenderedMail
    {
        $mail = $this->renderer->render(
            self::VIEW,
            $company,
            $notice,
            $this->templates->for($company->code, RunKind::Circularization),
            test: $test,
            draft: $this->isDraft(),
        );

        if ($test) {
            return $mail;
        }

        return new RenderedMail(
            $mail->subject,
            $mail->html,
            CopyRecipients::resolve($this->copies->for($company->code), $notice->advisorEmail, $notice->group),
        );
    }

    /**
     * The e-mail the advisor is copied at: none when SAP lists the advisor
     * as inactive (someone who left the company).
     */
    private static function advisorEmail(?Advisor $advisor): ?string
    {
        return $advisor !== null && $advisor->active ? $advisor->email : null;
    }

    /**
     * The items that still carry a balance, credit notes included.
     *
     * @param  list<Invoice>  $invoices
     * @return list<Invoice>
     */
    private static function open(array $invoices): array
    {
        return array_values(array_filter($invoices, fn (Invoice $invoice): bool => abs($invoice->balance) >= 0.005));
    }

    /**
     * @param  list<Invoice>  $invoices
     */
    private static function total(array $invoices): float
    {
        return array_sum(array_map(fn (Invoice $invoice): float => $invoice->balance, $invoices));
    }

    /**
     * Whether the items add up to a debt (a client whose credit notes cover
     * its invoices has nothing to confirm).
     *
     * @param  list<Invoice>  $invoices
     */
    private static function owes(array $invoices): bool
    {
        return self::total($invoices) >= 0.005;
    }
}
