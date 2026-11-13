<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Data\UserRef;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/**
 * Who in the portfolio team looks after each client: the client's portfolio
 * manager (ClientSummary::$manager, the "gestor"). The Cartera screens assign
 * it one client at a time or in bulk, and filter "Mi cartera" and "Sin
 * asignar" with ClientQuery::$managerUserId and ClientQuery::$unassigned.
 *
 * Implemented by Core. Tests\Fakes has no fake for it: module tests bind
 * their own double or use Core's implementation.
 *
 * The service acts as the authenticated user and follows the scope rule of
 * CarteraRepository over every company available to the user, not only the
 * active one: a client outside it behaves as if it did not exist. Only users
 * with the core.clients.assign permission (portfolio manager, analyst and
 * admin) may assign.
 *
 * A manager is a user with the gerente_cartera or analista_cartera role who
 * has access to the client's company; null leaves the client unassigned.
 * Assigning is not exclusive: everyone in the team still sees every client.
 */
interface ClientAssignmentService
{
    /**
     * The users a client may be assigned to: portfolio managers and
     * analysts with access to at least one company available to the
     * authenticated user, by name.
     *
     * @return list<UserRef>
     *
     * @throws AuthorizationException When the user may not assign clients (guests included).
     */
    public function assignees(): array;

    /**
     * Sets the manager of each client of $clientIds, all of them or none.
     * For each client whose manager changes, writes an activity entry in its
     * log and audits core.client.manager_changed; a client that already has
     * that manager writes nothing.
     *
     * @param  list<int>  $clientIds
     * @param  int|null  $managerUserId  The new manager; null = unassigned.
     * @return int How many clients changed manager.
     *
     * @throws AuthorizationException When the user may not assign clients (guests included).
     * @throws RecordNotFound When a client does not exist, is soft-deleted or is out of scope.
     * @throws InvalidArgumentException When $managerUserId is not an assignee with access to
     *                                  the company of every client.
     */
    public function assign(array $clientIds, ?int $managerUserId): int;

    /**
     * Same as assign() for every client that matches $query ("todos los
     * filtrados"); its order is ignored.
     *
     * @return int How many clients changed manager.
     *
     * @throws AuthorizationException When the user may not assign clients (guests included).
     * @throws InvalidArgumentException As in assign().
     */
    public function assignMatching(ClientQuery $query, ?int $managerUserId): int;
}
