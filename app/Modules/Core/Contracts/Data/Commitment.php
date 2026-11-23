<?php

namespace App\Modules\Core\Contracts\Data;

use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;

/**
 * A payment commitment (compromiso de pago) of a client.
 */
final readonly class Commitment
{
    /**
     * @param  CompanyCode  $company  Company of the client.
     * @param  int  $clientId  Platform id of the client.
     * @param  string  $clientName  Client name at the time of reading, for lists.
     * @param  int|null  $advisorCode  Advisor of the client (OSLP.SlpCode) when the commitment was created.
     * @param  float  $amount  Promised amount in Colombian pesos.
     * @param  CarbonImmutable  $dueDate  Promised payment date (date only, America/Bogota).
     * @param  UserRef  $createdBy  User who registered it.
     * @param  CarbonImmutable  $createdAt  Moment it was registered.
     * @param  CarbonImmutable|null  $fulfilledAt  Moment it was marked fulfilled; null otherwise.
     * @param  float|null  $paidAmount  Amount paid when it was closed as partially paid, in pesos; null otherwise.
     * @param  CarbonImmutable|null  $partiallyPaidAt  Moment it was closed as partially paid; null otherwise.
     */
    public function __construct(
        public int $id,
        public CompanyCode $company,
        public int $clientId,
        public string $clientName,
        public ?int $advisorCode,
        public float $amount,
        public CarbonImmutable $dueDate,
        public CommitmentStatus $status,
        public ?string $notes,
        public UserRef $createdBy,
        public CarbonImmutable $createdAt,
        public ?CarbonImmutable $fulfilledAt = null,
        public ?float $paidAmount = null,
        public ?CarbonImmutable $partiallyPaidAt = null,
    ) {}

    /**
     * What is still owed after a partial payment, in pesos rounded to
     * cents; null unless it is PartiallyPaid with a paid amount. Derived,
     * never stored.
     */
    public function remaining(): ?float
    {
        if ($this->status !== CommitmentStatus::PartiallyPaid || $this->paidAmount === null) {
            return null;
        }

        return round($this->amount - $this->paidAmount, 2);
    }
}
