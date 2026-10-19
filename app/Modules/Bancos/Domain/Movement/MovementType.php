<?php

namespace App\Modules\Bancos\Domain\Movement;

/**
 * Direction of a bank movement. Credits are money in (Cartera's); debits are
 * money out (Treasury's) and are never stored.
 */
enum MovementType: string
{
    case Credit = 'credito';

    case Debit = 'debito';
}
