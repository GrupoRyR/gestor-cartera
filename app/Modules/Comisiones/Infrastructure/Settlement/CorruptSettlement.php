<?php

namespace App\Modules\Comisiones\Infrastructure\Settlement;

use RuntimeException;
use Throwable;

/**
 * A stored settlement result failed its fingerprint check or could not be
 * read back as a SettlementResult. It is never used: the stored row needs
 * a look from IT.
 */
final class CorruptSettlement extends RuntimeException
{
    public static function fingerprint(string $period, int $version): self
    {
        return new self("The stored result of version {$version} of the {$period} settlement does not match its fingerprint.");
    }

    public static function unreadable(string $period, int $version, ?Throwable $previous = null): self
    {
        return new self("The stored result of version {$version} of the {$period} settlement cannot be read back.", 0, $previous);
    }
}
