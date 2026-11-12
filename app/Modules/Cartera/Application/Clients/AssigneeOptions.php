<?php

namespace App\Modules\Cartera\Application\Clients;

use App\Modules\Core\Contracts\Data\UserRef;

/**
 * The choices of the "gestor" selects of the client list and the client
 * file: "Sin asignar" first, then each assignee by id. The form posts the
 * choice as nuevo_gestor; managerUserId() turns it back into a user id.
 */
final class AssigneeOptions
{
    public const string UNASSIGNED = 'sin_asignar';

    /**
     * Accepted values of nuevo_gestor: "sin_asignar" or a user id.
     */
    public const string PATTERN = '/^(sin_asignar|[1-9][0-9]{0,8})$/';

    /**
     * @param  list<UserRef>  $assignees  ClientAssignmentService::assignees().
     * @param  UserRef|null  $current  The current manager, kept as a choice even when no longer an assignee.
     * @return array<int|string, string> value => label, for <x-ui.select>.
     */
    public static function from(array $assignees, ?UserRef $current = null): array
    {
        $options = [self::UNASSIGNED => __('cartera::clientes.asignar.sin_asignar')];

        foreach ($assignees as $assignee) {
            $options[$assignee->id] = $assignee->name;
        }

        if ($current !== null && ! isset($options[$current->id])) {
            $options[$current->id] = $current->name;
        }

        return $options;
    }

    /**
     * The user id a valid nuevo_gestor stands for; null for "Sin asignar".
     */
    public static function managerUserId(string $choice): ?int
    {
        return $choice === self::UNASSIGNED ? null : (int) $choice;
    }
}
