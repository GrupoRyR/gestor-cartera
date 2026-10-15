<?php

namespace App\Modules\Core\Application\Auth;

use RuntimeException;

/**
 * SignInWithMicrosoft turned the account away. The message is for logs; the
 * user sees __($exception->messageKey()).
 */
final class SignInRejected extends RuntimeException
{
    public function __construct(public readonly RejectionReason $reason)
    {
        parent::__construct("Microsoft sign-in rejected: {$reason->value}.");
    }

    public function messageKey(): string
    {
        return $this->reason->messageKey();
    }
}
