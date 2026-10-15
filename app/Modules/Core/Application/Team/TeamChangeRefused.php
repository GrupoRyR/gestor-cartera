<?php

namespace App\Modules\Core\Application\Team;

use RuntimeException;

/**
 * TeamMembers refused a change and wrote nothing. The message is for logs;
 * the admin sees __($exception->messageKey()).
 */
final class TeamChangeRefused extends RuntimeException
{
    public function __construct(public readonly TeamRefusal $reason)
    {
        parent::__construct("Team change refused: {$reason->value}.");
    }

    public function messageKey(): string
    {
        return $this->reason->messageKey();
    }
}
