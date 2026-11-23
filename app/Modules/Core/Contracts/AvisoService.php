<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Contracts\Data\Aviso;
use App\Modules\Core\Contracts\Data\Notice;
use Closure;

/**
 * In-app notices (avisos) for platform users: the bell in the layout.
 *
 * Implemented by Core; tests use Tests\Fakes\FakeAvisoService.
 *
 * A module sends an Aviso; Core stores one Notice per recipient. Each notice
 * has two independent states:
 *
 * - Unread -> read: the recipient opened it or marked it as read in the
 *   bell. Only Core's inbox changes it.
 * - Open -> resolved ("done"): what the notice asked for no longer needs
 *   doing, e.g. the commitment was settled. The sending module calls
 *   resolve() with the key it gave the Aviso; it resolves the notice for
 *   every recipient, whether they read it or not.
 *
 * Reading the inbox (list, unread count, mark as read) is Core-internal and
 * not part of this contract. Modules only send, resolve and say where a
 * notice of their kind leads (linkUsing()).
 */
interface AvisoService
{
    /**
     * Stores one unread, open notice per distinct recipient.
     *
     * It writes in the caller's current database transaction, if any, and
     * does not wait for it to commit: a rolled-back write leaves no notice.
     * Every recipient must be the id of an existing user.
     */
    public function send(Aviso $aviso): void;

    /**
     * Resolves every open notice with this key, for every recipient, and
     * returns how many it resolved (0 when none was open). Notices already
     * resolved keep their first resolution time; the read state is untouched.
     */
    public function resolve(string $key): int;

    /**
     * Registers where a notice of this kind leads. Call it from the module's
     * bootModule(). The resolver receives the Notice and returns the URL it
     * opens for the signed-in user, or null to pass. A kind may have several
     * resolvers: they run in registration order and the first non-null URL
     * wins; with no answer the notice has no link.
     *
     * @param  Closure(Notice): ?string  $resolver
     */
    public function linkUsing(string $kind, Closure $resolver): void;
}
