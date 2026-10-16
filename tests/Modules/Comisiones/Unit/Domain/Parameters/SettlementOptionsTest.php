<?php

use App\Modules\Comisiones\Domain\Parameters\Options\ComplianceScope;
use App\Modules\Comisiones\Domain\Parameters\Options\CreditNoteTreatment;
use App\Modules\Comisiones\Domain\Parameters\Options\CrossingSellerDate;
use App\Modules\Comisiones\Domain\Parameters\Options\CurrencyTreatment;
use App\Modules\Comisiones\Domain\Parameters\Options\DirectorPlacement;
use App\Modules\Comisiones\Domain\Parameters\Options\SalesBasis;
use App\Modules\Comisiones\Domain\Parameters\SettlementOptions;
use App\Modules\Comisiones\Domain\Shared\Percentage;

describe('defaults', function () {
    it('reproduces the current Excel behaviour', function () {
        $options = new SettlementOptions;

        expect($options->salesBasis)->toBe(SalesBasis::AsReported)
            ->and($options->salesVatRate->percent())->toBe('19')
            ->and($options->creditNotes)->toBe(CreditNoteTreatment::Net)
            ->and($options->crossingsInDerivedCollections)->toBeFalse()
            ->and($options->crossingsInDirectorCollections)->toBeFalse()
            ->and($options->directorPlacement)->toBe(DirectorPlacement::Separate)
            ->and($options->crossingSeller)->toBe(CrossingSellerDate::CrossingDate)
            ->and($options->complianceScope)->toBe(ComplianceScope::Code)
            ->and($options->currency)->toBe(CurrencyTreatment::AsReported);
    });

    it('accepts every alternative', function () {
        $options = new SettlementOptions(
            salesBasis: SalesBasis::IncludesVat,
            salesVatRate: Percentage::of(5),
            creditNotes: CreditNoteTreatment::Exclude,
            crossingsInDerivedCollections: true,
            crossingsInDirectorCollections: true,
            directorPlacement: DirectorPlacement::AssociatedCode,
            crossingSeller: CrossingSellerDate::InvoiceDate,
            complianceScope: ComplianceScope::Person,
            currency: CurrencyTreatment::Convert,
        );

        expect($options->salesBasis)->toBe(SalesBasis::IncludesVat)
            ->and($options->salesVatRate->percent())->toBe('5')
            ->and($options->creditNotes)->toBe(CreditNoteTreatment::Exclude)
            ->and($options->crossingsInDerivedCollections)->toBeTrue()
            ->and($options->crossingsInDirectorCollections)->toBeTrue()
            ->and($options->directorPlacement)->toBe(DirectorPlacement::AssociatedCode)
            ->and($options->crossingSeller)->toBe(CrossingSellerDate::InvoiceDate)
            ->and($options->complianceScope)->toBe(ComplianceScope::Person)
            ->and($options->currency)->toBe(CurrencyTreatment::Convert);
    });
});

describe('option labels', function () {
    it('labels every case in Spanish', function (UnitEnum $case) {
        expect(method_exists($case, 'label'))->toBeTrue()
            ->and($case->label())->toBeString()->not->toBeEmpty()
            ->and(mb_substr($case->label(), 0, 1))->toBe(mb_strtoupper(mb_substr($case->label(), 0, 1)));
    })->with(fn () => [
        ...SalesBasis::cases(),
        ...CreditNoteTreatment::cases(),
        ...DirectorPlacement::cases(),
        ...CrossingSellerDate::cases(),
        ...ComplianceScope::cases(),
        ...CurrencyTreatment::cases(),
    ]);

    it('keeps the stored values stable', function () {
        expect(SalesBasis::AsReported->value)->toBe('reportado')
            ->and(SalesBasis::IncludesVat->value)->toBe('con_iva')
            ->and(CreditNoteTreatment::Net->value)->toBe('netear')
            ->and(CreditNoteTreatment::Exclude->value)->toBe('excluir')
            ->and(DirectorPlacement::Separate->value)->toBe('aparte')
            ->and(DirectorPlacement::AssociatedCode->value)->toBe('codigo_asociado')
            ->and(CrossingSellerDate::CrossingDate->value)->toBe('fecha_cruce')
            ->and(CrossingSellerDate::InvoiceDate->value)->toBe('fecha_factura')
            ->and(ComplianceScope::Code->value)->toBe('codigo')
            ->and(ComplianceScope::Person->value)->toBe('persona')
            ->and(CurrencyTreatment::AsReported->value)->toBe('reportado')
            ->and(CurrencyTreatment::Convert->value)->toBe('convertir');
    });
});
