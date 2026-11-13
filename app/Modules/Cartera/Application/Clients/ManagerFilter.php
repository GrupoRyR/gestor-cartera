<?php

namespace App\Modules\Cartera\Application\Clients;

use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * The "Gestor" filter of the client list: the clients whose portfolio
 * manager is the user ("Mi cartera"), the ones without a manager ("Sin
 * asignar") or every client. It is only a filter, never a restriction: the
 * whole team still sees every client. The value is the one in the URL
 * (?gestor=).
 */
enum ManagerFilter: string
{
    case Mine = 'mios';
    case Unassigned = 'sin_asignar';
    case All = 'todos';

    /**
     * Where the list opens when the URL does not say: with the
     * cartera.clients.start_on_mine permission (the analyst, who works her
     * own clients) on "Mi cartera"; the portfolio manager and everyone else
     * on the whole portfolio.
     */
    public static function defaultFor(?Authorizable $user): self
    {
        return $user?->can('cartera.clients.start_on_mine') === true ? self::Mine : self::All;
    }

    /**
     * @return array<string, string> value => label, for <x-ui.select>.
     */
    public static function options(): array
    {
        $options = [];

        foreach ([self::All, self::Mine, self::Unassigned] as $case) {
            $options[$case->value] = __("cartera::clientes.filtros.gestor_opciones.{$case->value}");
        }

        return $options;
    }
}
