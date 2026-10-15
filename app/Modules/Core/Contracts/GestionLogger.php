<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Contracts\Data\Activity;
use App\Modules\Core\Contracts\Data\NewActivity;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use InvalidArgumentException;

/**
 * The clients' activity log (gestiones / bitácora): calls, notes,
 * commitments, discounts, e-mails and status changes.
 *
 * Implemented by Core (phase 0b); tests use Tests\Fakes\FakeGestionLogger.
 *
 * Entries follow the scope rule of CarteraRepository. Users soft-delete
 * entries through Core's own screens; this contract only appends and reads.
 */
interface GestionLogger
{
    /**
     * Appends an entry authored by the authenticated user, or by the system
     * (Activity::$user = null) when no one is authenticated, e.g. in jobs.
     *
     * @throws RecordNotFound When the client does not exist or is out of scope.
     */
    public function log(NewActivity $activity): Activity;

    /**
     * Latest entries of a client, newest first; empty when the client does
     * not exist or is out of scope.
     *
     * @param  int  $limit  At least 1.
     * @return list<Activity>
     *
     * @throws InvalidArgumentException When $limit is below 1.
     */
    public function forClient(int $clientId, int $limit = 50): array;

    /**
     * Latest entries across every client in scope, newest first.
     *
     * @param  int  $limit  At least 1.
     * @return list<Activity>
     *
     * @throws InvalidArgumentException When $limit is below 1.
     */
    public function recent(int $limit = 20): array;
}
