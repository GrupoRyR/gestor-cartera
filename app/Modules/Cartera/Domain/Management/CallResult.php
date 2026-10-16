<?php

namespace App\Modules\Cartera\Domain\Management;

/**
 * How a collection call ended. The six results are those of the Phase 1 app,
 * so the analyst keeps the vocabulary she already uses.
 */
enum CallResult: string
{
    case PromisesPayment = 'promete_pago';

    case DisputesInvoice = 'disputa_factura';

    case NoAgreement = 'sin_acuerdo';

    case NoAnswer = 'no_contesta';

    case WrongNumber = 'numero_errado';

    case Voicemail = 'buzon_voz';

    public function label(): string
    {
        return match ($this) {
            self::PromisesPayment => 'Contactado — promete pago',
            self::DisputesInvoice => 'Contactado — disputa factura',
            self::NoAgreement => 'Contactado — sin acuerdo',
            self::NoAnswer => 'No contesta',
            self::WrongNumber => 'Número errado',
            self::Voicemail => 'Buzón de voz',
        };
    }

    /**
     * Values and labels for <x-ui.select> and validation.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
