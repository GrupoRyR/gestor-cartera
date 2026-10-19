<?php

namespace App\Modules\Bancos\Infrastructure\Persistence;

use App\Models\User;
use App\Modules\Bancos\Domain\Movement\ImportSource;
use App\Modules\Core\Contracts\Enums\Bank;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A statement that was imported (bancos_cargas). The counts say what the
 * importer did with it (a failed load, e.g. an unreadable file in the import
 * folder, has no counts and says why in errors); errors lists, for the rows it could not read,
 * {row: ?int, message: string}.
 *
 * @property int $id
 * @property CompanyCode $company
 * @property Bank $bank
 * @property ImportSource $source
 * @property bool $failed
 * @property string|null $file_name
 * @property string|null $file_sha256
 * @property int $movements_read
 * @property int $credits
 * @property int $inserted
 * @property int $duplicates
 * @property int $debits_skipped
 * @property int $ignored_rows
 * @property int $error_count
 * @property list<array{row: int|null, message: string}>|null $errors
 * @property int|null $user_id
 * @property-read CarbonImmutable $created_at
 * @property-read User|null $user
 */
final class BankLoad extends Model
{
    protected $table = 'bancos_cargas';

    protected $fillable = [
        'company', 'bank', 'source', 'failed', 'file_name', 'file_sha256', 'movements_read', 'credits', 'inserted',
        'duplicates', 'debits_skipped', 'ignored_rows', 'error_count', 'errors', 'user_id',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'company' => CompanyCode::class,
            'bank' => Bank::class,
            'source' => ImportSource::class,
            'failed' => 'boolean',
            'errors' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<BankMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(BankMovement::class, 'load_id');
    }
}
