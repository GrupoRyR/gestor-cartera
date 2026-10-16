<?php

namespace App\Modules\Asesores\Http\Policies;

use App\Models\User;
use App\Modules\Core\Contracts\Data\Commitment;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;

/**
 * Which buttons a commitment shows. The commitment was already found through
 * CompromisoService::find(), which hides what is out of the user's scope;
 * Core's write rule repeats these checks when the action runs.
 */
final readonly class CommitmentPolicy
{
    /**
     * Change the date, amount or notes: only while pending.
     */
    public function update(User $user, Commitment $commitment): bool
    {
        return $this->writes($user, $commitment) && $commitment->status === CommitmentStatus::Pending;
    }

    /**
     * Mark as fulfilled: a pending commitment, or a broken one (a late
     * payment still fulfils it).
     */
    public function fulfill(User $user, Commitment $commitment): bool
    {
        return $this->writes($user, $commitment) && $this->isOpen($commitment);
    }

    /**
     * Cancel with a reason: same states as fulfilling.
     */
    public function cancel(User $user, Commitment $commitment): bool
    {
        return $this->fulfill($user, $commitment);
    }

    private function writes(User $user, Commitment $commitment): bool
    {
        return $user->can('asesores.manage')
            && in_array($commitment->company->value, (array) config('asesores.commitment_companies'), true);
    }

    private function isOpen(Commitment $commitment): bool
    {
        return in_array($commitment->status, [CommitmentStatus::Pending, CommitmentStatus::Broken], true);
    }
}
