<?php

namespace App\Modules\Cartera\Domain\Calls;

use App\Modules\Core\Contracts\Enums\Concerns\HasOptions;

/**
 * How the collector reached the client: a phone call, a WhatsApp call or a
 * WhatsApp message.
 */
enum CallChannel: string
{
    use HasOptions;

    case Call = 'llamada';

    case WhatsappCall = 'llamada_whatsapp';

    case WhatsappMessage = 'mensaje_whatsapp';

    public function label(): string
    {
        return __('cartera::llamadas.canal.'.$this->value);
    }
}
