<?php

namespace App\Modules\Comisiones\Domain\Shared;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * An exact amount of Colombian pesos (§5.1).
 *
 * Arithmetic never rounds: line commissions stay exact and the calculation
 * rounds each concept total once, to the peso and half up (away from zero),
 * the way the current Excel workbook does. Derived bases (crossing base
 * without VAT, sales without VAT, converted currency) are rounded to cents.
 */
final readonly class Money
{
    private function __construct(private BigDecimal $amount) {}

    /**
     * @throws InvalidArgumentException when a string is not a plain decimal
     *                                  ("1234567.89"; no thousand separators or commas).
     */
    public static function of(BigDecimal|int|string $amount): self
    {
        return new self(Decimals::parse($amount));
    }

    public static function zero(): self
    {
        return new self(BigDecimal::zero());
    }

    /**
     * @param  iterable<Money>  $amounts
     */
    public static function sum(iterable $amounts): self
    {
        $total = BigDecimal::zero();

        foreach ($amounts as $amount) {
            $total = $total->plus($amount->amount);
        }

        return new self($total);
    }

    public function plus(Money $other): self
    {
        return new self($this->amount->plus($other->amount));
    }

    public function minus(Money $other): self
    {
        return new self($this->amount->minus($other->amount));
    }

    public function negated(): self
    {
        return new self($this->amount->negated());
    }

    /**
     * The exact share of this amount (0.75 % of 2,100,840 is 15,756.3).
     */
    public function times(Percentage $percentage): self
    {
        return new self($this->amount->multipliedBy($percentage->fraction()));
    }

    /**
     * The exact product by a plain factor, e.g. an exchange rate.
     *
     * @throws InvalidArgumentException when a string factor is not a plain decimal.
     */
    public function multipliedBy(BigDecimal|int|string $factor): self
    {
        return new self($this->amount->multipliedBy(Decimals::parse($factor)));
    }

    /**
     * Rounded to the peso, half up (away from zero): 524,363.505 → 524,364.
     */
    public function rounded(): self
    {
        return new self($this->amount->toScale(0, RoundingMode::HalfUp));
    }

    /**
     * Rounded to cents, half up (away from zero): 2,280,210.084 → 2,280,210.08.
     */
    public function toCents(): self
    {
        return new self($this->amount->toScale(2, RoundingMode::HalfUp));
    }

    public function compareTo(Money $other): int
    {
        return $this->amount->compareTo($other->amount);
    }

    public function equals(Money $other): bool
    {
        return $this->amount->isEqualTo($other->amount);
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function isPositive(): bool
    {
        return $this->amount->isPositive();
    }

    /**
     * The amount as a plain decimal without trailing zeros ("524364", "7878.15").
     */
    public function amount(): string
    {
        return $this->amount->strippedOfTrailingZeros()->toString();
    }

    public function decimal(): BigDecimal
    {
        return $this->amount;
    }
}
