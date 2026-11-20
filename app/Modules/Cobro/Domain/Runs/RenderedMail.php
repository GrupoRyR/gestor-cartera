<?php

namespace App\Modules\Cobro\Domain\Runs;

use App\Modules\Core\Contracts\Data\MailAttachment;

/**
 * A collection mail ready to send or preview.
 */
final readonly class RenderedMail
{
    /**
     * @param  list<string>  $cc  Carbon copies; the sender drops any address already in To.
     * @param  list<MailAttachment>  $attachments  The signature images, inline (the HTML shows them with cid:).
     */
    public function __construct(
        public string $subject,
        public string $html,
        public array $cc = [],
        public array $attachments = [],
    ) {}

    /**
     * The same mail with other carbon copies.
     *
     * @param  list<string>  $cc
     */
    public function withCc(array $cc): self
    {
        return new self($this->subject, $this->html, $cc, $this->attachments);
    }

    /**
     * The HTML for a browser preview: a browser cannot read cid:, so each
     * inline image goes as a data: URI (the app's Content-Security-Policy
     * allows them). Never send this HTML: mail clients block data: images.
     */
    public function previewHtml(): string
    {
        $html = $this->html;

        foreach ($this->attachments as $attachment) {
            if ($attachment->isInline()) {
                $html = str_replace(
                    'src="cid:'.$attachment->contentId.'"',
                    'src="data:'.$attachment->contentType.';base64,'.base64_encode($attachment->content).'"',
                    $html,
                );
            }
        }

        return $html;
    }
}
