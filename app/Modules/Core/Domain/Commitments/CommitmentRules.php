<?php

namespace App\Modules\Core\Domain\Commitments;

use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Domain\Status\StatusDecision;
use Carbon\CarbonImmutable;

/**
 * Payment commitment rules: when a commitment breaks and how each step of
 * its life changes the client's status.
 */
final class CommitmentRules
{
    /**
     * Whether the daily task must mark the commitment Broken: it is still
     * Pending and its due date is before today. A commitment due today only
     * breaks tomorrow. Only calendar dates count, never the time of day.
     */
    public static function isBroken(CommitmentStatus $status, CarbonImmutable $dueDate, CarbonImmutable $today): bool
    {
        return $status === CommitmentStatus::Pending
            && $dueDate->toDateString() < $today->toDateString();
    }

    /**
     * A new commitment moves the client to Committed, except in Legal.
     */
    public static function afterCreate(ClientStatus $current): StatusDecision
    {
        if ($current === ClientStatus::Legal || $current === ClientStatus::Committed) {
            return StatusDecision::keep();
        }

        return StatusDecision::set(ClientStatus::Committed);
    }

    /**
     * A broken commitment moves the client to Defaulted, unless another
     * commitment is still pending or the client is in Legal, PaymentReceived
     * or already Defaulted.
     */
    public static function afterBreak(ClientStatus $current, bool $hasOtherPending): StatusDecision
    {
        $keeps = [ClientStatus::Legal, ClientStatus::PaymentReceived, ClientStatus::Defaulted];

        if ($hasOtherPending || in_array($current, $keeps, true)) {
            return StatusDecision::keep();
        }

        return StatusDecision::set(ClientStatus::Defaulted);
    }

    /**
     * A fulfilled or cancelled commitment hands the client back to the
     * automatic statuses when the commitment workflow set the current one
     * (Committed or Defaulted) and no other commitment is pending. The caller
     * recomputes from the latest snapshot, ignoring the manual status.
     */
    public static function afterClose(ClientStatus $current, bool $hasOtherPending): StatusDecision
    {
        $workflow = [ClientStatus::Committed, ClientStatus::Defaulted];

        if ($hasOtherPending || ! in_array($current, $workflow, true)) {
            return StatusDecision::keep();
        }

        return StatusDecision::recompute();
    }
}
