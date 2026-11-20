<?php

namespace App\Modules\Core\Contracts\Data;

use InvalidArgumentException;

/**
 * A file attached to an OutgoingMail.
 *
 * With a $contentId the file goes inline: the HTML body shows it with
 * <img src="cid:{contentId}"> (an e-mail signature's PNG, for example)
 * instead of listing it as an attachment.
 */
final readonly class MailAttachment
{
    /**
     * Characters a content id may use, so it fits a cid: URL in an HTML
     * attribute as is.
     */
    private const string CONTENT_ID = '/^[A-Za-z0-9._@-]+$/';

    /**
     * @param  string  $filename  Name the recipient sees, e.g. "estado-de-cuenta.pdf".
     * @param  string  $contentType  MIME type, e.g. "application/pdf".
     * @param  string  $content  Raw bytes (not base64); the sender encodes them.
     * @param  string|null  $contentId  Inline: the id the body refers to as cid:{contentId}; null = a regular attachment.
     *
     * @throws InvalidArgumentException When $contentId is blank or has characters other than letters, digits, ".", "_", "@" and "-".
     */
    public function __construct(
        public string $filename,
        public string $contentType,
        public string $content,
        public ?string $contentId = null,
    ) {
        if ($contentId !== null && preg_match(self::CONTENT_ID, $contentId) !== 1) {
            throw new InvalidArgumentException("The content id [{$contentId}] of an inline attachment may only use letters, digits, '.', '_', '@' and '-'.");
        }
    }

    /**
     * Whether the body shows it (cid:) rather than listing it.
     */
    public function isInline(): bool
    {
        return $this->contentId !== null;
    }
}
