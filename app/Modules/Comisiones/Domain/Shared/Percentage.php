<?php

namespace App\Modules\Comisiones\Domain\Shared;

use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * A non-negative percentage, stored as its exact fraction (0.75 % → 0.0075).
 *
 * Used for commission rates, minimum compliance, tranche shares and the
 * displayed compliance. Values above 100 % are valid (a compliance of
 * 108.2 %); negative values are not.
 */
final readonly class Percentage
{
    private function __construct(private BigDecimal $fraction) {}

    /**
     * From the percent number as people write it: Percentage::of('0.75') is 0.75 %.
     *
     * @throws InvalidArgumentException when the value is negative or not a plain decimal.
     */
    public static function of(string|int $percent): self
    {
        return self::fromFraction(Decimals::parse($percent)->withPointMovedLeft(2));
    }

    /**
     * From the fraction: Percentage::fromFraction('0.0075') is 0.75 %.
     *
     * @throws InvalidArgumentException when the value is negative or not a plain decimal.
     */
    public static function fromFraction(BigDecimal|string $fraction): self
    {
        $value = Decimals::parse($fraction);

        if ($value->isNegative()) {
            throw new InvalidArgumentException("A percentage cannot be negative, got fraction {$value}.");
        }

        return new self($value->strippedOfTrailingZeros());
    }

    public static function zero(): self
    {
        return new self(BigDecimal::zero());
    }

    public static function full(): self
    {
        return new self(BigDecimal::one());
    }

    public function fraction(): BigDecimal
    {
        return $this->fraction;
    }

    /**
     * The percent number without trailing zeros ("0.75", "100", "90.5146").
     */
    public function percent(): string
    {
        return $this->fraction->withPointMovedRight(2)->strippedOfTrailingZeros()->toString();
    }

    public function compareTo(Percentage $other): int
    {
        return $this->fraction->compareTo($other->fraction);
    }

    public function equals(Percentage $other): bool
    {
        return $this->fraction->isEqualTo($other->fraction);
    }

    public function isZero(): bool
    {
        return $this->fraction->isZero();
    }
}
