<?php

namespace App\Modules\Soportes\Application\Inbox;

use App\Models\User;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Data\Company;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Soportes\Infrastructure\Models\Receipt;
use Illuminate\Database\Eloquent\Builder;

/**
 * The receipts a user's inbox lists, by the same rule as ReceiptPolicy::view.
 *
 * Reviewers (soportes.review) list the receipts of the companies in view
 * (CurrentCompany::scope(), so the inbox follows the company switcher).
 * Everyone else (the advisor) lists the receipts they uploaded and the ones
 * of their own clients, whoever uploaded them. Client ownership is read live
 * from CarteraRepository, which applies the advisor scope, without one
 * client() call per row: allClients() is walked once and only the clients
 * that have receipts are kept, so the IN list stays as short as the inbox.
 */
final readonly class VisibleReceipts
{
    public function __construct(
        private CurrentCompany $current,
        private CarteraRepository $cartera,
    ) {}

    /**
     * @return Builder<Receipt>
     */
    public function for(User $user): Builder
    {
        if ($user->can('soportes.review')) {
            return Receipt::query()->forCompanies($this->companiesInView());
        }

        $clients = $this->ownClientsWithReceipts();

        return Receipt::query()->where(function (Builder $query) use ($user, $clients): void {
            $query->where('uploaded_by', $user->getKey());

            if ($clients !== []) {
                $query->orWhereIn('client_id', $clients);
            }
        });
    }

    /**
     * The menu badge: pending receipts of the companies in view, only for
     * reviewers; null (no badge) for everyone else and when none is pending.
     */
    public function pendingCount(User $user): ?int
    {
        if (! $user->can('soportes.review')) {
            return null;
        }

        return Receipt::query()->forCompanies($this->companiesInView())->pending()->count() ?: null;
    }

    /**
     * @return list<CompanyCode>
     */
    private function companiesInView(): array
    {
        return array_map(fn (Company $company): CompanyCode => $company->code, $this->current->scope());
    }

    /**
     * Ids of the user's clients (in the companies in view) that have at
     * least one receipt.
     *
     * @return list<int>
     */
    private function ownClientsWithReceipts(): array
    {
        $withReceipts = [];

        foreach (Receipt::query()->forCompanies($this->companiesInView())->distinct()->pluck('client_id') as $id) {
            $withReceipts[(int) $id] = true;
        }

        if ($withReceipts === []) {
            return [];
        }

        $own = [];

        foreach ($this->cartera->allClients(new ClientQuery) as $client) {
            if (isset($withReceipts[$client->id])) {
                $own[] = $client->id;
            }
        }

        return $own;
    }
}
