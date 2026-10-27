<?php

namespace App\Modules\Comisiones\Infrastructure\Persistence;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A row of "comisiones_bitacora": one audited event of the module. Written
 * only by AuditTrail.
 *
 * Insert-only: saving changes to an existing event, or deleting one, throws a
 * LogicException, also through saveQuietly(), deleteQuietly(), forceDelete()
 * and withoutEvents(). Query-builder writes skip the model entirely: mass
 * update() and delete() on AuditRecord::query() or
 * DB::table('comisiones_bitacora') must never be used on this table; every
 * event is also mirrored to Core's audit_logs.
 *
 * @property int $id
 * @property string $evento
 * @property string|null $periodo
 * @property int|null $user_id
 * @property string $usuario
 * @property string|null $sujeto_tipo
 * @property string|null $sujeto_id
 * @property array<string, mixed> $cambios
 * @property array<string, mixed> $contexto
 * @property string|null $motivo
 * @property string|null $ip
 * @property CarbonImmutable|null $created_at
 * @property-read User|null $user
 */
#[Table(name: 'comisiones_bitacora')]
#[Fillable(['evento', 'periodo', 'user_id', 'usuario', 'sujeto_tipo', 'sujeto_id', 'cambios', 'contexto', 'motivo', 'ip'])]
final class AuditRecord extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'cambios' => 'array',
            'contexto' => 'array',
        ];
    }

    /**
     * Every model update (save(), update(), saveQuietly()) ends here, with or
     * without model events.
     *
     * @param  Builder<self>  $query
     */
    protected function performUpdate(Builder $query): never
    {
        throw new LogicException('The commission audit trail is insert-only.');
    }

    /**
     * Every model delete (delete(), deleteQuietly(), forceDelete(), destroy())
     * ends here, with or without model events.
     */
    protected function performDeleteOnModel(): never
    {
        throw new LogicException('The commission audit trail is insert-only.');
    }
}
