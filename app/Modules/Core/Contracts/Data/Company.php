<?php

namespace App\Modules\Core\Contracts\Data;

use App\Modules\Core\Contracts\Enums\CompanyCode;

/**
 * A company of the group, with the parameters other modules need.
 */
final readonly class Company
{
    /**
     * @param  string  $name  Display name, e.g. "Segurex Latam".
     * @param  int  $graceDays  Days after an invoice's due date before it counts as
     *                          overdue for the client's status (ClientStatus::Overdue).
     *                          Core keeps it as a parameter; defaults are 15 for SGX and
     *                          5 for SDC. Aging buckets ignore it (see AgingBuckets).
     * @param  BrandPalette  $palette  The company's brand colors.
     */
    public function __construct(
        public CompanyCode $code,
        public string $name,
        public int $graceDays,
        public BrandPalette $palette,
    ) {}
}
