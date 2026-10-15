<?php

namespace App\Modules\Core\Contracts;

use InvalidArgumentException;

/**
 * The immutable audit log of the whole platform (Part B, module 0N, item 7).
 *
 * Implemented by Core (phase 0b); tests use Tests\Fakes\FakeAuditLogger.
 *
 * Append-only: entries are never updated or deleted. The actor is the
 * authenticated user, or the system when no one is authenticated; the
 * implementation adds the actor, IP address, user agent and time itself.
 */
interface AuditLogger
{
    /**
     * Records one auditable action.
     *
     * @param  string  $action  "<module-key>.<entity>.<verb>" in lowercase snake_case segments,
     *                          e.g. "cobro.exclusion.created" or "core.user.deactivated".
     * @param  string|null  $subjectType  Kind of the affected record, e.g. "client" or "cobro_exclusion".
     * @param  int|string|null  $subjectId  Id of the affected record.
     * @param  array<string, mixed>  $changes  Changed fields as field => ['old' => …, 'new' => …];
     *                                         for created or deleted records, field => value.
     *                                         Never include secrets or tokens.
     * @param  array<string, mixed>  $context  Extra information, e.g. the batch or the reason.
     *
     * @throws InvalidArgumentException When $action does not follow the naming pattern.
     */
    public function record(
        string $action,
        ?string $subjectType = null,
        int|string|null $subjectId = null,
        array $changes = [],
        array $context = [],
    ): void;
}
