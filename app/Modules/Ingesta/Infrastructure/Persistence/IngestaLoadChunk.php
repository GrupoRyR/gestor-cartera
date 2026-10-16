<?php

namespace App\Modules\Ingesta\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A row of "ingesta_load_chunks": up to ChunkCodec::ROWS rows of an
 * accepted load, encoded by ChunkCodec.
 *
 * @property int $id
 * @property int $load_id
 * @property int $sequence Position within the load, from 0.
 * @property int $row_count
 * @property string $payload
 */
#[Table(name: 'ingesta_load_chunks')]
#[Fillable(['load_id', 'sequence', 'row_count', 'payload'])]
final class IngestaLoadChunk extends Model
{
    /** Chunks are written once with their load and never updated. */
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'load_id' => 'integer',
            'sequence' => 'integer',
            'row_count' => 'integer',
        ];
    }
}
