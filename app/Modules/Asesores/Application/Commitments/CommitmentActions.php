<?php

namespace App\Modules\Asesores\Application\Commitments;

use App\Modules\Core\Contracts\CompromisoService;
use App\Modules\Core\Contracts\Data\Commitment;
use App\Modules\Core\Contracts\Data\CommitmentChanges;
use App\Modules\Core\Contracts\Data\NewCommitment;
use Carbon\CarbonImmutable;
use Closure;
use DomainException;
use InvalidArgumentException;

/**
 * Writes commitments through Core's CompromisoService, which is the
 * authority: it hides what is out of the user's scope (RecordNotFound),
 * checks who writes in each company (AuthorizationException) and keeps the
 * lifecycle. Those two exceptions go up as they are, to become a 404 and a 403.
 * What the person can fix or must know becomes a CommitmentRefused.
 */
final readonly class CommitmentActions
{
    public function __construct(private CompromisoService $commitments) {}

    /**
     * @throws CommitmentRefused
     */
    public function create(int $clientId, CarbonImmutable $dueDate, float $amount, ?string $notes): Commitment
    {
        return $this->run(
            fn (): Commitment => $this->commitments->create(new NewCommitment($clientId, $dueDate, $amount, $notes)),
            'valor',
        );
    }

    /**
     * @throws CommitmentRefused
     */
    public function update(int $commitmentId, CarbonImmutable $dueDate, float $amount, ?string $notes): Commitment
    {
        return $this->run(
            fn (): Commitment => $this->commitments->update($commitmentId, new CommitmentChanges($dueDate, $amount, $notes)),
            'valor',
        );
    }

    /**
     * @throws CommitmentRefused
     */
    public function fulfill(int $commitmentId, ?string $note): Commitment
    {
        return $this->run(fn (): Commitment => $this->commitments->markFulfilled($commitmentId, $note), 'nota');
    }

    /**
     * @throws CommitmentRefused
     */
    public function cancel(int $commitmentId, string $reason): Commitment
    {
        return $this->run(fn (): Commitment => $this->commitments->cancel($commitmentId, $reason), 'motivo');
    }

    /**
     * @param  Closure(): Commitment  $action
     * @param  string  $field  The field to blame when Core rejects a value.
     *
     * @throws CommitmentRefused
     */
    private function run(Closure $action, string $field): Commitment
    {
        try {
            return $action();
        } catch (DomainException) {
            throw CommitmentRefused::closed();
        } catch (InvalidArgumentException) {
            throw CommitmentRefused::invalid($field);
        }
    }
}
