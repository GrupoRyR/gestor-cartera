<?php

namespace Tests\Modules\Cartera\Support;

use App\Modules\Core\Contracts\Data\Advisor;
use App\Modules\Core\Contracts\Data\ClientSummary;
use App\Modules\Core\Contracts\Data\Contact;
use App\Modules\Core\Contracts\Data\UserRef;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\Make;

/**
 * Builds clients and their open invoices inside the contract fakes, so a
 * test only states the numbers it cares about.
 */
final class CarteraWorld
{
    /**
     * Adds a client with one overdue invoice and one that is not due yet,
     * whose balances add up to the client's balance and overdue amounts.
     */
    public static function client(
        ContractFakes $fakes,
        string $name = 'Ferretería El Tornillo SAS',
        CompanyCode $company = CompanyCode::SGX,
        float $overdue = 0.0,
        float $notDue = 0.0,
        int $daysOverdue = 45,
        ClientStatus $status = ClientStatus::Current,
        ?Advisor $advisor = null,
        ?UserRef $manager = null,
        ?CarbonImmutable $lastActivityAt = null,
        ?string $taxId = null,
        ?string $group = 'Distribuidores',
        ?string $city = 'Bogotá D.C.',
        ?float $creditLimit = 50_000_000.0,
        ?array $contacts = null,
    ): ClientSummary {
        $summary = Make::client(
            company: $company,
            name: $name,
            taxId: $taxId,
            status: $status,
            balance: $overdue + $notDue,
            overdue: $overdue,
            maxDaysOverdue: $overdue > 0 ? $daysOverdue : 0,
            advisor: $advisor,
            manager: $manager,
            lastActivityAt: $lastActivityAt,
        );

        $invoices = [];

        if ($overdue > 0) {
            $invoices[] = Make::invoice(company: $company, clientId: $summary->id, amount: $overdue, daysOverdue: $daysOverdue);
        }

        if ($notDue > 0) {
            $invoices[] = Make::invoice(company: $company, clientId: $summary->id, amount: $notDue, daysOverdue: -10);
        }

        $fakes->cartera->addClient(
            Make::clientDetail($summary, creditLimit: $creditLimit, group: $group, city: $city, contacts: $contacts ?? [Make::contact(isPrimary: true)]),
            ...$invoices,
        );

        return $summary;
    }

    /**
     * A contact without e-mail or phone, as SAP sometimes has them.
     */
    public static function bareContact(string $name): Contact
    {
        return Make::contact(name: $name, email: null, phone: null, position: null);
    }
}
