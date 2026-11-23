<?php

namespace App\Modules\Soportes\Domain\Exceptions;

use App\Modules\Soportes\Domain\ReceiptStatus;
use DomainException;

/**
 * A receipt was asked to change status in a way the rules refuse, e.g. an
 * applied receipt being rejected. The message is for the logs; the screens
 * show their own text.
 */
final class InvalidReceiptTransition extends DomainException
{
    public static function between(ReceiptStatus $from, ReceiptStatus $to): self
    {
        return new self("A receipt cannot move {$from->value} -> {$to->value}.");
    }

    /**
     * Another reviewer decided on the receipt after it was read as pending.
     */
    public static function alreadyReviewed(int $receiptId, ReceiptStatus $to): self
    {
        return new self("Receipt {$receiptId} is no longer pending; it cannot move to {$to->value}.");
    }
}
