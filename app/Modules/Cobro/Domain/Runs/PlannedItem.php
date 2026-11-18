<?php

namespace App\Modules\Cobro\Domain\Runs;

/**
 * A candidate with the status it starts with in a run: Pending (will be
 * mailed), NoEmail or Excluded (with the exclusion reason).
 */
final readonly class PlannedItem
{
    public function __construct(
        public Candidate $candidate,
        public ItemStatus $status,
        public ?string $exclusionReason = null,
    ) {}

    /**
     * The starting status of a candidate: excluded first (by the exclusion
     * list, whose reason wins, or by the run itself), then without e-mail,
     * else pending.
     */
    public static function for(Candidate $candidate, ?string $exclusionReason): self
    {
        $exclusionReason ??= $candidate->exclusionReason;

        if ($exclusionReason !== null) {
            return new self($candidate, ItemStatus::Excluded, $exclusionReason);
        }

        return new self($candidate, $candidate->emails === [] ? ItemStatus::NoEmail : ItemStatus::Pending);
    }
}
