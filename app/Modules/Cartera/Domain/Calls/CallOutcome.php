<?php

namespace App\Modules\Cartera\Domain\Calls;

use App\Modules\Core\Contracts\Enums\Concerns\HasOptions;

/**
 * How a call ended. Only a call the client answered carries an agreement;
 * CallBack needs the day to call again.
 */
enum CallOutcome: string
{
    use HasOptions;

    case Answered = 'contesto';

    case NoAnswer = 'no_contesto';

    case Voicemail = 'buzon_voz';

    case WrongNumber = 'numero_equivocado';

    case CallBack = 'volver_a_llamar';

    public function label(): string
    {
        return __('cartera::llamadas.resultado.'.$this->value);
    }
}
