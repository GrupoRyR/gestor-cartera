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
 * A row of "comisiones_documentos": a report generated from a closed
 * settlement version (§8, §10) and stored on the documents disk. Written
 * only by ReportArchive; once stored it is never updated nor deleted, and
 * every download checks the file against its sha256.
 *
 * @property int $id
 * @property int $liquidacion_id
 * @property string $tipo
 * @property string $referencia
 * @property string $archivo
 * @property string $ruta
 * @property string $sha256
 * @property int $bytes
 * @property int|null $generado_por
 * @property CarbonImmutable|null $created_at
 * @property-read User|null $generator
 */
#[Table(name: 'comisiones_documentos')]
#[Fillable(['liquidacion_id', 'tipo', 'referencia', 'archivo', 'ruta', 'sha256', 'bytes', 'generado_por'])]
final class DocumentRecord extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<User, $this>
     */
    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generado_por')->withTrashed();
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new LogicException('A stored commission report is immutable.');
        });

        self::deleting(function (): never {
            throw new LogicException('A stored commission report is immutable.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'liquidacion_id' => 'integer',
            'bytes' => 'integer',
            'generado_por' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
