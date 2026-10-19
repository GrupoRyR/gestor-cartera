<?php

namespace App\Modules\Bancos\Domain\Statement;

/**
 * What a column of a statement means. A bank profile lists, for each role, the
 * headers that bank uses for it.
 */
enum Column: string
{
    case Date = 'date';

    case Description = 'description';

    case Reference = 'reference';

    case Account = 'account';

    case Currency = 'currency';

    /** Money in, in a column of its own (banks that print credits and debits apart). */
    case Credit = 'credit';

    /** Money out, in a column of its own. */
    case Debit = 'debit';

    /** One amount column: signed, or unsigned with a Type column. */
    case Amount = 'amount';

    /** Says whether the Amount column is a credit or a debit. */
    case Type = 'type';
}
