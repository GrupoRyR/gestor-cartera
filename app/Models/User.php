<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Shared identity model (owned by the base, see docs/arquitectura.md).
 *
 * Core adds its relations (companies, SAP advisor link) at runtime with
 * User::resolveRelationUsing() from its service provider instead of editing
 * this class. Other modules only reference users by id or through
 * Core\Contracts\Data\UserRef.
 *
 * email and microsoft_id stay unique even for soft-deleted users, so the audit
 * trail keeps pointing to one row per person: create users with
 * User::withTrashed()->restoreOrCreate(['email' => User::normalizeEmail($email)], [...]),
 * never with create().
 */
#[Fillable(['name', 'email', 'microsoft_id', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * The e-mail as it is stored: trimmed and in lowercase, so case variants
     * of one address cannot become two people. Use it for lookups too.
     */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * Stores every e-mail normalized (see normalizeEmail()).
     *
     * @return Attribute<string, string|null>
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null ? null : self::normalizeEmail($value),
        );
    }
}
