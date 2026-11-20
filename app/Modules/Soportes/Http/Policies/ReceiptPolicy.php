<?php

namespace App\Modules\Soportes\Http\Policies;

use App\Models\User;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Soportes\Infrastructure\Models\Receipt;

/**
 * Who may open and review a payment receipt.
 *
 * Reviewers (soportes.review) work by company: any receipt of a company
 * available to them. Everyone else (the advisor) sees the receipts they
 * uploaded and the ones of their own clients, whoever uploaded them. Client
 * ownership is read live from CarteraRepository, which already applies the
 * advisor scope, so a reassigned client follows its new advisor.
 */
final readonly class ReceiptPolicy
{
    public function __construct(
        private CurrentCompany $current,
        private CarteraRepository $cartera,
    ) {}

    /**
     * Open the receipts inbox.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('soportes.view');
    }

    /**
     * Upload a receipt. Which clients the user may pick is the upload's own
     * rule (CarteraRepository::client() must find the client).
     */
    public function create(User $user): bool
    {
        return $user->can('soportes.upload');
    }

    /**
     * Open one receipt and download its files.
     */
    public function view(User $user, Receipt $receipt): bool
    {
        if (! $user->can('soportes.view')) {
            return false;
        }

        if ($user->can('soportes.review')) {
            return $this->worksIn($receipt->company);
        }

        return $receipt->uploaded_by === $user->getKey()
            || $this->cartera->client($receipt->client_id) !== null;
    }

    /**
     * Mark the receipt applied or rejected: a reviewer of its company, and
     * only while it is pending (applied and rejected are final).
     */
    public function review(User $user, Receipt $receipt): bool
    {
        return $user->can('soportes.review')
            && $this->worksIn($receipt->company)
            && ! $receipt->status->isFinal();
    }

    private function worksIn(CompanyCode $company): bool
    {
        foreach ($this->current->available() as $available) {
            if ($available->code === $company) {
                return true;
            }
        }

        return false;
    }
}
