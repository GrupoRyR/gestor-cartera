<?php

namespace App\Modules\Asesores\Application\Commitments;

use RuntimeException;

/**
 * Core would not write the commitment. Carries what the person should see:
 * the field to blame, when one is to blame, and a message in Spanish. Core's
 * own messages are for logs, so they never reach the screen.
 */
final class CommitmentRefused extends RuntimeException
{
    private function __construct(string $message, public readonly ?string $field)
    {
        parent::__construct($message);
    }

    /**
     * The commitment is already fulfilled or cancelled, or changed state
     * since the page was drawn.
     */
    public static function closed(): self
    {
        return new self(__('asesores::compromisos.errores.cerrado'), null);
    }

    /**
     * Core rejected a value of the form.
     */
    public static function invalid(string $field): self
    {
        return new self(__('asesores::compromisos.errores.'.$field), $field);
    }
}
