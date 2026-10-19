<?php

namespace App\Modules\Cobro\Domain\Exclusions;

use DateTimeInterface;

/**
 * When an exclusion from the collection mail applies: from the moment it is
 * made until the end of its expiry date (inclusive), or forever without
 * one. Dates compare as calendar days ("Y-m-d").
 */
final class ExclusionPeriod
{
    public static function isActive(?DateTimeInterface $expiresOn, DateTimeInterface $today): bool
    {
        return $expiresOn === null || $expiresOn->format('Y-m-d') >= $today->format('Y-m-d');
    }
}
