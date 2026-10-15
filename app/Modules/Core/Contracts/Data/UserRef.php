<?php

namespace App\Modules\Core\Contracts\Data;

/**
 * A lightweight reference to a platform user (App\Models\User), for display
 * and attribution without exposing the Eloquent model across modules.
 */
final readonly class UserRef
{
    /**
     * @param  int  $id  Primary key of the user.
     * @param  string  $name  Display name.
     * @param  string  $email  Corporate e-mail (Microsoft Entra ID account).
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
    ) {}
}
