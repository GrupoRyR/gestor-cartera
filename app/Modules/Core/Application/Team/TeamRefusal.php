<?php

namespace App\Modules\Core\Application\Team;

/**
 * Why TeamMembers refused a change. The value names the Spanish message the
 * admin sees.
 */
enum TeamRefusal: string
{
    /** An admin may not deactivate their own account. */
    case SelfDeactivation = 'self_deactivation';

    /** An admin may not take away their own admin role. */
    case SelfDemotion = 'self_demotion';

    /** An admin may not delete their own account. */
    case SelfDeletion = 'self_deletion';

    /** The e-mail already belongs to a user who is not deleted. */
    case EmailTaken = 'email_taken';

    /** One of the advisors to link is already linked to someone else. */
    case AdvisorTaken = 'advisor_taken';

    /**
     * The key of the Spanish message for the admin.
     */
    public function messageKey(): string
    {
        return "core::equipo.rechazos.{$this->value}";
    }
}
