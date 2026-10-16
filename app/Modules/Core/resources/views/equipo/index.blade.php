@use('App\Modules\Core\Application\Team\TeamTab')
@use('App\Modules\Core\Contracts\Enums\Role')
@use('Illuminate\Support\Arr')

{{--
    GET /equipo (core.equipo.index): the users of the platform, for admins.
    One tab per state (links that keep the search and the order), a search
    by name or e-mail, a table sortable by name and e-mail, 25 per page.
    $members comes with roles loaded; $companies holds their companies by
    user id, in CompanyCode order.
--}}
@php
    $tabs = array_map(fn (TeamTab $tab): array => [
        'key' => $tab->value,
        'label' => $tab->label(),
        'href' => route('core.equipo.index', $filter->query($tab)),
        'count' => $counts[$tab->value],
    ], TeamTab::cases());

    $columns = [
        ['key' => 'name', 'label' => __('core::equipo.columnas.nombre'), 'sortable' => true],
        ['key' => 'email', 'label' => __('core::equipo.columnas.correo'), 'sortable' => true],
        ['key' => 'role', 'label' => __('core::equipo.columnas.rol')],
        ['key' => 'companies', 'label' => __('core::equipo.columnas.empresas')],
        ['key' => 'status', 'label' => __('core::equipo.columnas.estado')],
        ['key' => 'actions', 'label' => __('core::equipo.columnas.acciones'), 'align' => 'end'],
    ];

    $empty = $filter->search === '' ? $filter->tab->value : 'busqueda';
@endphp

<x-layouts.app :title="__('core::equipo.titulo')">
    <x-ui.page-header :title="__('core::equipo.titulo')" :description="__('core::equipo.descripcion')">
        <x-slot:actions>
            <x-ui.link-button :href="route('core.equipo.create')" icon="plus">{{ __('core::equipo.nuevo') }}</x-ui.link-button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.tabs :label="__('core::equipo.pestanas.etiqueta')" :items="$tabs" :current="$filter->tab->value" />

    <form method="GET" action="{{ route('core.equipo.index') }}" role="search">
        @foreach (Arr::except($filter->query(), ['buscar']) as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach

        <x-ui.input
            name="buscar"
            type="search"
            :label="__('core::equipo.buscar.etiqueta')"
            :label-hidden="true"
            :value="$filter->search"
            :placeholder="__('core::equipo.buscar.ayuda')"
        />
        <x-ui.button type="submit" variant="secondary" icon="search">{{ __('core::equipo.buscar.boton') }}</x-ui.button>
    </form>

    @if ($members->isEmpty())
        <x-ui.empty
            icon="users"
            :title="__('core::equipo.vacio.'.$empty.'.titulo', ['buscar' => $filter->search])"
            :description="__('core::equipo.vacio.'.$empty.'.detalle')"
        />
    @else
        <x-ui.table
            :caption="__('core::equipo.tabla.'.$filter->tab->value)"
            :caption-hidden="true"
            :columns="$columns"
            :sort-by="$filter->sort"
            :sort-direction="$filter->direction"
        >
            @foreach ($members as $member)
                @php($role = Role::tryFrom((string) $member->roles->first()?->name))
                <tr>
                    <td>{{ $member->name }}</td>
                    <td>{{ $member->email }}</td>
                    <td>{{ $role?->label() ?? __('core::equipo.sin_rol') }}</td>
                    <td>
                        @if ($role === Role::Admin)
                            <x-ui.chip :dot="false">{{ __('core::equipo.todas') }}</x-ui.chip>
                        @else
                            @foreach ($companies[$member->id] ?? [] as $company)
                                <x-ui.chip :dot="false">{{ $company->value }}</x-ui.chip>
                            @endforeach
                        @endif
                    </td>
                    <td>
                        @if ($member->trashed())
                            <x-ui.chip tone="danger">{{ __('core::equipo.estados.eliminado') }}</x-ui.chip>
                        @elseif (! $member->is_active)
                            <x-ui.chip>{{ __('core::equipo.estados.inactivo') }}</x-ui.chip>
                        @else
                            <x-ui.chip tone="success">{{ __('core::equipo.estados.activo') }}</x-ui.chip>
                        @endif
                    </td>
                    <td data-align="end">
                        @if ($member->trashed())
                            <form method="POST" action="{{ route('core.equipo.restore', $member) }}">
                                @csrf
                                <x-ui.button type="submit" variant="secondary" icon="refresh">{{ __('core::equipo.acciones.restaurar') }}<span data-visually-hidden> {{ __('core::equipo.acciones.a', ['nombre' => $member->name]) }}</span></x-ui.button>
                            </form>
                        @else
                            <x-ui.link-button :href="route('core.equipo.edit', $member)" variant="ghost" icon="edit">{{ __('core::equipo.acciones.editar') }}<span data-visually-hidden> {{ __('core::equipo.acciones.a', ['nombre' => $member->name]) }}</span></x-ui.link-button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>

        <x-ui.pagination :paginator="$members" />
    @endif
</x-layouts.app>
