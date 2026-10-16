<?php

namespace App\Modules\Asesores\Domain\Client;

/**
 * The age bucket of one invoice, with the same edges as Core's AgingBuckets:
 * days overdue to the cutoff, grace days ignored, due today still "not due".
 */
final class InvoiceAging
{
    /**
     * The AgingRow key of the bucket: not_due, days_1_30, days_31_60,
     * days_61_90 or over_90.
     */
    public static function key(int $daysOverdue): string
    {
        return match (true) {
            $daysOverdue <= 0 => 'not_due',
            $daysOverdue <= 30 => 'days_1_30',
            $daysOverdue <= 60 => 'days_31_60',
            $daysOverdue <= 90 => 'days_61_90',
            default => 'over_90',
        };
    }
}
