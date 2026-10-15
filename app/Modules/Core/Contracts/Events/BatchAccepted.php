<?php

namespace App\Modules\Core\Contracts\Events;

use App\Modules\Core\Contracts\Data\LoadRecord;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Ingesta accepted a batch and stored it as a dated snapshot.
 *
 * Dispatched once per accepted batch, never for a repeated batchId nor for a
 * rejected one. Listeners read the rows with IngestaSink::rows($event->load->id):
 * Core handles cartera, asesores and contactos; Comisiones handles ventas,
 * recaudos, cruces and the master datasets. Listeners filter by
 * $event->load->dataset and should be queued when the work is heavy.
 */
final class BatchAccepted
{
    use Dispatchable;

    public function __construct(
        public readonly LoadRecord $load,
    ) {}
}
