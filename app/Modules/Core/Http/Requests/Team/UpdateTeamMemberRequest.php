<?php

namespace App\Modules\Core\Http\Requests\Team;

use App\Modules\Core\Application\Team\TeamMemberDetails;

/**
 * PUT /equipo/{user}: the edit form. "activo" is a checkbox, so a missing
 * value means unchecked (the browser does not send it).
 */
final class UpdateTeamMemberRequest extends TeamMemberRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->memberRules(),
            'activo' => ['nullable', 'boolean'],
        ];
    }

    public function details(): TeamMemberDetails
    {
        return new TeamMemberDetails(
            $this->memberName(),
            $this->role(),
            $this->companies(),
            $this->boolean('activo'),
            $this->advisorIds(),
            $this->teamIds(),
        );
    }
}
