<?php

namespace App\Modules\Core\Domain\Status;

use App\Modules\Core\Contracts\Enums\ClientStatus;

/**
 * What a workflow rule asks the caller to do with a client's status.
 *
 * - keep(): leave the status as it is.
 * - set($status): change it to $status. Rules return set() only when the
 *   status actually changes, so the caller can log every set() as a change.
 * - recompute(): replace it with the automatic status computed from the
 *   latest snapshot (ClientStatusRules::resolve() from an automatic status).
 */
final readonly class StatusDecision
{
    private const KEEP = 'keep';

    private const SET = 'set';

    private const RECOMPUTE = 'recompute';

    private function __construct(
        private string $kind,
        private ?ClientStatus $status = null,
    ) {}

    public static function keep(): self
    {
        return new self(self::KEEP);
    }

    public static function set(ClientStatus $status): self
    {
        return new self(self::SET, $status);
    }

    public static function recompute(): self
    {
        return new self(self::RECOMPUTE);
    }

    public function isKeep(): bool
    {
        return $this->kind === self::KEEP;
    }

    public function isSet(): bool
    {
        return $this->kind === self::SET;
    }

    public function isRecompute(): bool
    {
        return $this->kind === self::RECOMPUTE;
    }

    /**
     * The status to set, or null unless this is a set() decision.
     */
    public function status(): ?ClientStatus
    {
        return $this->status;
    }
}
