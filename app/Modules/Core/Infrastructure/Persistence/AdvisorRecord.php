<?php

namespace App\Modules\Core\Infrastructure\Persistence;

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Database\Factories\AdvisorRecordFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A row of "advisors": a SAP sales employee of one company (OSLP), with the
 * user it is linked to. The e-mail is stored trimmed and in lowercase
 * (User::normalizeEmail()), so it matches users.email.
 *
 * @property int $id
 * @property CompanyCode $company
 * @property int $code OSLP.SlpCode, unique within the company.
 * @property string $name
 * @property string|null $email
 * @property bool $active
 * @property int|null $user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table(name: 'advisors')]
#[Fillable(['company', 'code', 'name', 'email', 'active', 'user_id'])]
#[UseFactory(AdvisorRecordFactory::class)]
final class AdvisorRecord extends Model
{
    /** @use HasFactory<AdvisorRecordFactory> */
    use HasFactory;

    /**
     * The advisors linked to $user. Core registers it as the "advisors"
     * relation of App\Models\User.
     *
     * @return HasMany<self, User>
     */
    public static function linkedTo(User $user): HasMany
    {
        return $user->hasMany(self::class, 'user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company' => CompanyCode::class,
            'code' => 'integer',
            'active' => 'boolean',
            'user_id' => 'integer',
        ];
    }

    /**
     * Stores the e-mail as users.email does (see User::normalizeEmail()); a
     * blank one, as SAP sends for employees without e-mail, is no e-mail.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null || trim($value) === '' ? null : User::normalizeEmail($value),
        );
    }
}
