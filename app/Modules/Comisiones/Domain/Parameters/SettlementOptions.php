<?php

namespace App\Modules\Comisiones\Domain\Parameters;

use App\Modules\Comisiones\Domain\Parameters\Options\ComplianceScope;
use App\Modules\Comisiones\Domain\Parameters\Options\CreditNoteTreatment;
use App\Modules\Comisiones\Domain\Parameters\Options\CrossingSellerDate;
use App\Modules\Comisiones\Domain\Parameters\Options\CurrencyTreatment;
use App\Modules\Comisiones\Domain\Parameters\Options\DirectorPlacement;
use App\Modules\Comisiones\Domain\Parameters\Options\SalesBasis;
use App\Modules\Comisiones\Domain\Shared\Percentage;

/**
 * How the settlement answers the points still to confirm (§14.2–§14.7).
 * Every default reproduces the current Excel behaviour, so a settlement
 * without options matches the workbook.
 *
 * - §14.2 VAT in sales: $salesBasis and $salesVatRate.
 * - §14.2 credit notes and returns: $creditNotes.
 * - §14.3 crossings in the 85.1 base: $crossingsInDerivedCollections.
 * - §14.3 crossings in the director collections: $crossingsInDirectorCollections.
 * - §14.4 where the director commission of 50/53 goes: $directorPlacement.
 * - §14.5 which seller gets a crossing: $crossingSeller.
 * - §14.6 compliance per code or per person: $complianceScope.
 * - §14.7 foreign currency: $currency.
 */
final readonly class SettlementOptions
{
    /** VAT rate removed from sales when $salesBasis is IncludesVat (19 % by default). */
    public Percentage $salesVatRate;

    /**
     * @param  Percentage|null  $salesVatRate  Null means 19 %.
     */
    public function __construct(
        public SalesBasis $salesBasis = SalesBasis::AsReported,
        ?Percentage $salesVatRate = null,
        public CreditNoteTreatment $creditNotes = CreditNoteTreatment::Net,
        public bool $crossingsInDerivedCollections = false,
        public bool $crossingsInDirectorCollections = false,
        public DirectorPlacement $directorPlacement = DirectorPlacement::Separate,
        public CrossingSellerDate $crossingSeller = CrossingSellerDate::CrossingDate,
        public ComplianceScope $complianceScope = ComplianceScope::Code,
        public CurrencyTreatment $currency = CurrencyTreatment::AsReported,
    ) {
        $this->salesVatRate = $salesVatRate ?? Percentage::of(19);
    }
}
