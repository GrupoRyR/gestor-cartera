<?php

namespace App\Modules\Cartera\Domain\Commitments;

use App\Modules\Core\Contracts\Enums\CompanyCode;

/**
 * Who writes the payment commitments of a company (Part B, module 2, item 7):
 * in SDC, where a single advisor sells, the portfolio analyst; in SGX, the
 * advisor of the client, from the Asesores module, so the collection team
 * only reads them.
 *
 * This mirrors the rule Core's CompromisoService enforces. The service stays
 * the authority; the screens use this to show only what it would accept.
 */
final class CommitmentWriting
{
    /**
     * Whether the collection team (the analyst) writes the commitments of the
     * company.
     */
    public static function byCollector(CompanyCode $company): bool
    {
        return $company === CompanyCode::SDC;
    }
}
