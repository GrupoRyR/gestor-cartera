<?php

namespace App\Modules\Comisiones\Domain\Settlement;

use App\Modules\Comisiones\Domain\Shared\Period;
use DomainException;

/**
 * Blocking alerts stop the approval until each one is justified (§9.7).
 */
final class UnresolvedAlerts extends DomainException
{
    /**
     * @param  list<string>  $keys  The keys of the blocking alerts without a justification, in alert order.
     */
    public function __construct(Period $period, int $version, private readonly array $keys)
    {
        parent::__construct(sprintf(
            'Version %d of the %s settlement has blocking alerts without a justification: %s.',
            $version,
            $period->key(),
            implode(', ', $keys),
        ));
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return $this->keys;
    }
}
