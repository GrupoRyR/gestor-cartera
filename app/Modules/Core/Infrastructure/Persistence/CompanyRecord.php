<?php

namespace App\Modules\Core\Infrastructure\Persistence;

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A row of "companies": a company of the group and the parameters admins
 * edit. Other code reads companies through CompanyCatalog, which turns rows
 * into the Contracts\Data\Company DTO.
 *
 * @property CompanyCode $code
 * @property string $name
 * @property int $grace_days
 * @property int $due_soon_days
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table(name: 'companies', key: 'code', keyType: 'string', incrementing: false)]
#[Fillable(['code', 'name', 'grace_days', 'due_soon_days'])]
final class CompanyRecord extends Model
{
    /**
     * The companies $user is assigned to, through company_user. Core
     * registers it as the "companies" relation of App\Models\User.
     *
     * @return BelongsToMany<self, User, Pivot>
     */
    public static function assignedTo(User $user): BelongsToMany
    {
        return $user->belongsToMany(self::class, 'company_user', 'user_id', 'company', 'id', 'code', 'companies')
            ->withTimestamps();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'code' => CompanyCode::class,
            'grace_days' => 'integer',
            'due_soon_days' => 'integer',
        ];
    }
}
