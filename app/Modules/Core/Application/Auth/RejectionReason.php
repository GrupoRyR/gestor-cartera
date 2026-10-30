<?php

namespace App\Modules\Core\Application\Auth;

/**
 * Why SignInWithMicrosoft turned a Microsoft account away. The value goes to
 * the audit trail (context "reason") and names the message the user sees.
 */
enum RejectionReason: string
{
    /** The "tid" claim is missing or is not the configured tenant (or none is configured). */
    case Tenant = 'tenant';

    /** A guest (B2B) account of the tenant: only members sign in (audit S-17). */
    case Guest = 'guest';

    /** The user was removed (soft-deleted); an admin restores them from Equipo. */
    case Deleted = 'deleted';

    /** The e-mail is registered with another Microsoft account, or the account with another e-mail. */
    case IdentityMismatch = 'identity_mismatch';

    /** The user was deactivated from Equipo. */
    case Inactive = 'inactive';

    /**
     * The key of the Spanish message for the user.
     */
    public function messageKey(): string
    {
        return "core::ingreso.rechazo.{$this->value}";
    }
}
