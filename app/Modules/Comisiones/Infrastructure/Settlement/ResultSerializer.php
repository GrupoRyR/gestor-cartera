<?php

namespace App\Modules\Comisiones\Infrastructure\Settlement;

use App\Modules\Comisiones\Domain\Calculation\SettlementResult;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\BigNumber;
use Brick\Math\BigRational;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Turns a SettlementResult into the text stored in comisiones_liquidaciones
 * and back: serialize, gzcompress and base64, fingerprinted with sha256.
 *
 * Reading back checks the fingerprint first and unserializes only the
 * engine's own classes (everything under Comisiones\Domain), dates and the
 * Brick\Math numbers, so a tampered row never builds another object.
 */
final class ResultSerializer
{
    /**
     * Every class and enum under Comisiones\Domain, relative to it. A static
     * list, so decoding neither scans the directory nor autoloads the domain;
     * ResultSerializerTest keeps it equal to the directory.
     */
    private const array DOMAIN = [
        'Calculation\Alert',
        'Calculation\AlertCollector',
        'Calculation\AlertKind',
        'Calculation\AlertSeverity',
        'Calculation\CaptureFigures',
        'Calculation\CodeSettlement',
        'Calculation\CollectionCommission',
        'Calculation\CollectionDetail',
        'Calculation\CollectionFigures',
        'Calculation\CollectionOutcome',
        'Calculation\CollectionSource',
        'Calculation\ControlTotals',
        'Calculation\CrossingResolver',
        'Calculation\DerivedCommission',
        'Calculation\DerivedSettlement',
        'Calculation\DirectorCommission',
        'Calculation\DirectorSettlement',
        'Calculation\DirectorTeamRow',
        'Calculation\LineFilter',
        'Calculation\ManualCaptures',
        'Calculation\PersonRole',
        'Calculation\PersonSettlement',
        'Calculation\PersonTotals',
        'Calculation\RoleKind',
        'Calculation\SaleDetail',
        'Calculation\SalesCommission',
        'Calculation\SalesFigures',
        'Calculation\SalesOutcome',
        'Calculation\SettlementCalculator',
        'Calculation\SettlementResult',
        'Input\AdjustmentKind',
        'Input\Budget',
        'Input\ClientAssignment',
        'Input\CrossingLine',
        'Input\CrossingSource',
        'Input\CrossingType',
        'Input\DocumentType',
        'Input\LineFields',
        'Input\ManualAdjustment',
        'Input\ObrSale',
        'Input\PaymentLine',
        'Input\SaleLine',
        'Input\SettlementInput',
        'Parameters\CodeScope',
        'Parameters\CollectionTranche',
        'Parameters\DerivedBase',
        'Parameters\DerivedCommissionRule',
        'Parameters\DirectorTeam',
        'Parameters\MissingParameters',
        'Parameters\Options\ComplianceScope',
        'Parameters\Options\CreditNoteTreatment',
        'Parameters\Options\CrossingSellerDate',
        'Parameters\Options\CurrencyTreatment',
        'Parameters\Options\DirectorPlacement',
        'Parameters\Options\SalesBasis',
        'Parameters\OptionsVersion',
        'Parameters\ParameterBook',
        'Parameters\PeriodParameters',
        'Parameters\SellerCodeRule',
        'Parameters\SettlementOptions',
        'Parameters\TrancheTable',
        'Settlement\InvalidTransition',
        'Settlement\SegregationOfDuties',
        'Settlement\Settlement',
        'Settlement\SettlementLocked',
        'Settlement\SettlementStatus',
        'Settlement\StaleSettlement',
        'Settlement\UnresolvedAlerts',
        'Shared\Dates',
        'Shared\Decimals',
        'Shared\Money',
        'Shared\Percentage',
        'Shared\Period',
        'Shared\PersonRef',
        'Shared\SellerCode',
        'Shared\Validity',
    ];

    /**
     * @return array{string, string} The stored text and its sha256.
     */
    public function encode(SettlementResult $result): array
    {
        $payload = base64_encode((string) gzcompress(serialize($result)));

        return [$payload, hash('sha256', $payload)];
    }

    /**
     * @param  string  $period  "YYYY-MM", for the error.
     *
     * @throws CorruptSettlement when the fingerprint differs or the text is not a result.
     */
    public function decode(string $payload, string $sha256, string $period, int $version): SettlementResult
    {
        if (! hash_equals($sha256, hash('sha256', $payload))) {
            throw CorruptSettlement::fingerprint($period, $version);
        }

        try {
            $compressed = base64_decode($payload, true);
            $serialized = $compressed === false ? false : gzuncompress($compressed);
            $result = $serialized === false ? null : unserialize($serialized, ['allowed_classes' => self::allowedClasses()]);
        } catch (Throwable $error) {
            throw CorruptSettlement::unreadable($period, $version, $error);
        }

        if (! $result instanceof SettlementResult) {
            throw CorruptSettlement::unreadable($period, $version);
        }

        return $result;
    }

    /**
     * Every class and enum of the engine's domain, the date classes and the
     * Brick\Math numbers.
     *
     * @return list<class-string>
     */
    public static function allowedClasses(): array
    {
        /** @var list<class-string> $domain */
        $domain = array_map(fn (string $class): string => 'App\Modules\Comisiones\Domain\\'.$class, self::DOMAIN);

        return [...$domain, DateTimeImmutable::class, DateTimeZone::class, BigNumber::class, BigDecimal::class, BigInteger::class, BigRational::class];
    }
}
