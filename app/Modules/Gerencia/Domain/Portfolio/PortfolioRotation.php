<?php

namespace App\Modules\Gerencia\Domain\Portfolio;

/**
 * How many times the portfolio turned over in a period: bank collections of
 * the period divided by the average portfolio of the period.
 */
final readonly class PortfolioRotation
{
    public function __construct(
        public float $collected,
        public float $averagePortfolio,
        public int $periodDays,
    ) {}

    public static function from(float $collected, float $averagePortfolio, int $periodDays): self
    {
        return new self($collected, $averagePortfolio, $periodDays);
    }

    /**
     * Turns of the portfolio in the period; 0 when there is no average portfolio.
     */
    public function times(): float
    {
        if ($this->averagePortfolio <= 0) {
            return 0.0;
        }

        return $this->collected / $this->averagePortfolio;
    }

    /**
     * Days one turn takes at this pace; null when the portfolio did not turn.
     */
    public function daysPerTurn(): ?float
    {
        $times = $this->times();

        return $times > 0 ? $this->periodDays / $times : null;
    }
}
