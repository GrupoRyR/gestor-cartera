<?php

namespace App\Modules\Comisiones\Infrastructure\Persistence;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A row of "comisiones_bitacora": one audited event of the module. Written
 * only by AuditTrail; never updated nor deleted.
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

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new LogicException('The commission audit trail is insert-only.');
        });

        self::deleting(function (): never {
            throw new LogicException('The commission audit trail is insert-only.');
        });
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
}
