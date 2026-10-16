<?php

namespace Tests\Modules\Core\Support;

use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\Advisor;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Data\ClientSummary;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Persistence\InvoiceRecord;
use App\Modules\Core\Infrastructure\Persistence\PortfolioLoadRecord;
use Carbon\CarbonImmutable;

/**
 * Snapshots, clients and invoices for the CarteraRepository tests, with the
 * exact figures each test needs, and readers of what the repository
 * returns.
 */
final class CarteraRecords
{
    /**
     * The repository of the container, as a controller gets it.
     */
    public static function repository(): CarteraRepository
    {
        return app(CarteraRepository::class);
    }

    /**
     * Switches the signed-in user to $company, or to the group view with null.
     */
    public static function switchTo(?CompanyCode $company): void
    {
        app(CurrentCompany::class)->switchTo($company);
    }

    /**
     * A receivables snapshot of $company cut on $cutoff ("Y-m-d").
     */
    public static function snapshot(CompanyCode $company = CompanyCode::SGX, string $cutoff = '2026-10-15'): PortfolioLoadRecord
    {
        return PortfolioLoadRecord::factory()->create(['company' => $company, 'cutoff_date' => $cutoff]);
    }

    /**
     * A client of Segurex named $name, unless $attributes say otherwise.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function client(string $name, array $attributes = []): ClientRecord
    {
        return ClientRecord::factory()->create(['name' => $name, ...$attributes]);
    }

    /**
     * An item of $client in $snapshot with $balance ("1234.56"), due
     * $daysOverdue days before the cutoff date and issued 30 days earlier.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function invoice(
        PortfolioLoadRecord $snapshot,
        ClientRecord $client,
        string $balance,
        int $daysOverdue = 0,
        array $attributes = [],
    ): InvoiceRecord {
        $dueAt = $snapshot->cutoff_date->subDays($daysOverdue);

        return InvoiceRecord::factory()->create([
            'portfolio_load_id' => $snapshot->id,
            'company' => $snapshot->company,
            'client_id' => $client->id,
            'issued_at' => $dueAt->subDays(30)->toDateString(),
            'due_at' => $dueAt->toDateString(),
            'amount' => $balance,
            'balance' => $balance,
            'days_overdue' => $daysOverdue,
            'advisor_code' => $client->advisor_code,
            ...$attributes,
        ]);
    }

    /**
     * The names of every client the repository lists for $query, in order.
     *
     * @return list<string>
     */
    public static function names(?ClientQuery $query = null, ?CarteraRepository $repository = null): array
    {
        $names = [];

        foreach (($repository ?? self::repository())->allClients($query ?? new ClientQuery) as $client) {
            $names[] = $client->name;
        }

        return $names;
    }

    /**
     * The names of a page or list of clients, in order.
     *
     * @param  iterable<ClientSummary>  $clients
     * @return list<string>
     */
    public static function namesOf(iterable $clients): array
    {
        $names = [];

        foreach ($clients as $client) {
            $names[] = $client->name;
        }

        return $names;
    }

    /**
     * The advisors the repository shows, as «<COMPANY>-<code> <name>» labels
     * in its order.
     *
     * @return list<string>
     */
    public static function advisors(?CarteraRepository $repository = null): array
    {
        return array_map(
            fn (Advisor $advisor): string => "{$advisor->company->value}-{$advisor->code} {$advisor->name}",
            ($repository ?? self::repository())->advisors(),
        );
    }

    /**
     * A moment of the cartera tests' calendar, in America/Bogota.
     */
    public static function at(string $moment): CarbonImmutable
    {
        return CarbonImmutable::parse($moment, 'America/Bogota');
    }
}
