<?php

namespace App\Modules\Core\Infrastructure\Portfolio;

use App\Models\User;
use App\Modules\Core\Contracts\Data\Advisor;
use App\Modules\Core\Contracts\Data\ClientDetail;
use App\Modules\Core\Contracts\Data\ClientSummary;
use App\Modules\Core\Contracts\Data\Contact;
use App\Modules\Core\Contracts\Data\Invoice;
use App\Modules\Core\Contracts\Data\UserRef;
use App\Modules\Core\Infrastructure\Persistence\AdvisorRecord;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Persistence\ContactRecord;
use App\Modules\Core\Infrastructure\Persistence\InvoiceRecord;
use Illuminate\Database\Eloquent\Builder;

/**
 * Turns portfolio records into the contract data objects of
 * CarteraRepository. Exact decimal strings become pesos as float; dates stay
 * CarbonImmutable in America/Bogota.
 */
final class PortfolioMapper
{
    /**
     * The summaries of $clients, in their order, with the SAP advisor (by
     * company and code) and the manager (soft-deleted users too) of the whole
     * list read in one query each: strict models forbid lazy loading.
     *
     * @param  array<int, ClientRecord>  $clients
     * @return list<ClientSummary>
     */
    public function summaries(array $clients): array
    {
        $codes = [];
        $managerIds = [];

        foreach ($clients as $client) {
            if ($client->advisor_code !== null) {
                $codes[$client->company->value][$client->advisor_code] = $client->advisor_code;
            }

            if ($client->manager_user_id !== null) {
                $managerIds[$client->manager_user_id] = $client->manager_user_id;
            }
        }

        $advisors = $this->advisorsByCode($codes);
        $managers = $this->managers(array_values($managerIds));
        $summaries = [];

        foreach ($clients as $client) {
            $summaries[] = new ClientSummary(
                id: $client->id,
                company: $client->company,
                code: $client->code,
                name: $client->name,
                taxId: $client->tax_id,
                status: $client->status,
                balance: (float) $client->balance,
                overdue: (float) $client->overdue,
                // The cache is negative while nothing is past due; the contract shows 0.
                maxDaysOverdue: max(0, $client->max_days_overdue),
                advisor: $client->advisor_code === null ? null : $advisors["{$client->company->value}-{$client->advisor_code}"] ?? null,
                manager: $client->manager_user_id === null ? null : $managers[$client->manager_user_id] ?? null,
                lastActivityAt: $client->last_activity_at,
            );
        }

        return $summaries;
    }

    /**
     * The client file of $client, with its contacts: primary ones first, then
     * in the order they were saved.
     */
    public function detail(ClientRecord $client): ClientDetail
    {
        $contacts = [];

        foreach (ContactRecord::query()->where('client_id', $client->id)->orderByDesc('is_primary')->orderBy('id')->get() as $contact) {
            $contacts[] = new Contact(
                name: $contact->name,
                email: $contact->email,
                phone: $contact->phone,
                position: $contact->position,
                isPrimary: $contact->is_primary,
            );
        }

        return new ClientDetail(
            summary: $this->summaries([$client])[0],
            creditLimit: $client->credit_limit === null ? null : (float) $client->credit_limit,
            group: $client->group,
            city: $client->city,
            paymentTerms: $client->payment_terms,
            contacts: $contacts,
        );
    }

    public function invoice(InvoiceRecord $invoice): Invoice
    {
        return new Invoice(
            id: $invoice->id,
            company: $invoice->company,
            clientId: $invoice->client_id,
            documentNumber: $invoice->document_number,
            documentType: $invoice->document_type,
            issuedAt: $invoice->issued_at,
            dueAt: $invoice->due_at,
            amount: (float) $invoice->amount,
            balance: (float) $invoice->balance,
            daysOverdue: $invoice->days_overdue,
            advisorCode: $invoice->advisor_code,
        );
    }

    public function advisor(AdvisorRecord $advisor): Advisor
    {
        return new Advisor(
            company: $advisor->company,
            code: $advisor->code,
            name: $advisor->name,
            email: $advisor->email,
            active: $advisor->active,
            userId: $advisor->user_id,
        );
    }

    /**
     * @param  array<string, array<int, int>>  $codes  Advisor codes by company value.
     * @return array<string, Advisor> By «<COMPANY>-<code>».
     */
    private function advisorsByCode(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $records = AdvisorRecord::query()
            ->where(function (Builder $wanted) use ($codes): void {
                foreach ($codes as $company => $inCompany) {
                    $wanted->orWhere(fn (Builder $advisor) => $advisor->where('company', $company)->whereIn('code', array_values($inCompany)));
                }
            })
            ->get();

        $advisors = [];

        foreach ($records as $record) {
            $advisors["{$record->company->value}-{$record->code}"] = $this->advisor($record);
        }

        return $advisors;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, UserRef> By user id.
     */
    private function managers(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $managers = [];

        foreach (User::withTrashed()->whereIn('id', $ids)->get(['id', 'name', 'email']) as $user) {
            $managers[$user->id] = new UserRef($user->id, $user->name, $user->email);
        }

        return $managers;
    }
}
