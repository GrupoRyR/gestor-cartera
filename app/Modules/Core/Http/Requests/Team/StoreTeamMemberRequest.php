<?php

namespace App\Modules\Core\Http\Requests\Team;

use App\Models\User;
use App\Modules\Core\Application\Team\TeamMemberDetails;
use Illuminate\Validation\Rule;

/**
 * POST /equipo: pre-registers a person. "correo" is normalized before it is
 * validated, so case variants of a registered e-mail are caught; a deleted
 * user's e-mail passes, because TeamMembers restores that user.
 */
final class StoreTeamMemberRequest extends TeamMemberRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->memberRules(),
            'correo' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->withoutTrashed()],
        ];
    }

    public function email(): string
    {
        return $this->string('correo')->value();
    }

    public function details(): TeamMemberDetails
    {
        return new TeamMemberDetails(
            $this->memberName(),
            $this->role(),
            $this->companies(),
            advisors: $this->advisorIds(),
            team: $this->teamIds(),
        );
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('correo');

        if (is_string($email)) {
            $this->merge(['correo' => User::normalizeEmail($email)]);
        }
    }
}
