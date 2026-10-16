<?php

namespace App\Modules\Comisiones\Domain\Parameters;

use App\Modules\Comisiones\Domain\Shared\Percentage;
use App\Modules\Comisiones\Domain\Shared\PersonRef;
use App\Modules\Comisiones\Domain\Shared\Validity;
use InvalidArgumentException;

/**
 * One version of the commission parameters of a seller code (§4
 * "ParametroComision", Annex 1).
 */
final readonly class SellerCodeRule
{
    /**
     * @param  int  $code  SAP seller code (§4), 1 or more: SAP never reports code 0 as a seller.
     * @param  string  $name  Name shown in reports ("Mónica Rivera", "Oficina – Varios").
     * @param  PersonRef|null  $person  Who gets paid; null settles the code as its own person.
     * @param  Percentage  $saleRate  Rate on the code's total sales when it meets the minimum (§5.2).
     * @param  Percentage  $collectionRate  Rate on each payment's base without VAT (§5.3).
     * @param  Percentage  $minimumCompliance  Sales over budget needed to earn the sales commission;
     *                                         zero pays from the first peso (§5.2).
     * @param  bool  $usesTranches  False pays the full collection rate whatever the days (code 85, §5.3).
     *
     * @throws InvalidArgumentException when the code is below 1 or the name is blank.
     */
    public function __construct(
        public Validity $validity,
        public int $code,
        public string $name,
        public ?PersonRef $person,
        public Percentage $saleRate,
        public Percentage $collectionRate,
        public Percentage $minimumCompliance,
        public CodeScope $scope,
        public bool $usesTranches = true,
    ) {
        if ($code < 1) {
            throw new InvalidArgumentException("SAP seller codes start at 1, got {$code}.");
        }

        if (trim($name) === '') {
            throw new InvalidArgumentException("Seller code {$code} needs a name.");
        }
    }

    /**
     * The key that groups the codes of one person (§5.7): the person id, or
     * "codigo-{code}" when the code has no person.
     */
    public function personKey(): string
    {
        return $this->personRef()->id;
    }

    /** The person paid for this code; without one, the code itself under its name. */
    public function personRef(): PersonRef
    {
        return $this->person ?? new PersonRef("codigo-{$this->code}", $this->name);
    }
}
