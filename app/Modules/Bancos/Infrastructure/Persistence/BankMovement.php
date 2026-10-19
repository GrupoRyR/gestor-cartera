<?php

namespace App\Modules\Bancos\Infrastructure\Persistence;

use App\Models\User;
use App\Modules\Bancos\Database\Factories\BankMovementFactory;
use App\Modules\Bancos\Domain\Movement\MovementStatus;
use App\Modules\Core\Contracts\Enums\Bank;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bank credit (bancos_movimientos). amount is a decimal string with two
 * places, as the database returns it; the movement date is a date without
 * time.
 *
 * @property int $id
 * @property int $load_id
 * @property CompanyCode $company
 * @property Bank $bank
 * @property CarbonImmutable $movement_date
 * @property string $amount
 * @property string $currency
 * @property string|null $description
 * @property string|null $reference
 * @property string|null $account
 * @property string $fingerprint
 * @property MovementStatus $status
 * @property string|null $receipt_number
 * @property int|null $applied_by
 * @property CarbonImmutable|null $applied_at
 * @property-read BankLoad $bankLoad
 * @property-read User|null $appliedBy
 */
#[UseFactory(BankMovementFactory::class)]
final class BankMovement extends Model
{
    /** @use HasFactory<BankMovementFactory> */
    use HasFactory;

    protected $table = 'bancos_movimientos';

    protected $fillable = [
        'load_id', 'company', 'bank', 'movement_date', 'amount', 'currency', 'description', 'reference',
        'account', 'fingerprint', 'status', 'receipt_number', 'applied_by', 'applied_at',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'company' => CompanyCode::class,
            'bank' => Bank::class,
            'movement_date' => 'date',
            'amount' => 'decimal:2',
            'status' => MovementStatus::class,
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BankLoad, $this>
     */
    public function bankLoad(): BelongsTo
    {
        return $this->belongsTo(BankLoad::class, 'load_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
