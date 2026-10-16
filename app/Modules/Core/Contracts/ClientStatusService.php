<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Contracts\Data\ClientSummary;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Manual status changes of a client: dragging its card to another Kanban
 * column, or the status change on the client page (Part B, module 2, items
 * 3 and 5).
 *
 * Implemented by Core, added after phase 0a. Tests\Fakes has no fake for it
 * yet: module tests bind their own double or use Core's implementation.
 *
 * The service acts as the authenticated user and follows the scope rule of
 * CarteraRepository (docs/arquitectura.md §6.2) over every company available
 * to the user, not only the active one: a client outside it behaves as if it
 * did not exist. Only users with the core.clients.change_status permission
 * (portfolio manager and analyst) may change a status.
 *
 * Any ClientStatus may be chosen, and Core's rules still apply to it on the
 * next snapshot: an automatic status chosen by hand (Current, DueSoon,
 * Overdue, PaymentReceived) is recomputed then, while a manual one
 * (InProgress, Committed, Defaulted, Legal) survives new snapshots as long as
 * the client still owes money, and becomes PaymentReceived once it owes
 * nothing.
 */
interface ClientStatusService
{
    /**
     * Sets the client's status. When it differs from the current one, also
     * sets the time of the change, writes an ActivityType::StatusChange entry
     * in the client's activity log (with $note as its details) and audits
     * core.client.status_changed; the same status writes nothing.
     *
     * @param  string|null  $note  Optional reason for the activity log; blank means none.
     * @return ClientSummary The client after the change.
     *
     * @throws AuthorizationException When the user may not change client statuses (guests included).
     * @throws RecordNotFound When the client does not exist, is soft-deleted or is out of scope.
     */
    public function change(int $clientId, ClientStatus $status, ?string $note = null): ClientSummary;
}
