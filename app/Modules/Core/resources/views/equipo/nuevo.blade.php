{{--
    GET /equipo/nuevo (core.equipo.create): pre-registers a person before
    their first Microsoft sign-in, with their SAP advisors and team, or
    restores the deleted user of that e-mail (TeamMembers::create()). The
    advisor fields come from TeamDirectory::advisorFields().
--}}
<x-layouts.app :title="__('core::equipo.nuevo')">
    <x-ui.page-header
        :context="__('core::equipo.titulo')"
        :title="__('core::equipo.nuevo')"
        :description="__('core::equipo.formulario.nuevo_detalle')"
    />

    <x-ui.card>
        <form method="POST" action="{{ route('core.equipo.store') }}">
            @csrf

            <x-ui.input name="nombre" :label="__('core::equipo.formulario.nombre')" :required="true" autocomplete="off" />
            <x-ui.input
                name="correo"
                type="email"
                :label="__('core::equipo.formulario.correo')"
                :hint="__('core::equipo.formulario.correo_ayuda')"
                :required="true"
                autocomplete="off"
            />

            @include('core::equipo.partials.acceso', ['role' => null, 'companies' => []])
            @include('core::equipo.partials.asesores', ['memberId' => null])

            <x-ui.button type="submit" icon="check">{{ __('core::equipo.formulario.crear') }}</x-ui.button>
            <x-ui.link-button :href="route('core.equipo.index')" variant="ghost">{{ __('core::equipo.formulario.cancelar') }}</x-ui.link-button>
        </form>
    </x-ui.card>
</x-layouts.app>
