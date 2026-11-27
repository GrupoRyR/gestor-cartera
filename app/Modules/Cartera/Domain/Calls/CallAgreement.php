<?php

namespace App\Modules\Cartera\Domain\Calls;

use App\Modules\Core\Contracts\Enums\Concerns\HasOptions;

/**
 * What the client agreed on an answered call. The call stores no amount or
 * date: a payment commitment is written apart, through CompromisoService.
 */
enum CallAgreement: string
{
    use HasOptions;

    case None = 'ninguno';

    case PaymentCommitment = 'compromiso_pago';

    case SendReceipt = 'envio_soporte';

    public function label(): string
    {
        return __('cartera::llamadas.acuerdo.'.$this->value);
    }
}
