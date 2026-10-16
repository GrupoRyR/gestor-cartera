@use('App\Modules\Core\Contracts\Enums\Role')

{{--
    GET /equipo/{user}/editar (core.equipo.edit): name, role, companies,
    SAP advisors, sales manager's team and activation of a user who is not
    deleted, and the soft delete behind a confirmation. An admin editing
    themselves gets no delete button (TeamMembers refuses it anyway).
    $member comes with its roles; $companies: list<CompanyCode>; the advisor
    fields come from TeamDirectory::advisorFields().
--}}
@php($role = Role::tryFrom((string) $member->roles->first()?->name))

<x-layouts.app :title="$member->name">
    <x-ui.page-header :context="__('core::equipo.titulo')" :title="$member->name" :description="$member->email" />

    <x-ui.card>
        <form method="POST" action="{{ route('core.equipo.update', $member) }}">
            @csrf
            @method('PUT')

            <x-ui.input name="nombre" :label="__('core::equipo.formulario.nombre')" :value="$member->name" :required="true" autocomplete="off" />

            @include('core::equipo.partials.acceso', ['role' => $role, 'companies' => $companies])
            @include('core::equipo.partials.asesores', ['memberId' => $member->id])

            <x-ui.checkbox
                name="activo"
                :label="__('core::equipo.formulario.activo')"
                :checked="(bool) $member->is_active"
                :hint="__('core::equipo.formulario.activo_ayuda')"
            />

            <x-ui.button type="submit" icon="check">{{ __('core::equipo.formulario.guardar') }}</x-ui.button>
            <x-ui.link-button :href="route('core.equipo.index')" variant="ghost">{{ __('core::equipo.formulario.cancelar') }}</x-ui.link-button>
        </form>
    </x-ui.card>

    @unless ($isSelf)
        <x-ui.card :title="__('core::equipo.eliminar.titulo')" :description="__('core::equipo.eliminar.detalle')">
            <x-ui.button variant="danger" icon="trash" data-modal-open="eliminar-usuario">{{ __('core::equipo.eliminar.boton') }}</x-ui.button>
        </x-ui.card>

        <x-ui.modal id="eliminar-usuario" :title="__('core::equipo.eliminar.confirmar', ['nombre' => $member->name])">
            <p>{{ __('core::equipo.eliminar.confirmar_detalle') }}</p>

            <x-slot:footer>
                <x-ui.button variant="secondary" data-modal-close>{{ __('core::equipo.eliminar.no') }}</x-ui.button>
                <form method="POST" action="{{ route('core.equipo.destroy', $member) }}">
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="submit" variant="danger" icon="trash">{{ __('core::equipo.eliminar.si') }}</x-ui.button>
                </form>
            </x-slot:footer>
        </x-ui.modal>
    @endunless
</x-layouts.app>
