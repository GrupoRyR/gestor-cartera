<?php

namespace App\Modules\Core\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A row of "audit_logs": one entry of the append-only audit trail, written
 * by Infrastructure\Audit\DatabaseAuditLogger.
 *
 * Immutable: saving changes to an existing entry, or deleting one, throws a
 * LogicException, also through saveQuietly(), deleteQuietly() and
 * withoutEvents(). Query-builder writes skip the model entirely: mass
 * update() and delete() on AuditLogRecord::query() or DB::table('audit_logs'),
 * and increment() or decrement(), must never be used on this table. They
 * are not blocked, but the hash chain detects them: rows are created only
 * through Infrastructure\Audit\AuditChain, which sets chain_hash, and
 * core:verificar-auditoria fails on any row changed, deleted or added
 * outside it (audit S-14).
 *
 * @property int $id
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<array-key, mixed>|null $changes
 * @property array<array-key, mixed>|null $context
 * @property int|null $user_id
 * @property string|null $actor
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property CarbonImmutable|null $created_at
 * @property string|null $chain_hash
 */
#[Table(name: 'audit_logs')]
#[Fillable(['action', 'subject_type', 'subject_id', 'changes', 'context', 'user_id', 'actor', 'ip_address', 'user_agent', 'created_at', 'chain_hash'])]
final class AuditLogRecord extends Model
{
    /**
     * Entries are never updated: there is only created_at.
     */
    const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'context' => 'array',
            'user_id' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * Every model update (save(), update(), saveQuietly()) ends here.
     *
     * @param  Builder<self>  $query
     */
    protected function performUpdate(Builder $query): never
    {
        throw new LogicException("Audit log entry [{$this->getKey()}] is immutable: it cannot be updated.");
    }

    /**
     * Every model delete (delete(), deleteQuietly(), forceDelete(), destroy()) ends here.
     */
    protected function performDeleteOnModel(): never
    {
        throw new LogicException("Audit log entry [{$this->getKey()}] is immutable: it cannot be deleted.");
    }
}
