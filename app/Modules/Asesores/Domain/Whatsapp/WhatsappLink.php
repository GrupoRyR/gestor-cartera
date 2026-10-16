<?php

namespace App\Modules\Asesores\Domain\Whatsapp;

/**
 * The wa.me link that opens WhatsApp with a message ready. Nothing is sent:
 * the advisor reads it, edits it and sends it.
 */
final class WhatsappLink
{
    /**
     * With a number the chat of that number opens; without one, WhatsApp
     * asks which contact to send the message to.
     */
    public static function url(?string $number, string $message): string
    {
        return 'https://wa.me/'.($number ?? '').'?text='.rawurlencode($message);
    }
}
