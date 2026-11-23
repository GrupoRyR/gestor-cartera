<?php

namespace App\Modules\Soportes\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file of a receipt (soporte_archivos). path is relative to the disk in
 * config('soportes.disk') and uses a generated name; original_name is only
 * shown to the user. mime is the type sniffed from the content at upload and
 * size is in bytes.
 *
 * @property int $id
 * @property int $soporte_id
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property-read Receipt $receipt
 */
final class ReceiptFile extends Model
{
    protected $table = 'soporte_archivos';

    protected $fillable = ['soporte_id', 'path', 'original_name', 'mime', 'size'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Receipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class, 'soporte_id');
    }
}
