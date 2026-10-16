<?php

namespace App\Modules\Asesores\Domain\Portfolio;

/**
 * One bar of the age chart.
 */
final readonly class AgingRow
{
    /**
     * @param  string  $key  not_due, days_1_30, days_31_60, days_61_90 or over_90.
     * @param  float  $value  Signed balance of the bucket, in pesos.
     * @param  float  $share  Percent of the portfolio total, one decimal; 0 without a total.
     * @param  float  $width  Bar length, 0 to 100, against the biggest bucket.
     * @param  bool  $negative  The bucket nets credit notes or advances: drawn apart from the debt.
     */
    public function __construct(
        public string $key,
        public float $value,
        public float $share,
        public float $width,
        public bool $negative,
    ) {}
}
