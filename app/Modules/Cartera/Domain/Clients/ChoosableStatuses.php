<?php

namespace App\Modules\Cartera\Domain\Clients;

use App\Modules\Core\Contracts\Enums\ClientStatus;

/**
 * The client statuses Cartera offers: the Kanban columns and its move
 * dialog, the status form of the client file, the status filter of the
 * client list and the validation of a status change (MoveClientRequest).
 *
 * Every ClientStatus in its order, except the retired ones, which stay in
 * the enum until they are removed but are never offered again.
 */
final class ChoosableStatuses
{
    /**
     * «En gestión»: retired; its clients moved to their automatic status.
     *
     * @var list<ClientStatus>
     */
    public const array RETIRED = [ClientStatus::InProgress];

    /**
     * @return list<ClientStatus>
     */
    public static function cases(): array
    {
        return array_values(array_filter(
            ClientStatus::cases(),
            fn (ClientStatus $status): bool => ! in_array($status, self::RETIRED, true),
        ));
    }

    /**
     * value => label, for <x-ui.select>.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }
}
