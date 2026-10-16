<?php

namespace App\Modules\Comisiones\Domain\Shared;

/**
 * Seller codes as SAP reports them (§4): the seller column holds the code
 * followed by the name, e.g. "14 MONICA RIVERA AREVALO".
 */
final class SellerCode
{
    /**
     * The leading integer of the SAP seller text: "14 MONICA RIVERA AREVALO" →
     * 14, " 085 LILIAM" → 85, "SIN ASESOR" → null. Numbers longer than nine
     * digits are not seller codes and also give null.
     */
    public static function fromSapText(string $text): ?int
    {
        if (preg_match('/^\s*(\d+)/', $text, $matches) !== 1) {
            return null;
        }

        $digits = ltrim($matches[1], '0');

        if (strlen($digits) > 9) {
            return null;
        }

        return (int) $digits;
    }
}
