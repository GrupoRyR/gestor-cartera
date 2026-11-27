<?php

namespace App\Modules\Cobro\Infrastructure\Reports;

use App\Modules\Cobro\Application\Reports\MailReportFilter;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Enums\CompanyCode;

/**
 * What the collection report needs from Core about each client: its
 * current SAP advisor and SAP group. Cobro keeps neither, so read() takes
 * them with one CarteraRepository::allClients() pass per company, once a
 * request, and the filters, the page, the export and the group options
 * all read from it.
 */
final readonly class ReportClients
{
    /**
     * @param  array<int, array{advisorKey: string|null, advisorName: string|null, group: string|null}>  $clients  By client id.
     */
    private function __construct(private array $clients) {}

    /**
     * @param  list<CompanyCode>  $companies
     */
    public static function read(CarteraRepository $cartera, array $companies): self
    {
        $clients = [];

        foreach ($companies as $company) {
            foreach ($cartera->allClients(new ClientQuery(companies: [$company])) as $client) {
                $group = trim((string) $client->group);

                $clients[$client->id] = [
                    'advisorKey' => $client->advisor === null ? null : MailReportFilter::advisorKey($client->advisor->company, $client->advisor->code),
                    'advisorName' => $client->advisor?->name,
                    'group' => $group === '' ? null : $group,
                ];
            }
        }

        return new self($clients);
    }

    public function advisorName(int $clientId): ?string
    {
        return $this->clients[$clientId]['advisorName'] ?? null;
    }

    public function group(int $clientId): ?string
    {
        return $this->clients[$clientId]['group'] ?? null;
    }

    /**
     * The groups of the clients, once each, by name without regard to case.
     *
     * @return list<string>
     */
    public function groups(): array
    {
        $groups = array_values(array_unique(array_filter(array_column($this->clients, 'group'), fn (?string $group): bool => $group !== null)));
        usort($groups, fn (string $a, string $b): int => [mb_strtolower($a), $a] <=> [mb_strtolower($b), $b]);

        return $groups;
    }

    /**
     * Ids of the clients of the advisor MailReportFilter::advisorKey() names.
     *
     * @return list<int>
     */
    public function ofAdvisor(string $advisorKey): array
    {
        return $this->idsWhere('advisorKey', $advisorKey);
    }

    /**
     * Ids of the clients of the group.
     *
     * @return list<int>
     */
    public function ofGroup(string $group): array
    {
        return $this->idsWhere('group', $group);
    }

    /**
     * @param  'advisorKey'|'group'  $field
     * @return list<int>
     */
    private function idsWhere(string $field, string $value): array
    {
        $ids = [];

        foreach ($this->clients as $id => $client) {
            if ($client[$field] === $value) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
