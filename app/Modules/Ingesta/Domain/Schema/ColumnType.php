<?php

namespace App\Modules\Ingesta\Domain\Schema;

/**
 * The type of a dataset column and the JSON value it accepts, mirroring the
 * type constants of etl/esquema.py.
 */
enum ColumnType: string
{
    /** TEXTO ("text"): a string of at most 4000 characters. */
    case Text = 'text';

    /** ENTERO ("integer"): a JSON integer. */
    case Integer = 'integer';

    /** NUMERO ("numeric(19,2)"): a decimal string with a dot and up to 2 decimals. */
    case Decimal2 = 'decimal2';

    /** TASA ("numeric(19,6)"): a decimal string with a dot and up to 6 decimals. */
    case Decimal6 = 'decimal6';

    /** FECHA ("date"): a "Y-m-d" string that is a real calendar date. */
    case Date = 'date';
}
