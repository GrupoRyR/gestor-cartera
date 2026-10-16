<?php

namespace App\Modules\Asesores\Application\Whatsapp;

/**
 * The WhatsApp button of a client file.
 */
final readonly class WhatsappShare
{
    /**
     * @param  string  $url  The wa.me link with the statement as the message.
     * @param  bool  $direct  The link opens the chat of a mobile of the client; if not, WhatsApp asks for the contact.
     */
    public function __construct(
        public string $url,
        public bool $direct,
    ) {}
}
