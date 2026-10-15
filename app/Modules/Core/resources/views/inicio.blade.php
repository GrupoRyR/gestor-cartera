{{--
    Landing page of GET / (core.inicio) while no menu entry is visible to the
    user: their screens appear in the menu as the modules are enabled.
--}}
<x-layouts.app :title="__('core::acceso.inicio.titulo')">
    <x-ui.page-header :title="__('core::acceso.inicio.saludo', ['nombre' => $firstName])" />

    <x-ui.empty
        :title="__('core::acceso.inicio.vacio')"
        :description="__('core::acceso.inicio.vacio_detalle')"
    />
</x-layouts.app>
