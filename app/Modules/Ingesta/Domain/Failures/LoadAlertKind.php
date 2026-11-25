<?php

namespace App\Modules\Ingesta\Domain\Failures;

/**
 * What a load alert in ingesta_load_alerts warned about. Each kind is sent
 * at most once per company, dataset and slot.
 */
enum LoadAlertKind: string
{
    /** A final failure report arrived: the load will not be retried. */
    case Falla = 'falla';

    /** The bell notice of that final failure (LoadNotices), claimed apart from its mail. */
    case Campana = 'campana';

    /** No load and no final report arrived for the slot. */
    case NoLlego = 'no_llego';
}
