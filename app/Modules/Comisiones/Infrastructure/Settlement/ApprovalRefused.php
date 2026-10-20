<?php

namespace App\Modules\Comisiones\Infrastructure\Settlement;

use RuntimeException;

/**
 * The approver loaded data or changed parameters of the period (§10). The
 * message and the reasons are the Spanish texts the screen shows.
 */
final class ApprovalRefused extends RuntimeException
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(string $message, private readonly array $reasons)
    {
        parent::__construct($message);
    }

    /**
     * What the approver did in the period, one sentence each.
     *
     * @return list<string>
     */
    public function reasons(): array
    {
        return $this->reasons;
    }
}
