<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Contracts\Data\Commitment;
use App\Modules\Core\Contracts\Data\CommitmentChanges;
use App\Modules\Core\Contracts\Data\CommitmentQuery;
use App\Modules\Core\Contracts\Data\NewCommitment;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use InvalidArgumentException;

/**
 * Payment commitments (compromisos de pago) of clients.
 *
 * Implemented by Core (phase 0b); tests use Tests\Fakes\FakeCompromisoService.
 *
 * The service acts as the authenticated user and follows the scope rule of
 * CarteraRepository: commitments of clients outside the scope behave as if
 * they did not exist. Writing is authorized per company: in SGX the advisor
 * of the client creates and edits commitments; in SDC the portfolio analyst
 * does. The portfolio manager and analyst read every commitment.
 *
 * Lifecycle: create() makes a Pending commitment; Core's daily task turns a
 * Pending commitment whose due date has passed without payment into Broken.
 * Fulfilled and Cancelled are final. Every write is also recorded in the
 * client's activity log (ActivityType::Commitment) and in the audit log.
 * Amounts are Colombian pesos and must be greater than zero.
 */
interface CompromisoService
{
    /**
     * Registers a Pending commitment for the client.
     *
     * @throws RecordNotFound When the client does not exist or is out of scope.
     * @throws AuthorizationException When the user may not create commitments in the client's company.
     * @throws InvalidArgumentException When the amount is not greater than zero.
     */
    public function create(NewCommitment $commitment): Commitment;

    /**
     * Changes the date, amount or notes of a Pending commitment.
     *
     * @throws RecordNotFound When the commitment does not exist or is out of scope.
     * @throws AuthorizationException When the user may not edit commitments in its company.
     * @throws InvalidArgumentException When the new amount is not greater than zero.
     * @throws DomainException When the commitment is not Pending.
     */
    public function update(int $commitmentId, CommitmentChanges $changes): Commitment;

    /**
     * Marks a Pending or Broken commitment as Fulfilled (a late payment still
     * fulfils it) and sets Commitment::$fulfilledAt to now.
     *
     * @param  string|null  $note  Optional remark for the activity log.
     *
     * @throws RecordNotFound When the commitment does not exist or is out of scope.
     * @throws AuthorizationException When the user may not edit commitments in its company.
     * @throws DomainException When the commitment is already Fulfilled or Cancelled.
     */
    public function markFulfilled(int $commitmentId, ?string $note = null): Commitment;

    /**
     * Voids a Pending or Broken commitment, e.g. when it was renegotiated (a
     * new commitment replaces it) or registered by mistake.
     *
     * @param  string  $reason  Why it was voided; required, written to the activity and audit logs.
     *
     * @throws RecordNotFound When the commitment does not exist or is out of scope.
     * @throws AuthorizationException When the user may not edit commitments in its company.
     * @throws InvalidArgumentException When the reason is blank.
     * @throws DomainException When the commitment is already Fulfilled or Cancelled.
     */
    public function cancel(int $commitmentId, string $reason): Commitment;

    /**
     * The commitment; null when it does not exist or is out of scope.
     */
    public function find(int $commitmentId): ?Commitment;

    /**
     * One page of commitments; the page number comes from the request (?page=).
     *
     * @param  int  $perPage  At least 1.
     * @return LengthAwarePaginator<int, Commitment>
     *
     * @throws InvalidArgumentException When $perPage is below 1.
     */
    public function paginate(CommitmentQuery $query, int $perPage = 25): LengthAwarePaginator;
}
