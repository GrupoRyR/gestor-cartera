<?php

namespace App\Modules\Core\Contracts\Data;

/**
 * A file attached to an OutgoingMail.
 */
final readonly class MailAttachment
{
    /**
     * @param  string  $filename  Name the recipient sees, e.g. "estado-de-cuenta.pdf".
     * @param  string  $contentType  MIME type, e.g. "application/pdf".
     * @param  string  $content  Raw bytes (not base64); the sender encodes them.
     */
    public function __construct(
        public string $filename,
        public string $contentType,
        public string $content,
    ) {}
}
