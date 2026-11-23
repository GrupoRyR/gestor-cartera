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
 * does. The portfolio manager and analyst read every commitment and, in
 * both companies, change its status (markFulfilled(), markPartiallyPaid(),
 * markBroken()).
 *
 * Lifecycle: create() makes a Pending commitment; Core's daily task, or a
 * user through markBroken(), turns a Pending commitment into Broken.
 * Fulfilled, Cancelled and PartiallyPaid (abonado, markPartiallyPaid(): part
 * of the amount paid, paidAmount stored and the remaining balance derived)
 * are final.
 * Every write is also recorded in the client's activity log
 * (ActivityType::Commitment) and in the audit log.
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
     * @throws AuthorizationException When the user may not change the status of commitments in its company.
     * @throws DomainException When the commitment is already Fulfilled, Cancelled or PartiallyPaid.
     */
    public function markFulfilled(int $commitmentId, ?string $note = null): Commitment;

    /**
     * Marks a Pending or Broken commitment as PartiallyPaid: the client paid
     * $paidAmount and the rest stays owed. Sets Commitment::$paidAmount and
     * Commitment::$partiallyPaidAt (now). When the client paid everything,
     * use markFulfilled() instead.
     *
     * Core also sends a notice (kind commitment.remaining) asking whoever
     * writes the client's commitments to create a new one for the balance;
     * creating any commitment for the client resolves it.
     *
     * @param  float  $paidAmount  Pesos, rounded to cents; above zero and below the commitment amount.
     * @param  string|null  $note  Optional remark for the activity and audit logs.
     *
     * @throws RecordNotFound When the commitment does not exist or is out of scope.
     * @throws AuthorizationException When the user may not change the status of commitments in its company.
     * @throws InvalidArgumentException When the paid amount is not above zero and below the commitment amount.
     * @throws DomainException When the commitment is already Fulfilled, Cancelled or PartiallyPaid.
     */
    public function markPartiallyPaid(int $commitmentId, float $paidAmount, ?string $note = null): Commitment;

    /**
     * Marks a Pending commitment as Broken before the daily task does, e.g.
     * when the client says it will not pay; the client moves as when the
     * daily task breaks it. A Broken commitment may still be fulfilled.
     *
     * @param  string|null  $note  Optional remark for the activity and audit logs.
     *
     * @throws RecordNotFound When the commitment does not exist or is out of scope.
     * @throws AuthorizationException When the user may not change the status of commitments in its company.
     * @throws DomainException When the commitment is not Pending.
     */
    public function markBroken(int $commitmentId, ?string $note = null): Commitment;

    /**
     * Voids a Pending or Broken commitment, e.g. when it was renegotiated (a
     * new commitment replaces it) or registered by mistake.
     *
     * @param  string  $reason  Why it was voided; required, written to the activity and audit logs.
     *
     * @throws RecordNotFound When the commitment does not exist or is out of scope.
     * @throws AuthorizationException When the user may not edit commitments in its company.
     * @throws InvalidArgumentException When the reason is blank.
     * @throws DomainException When the commitment is already Fulfilled, Cancelled or PartiallyPaid.
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
