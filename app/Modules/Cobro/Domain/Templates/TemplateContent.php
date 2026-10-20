<?php

namespace App\Modules\Cobro\Domain\Templates;

/**
 * The editable texts of a company mail template: subject, opening and
 * closing paragraphs, with {placeholders} (Placeholders). The invoice table
 * and the brand frame are fixed.
 */
final readonly class TemplateContent
{
    /**
     * @param  bool  $isDefault  The company never saved its own texts.
     */
    public function __construct(
        public string $subject,
        public string $intro,
        public string $closing,
        public bool $isDefault = false,
    ) {}
}
