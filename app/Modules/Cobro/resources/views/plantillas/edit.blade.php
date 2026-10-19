@use('App\Modules\Cobro\Domain\Templates\Placeholders')
@use('App\Support\Formatting\DateFormat')

{{--
    GET /cobro/plantillas (cobro.plantillas.edit): the weekly collection
    template of one company. $companies: list<Company> the user may choose
    (one tab each); $company: the selected Company; $template:
    TemplateContent in use; $saved: ?Template row with its editor.
--}}
@php
    $tabs = array_map(fn ($option) => [
        'key' => $option->code->value,
        'label' => $option->name,
        'href' => route('cobro.plantillas.edit', ['empresa' => $option->code->value]),
    ], $companies);
@endphp

<x-layouts.app :title="__('cobro::plantillas.titulo')">
    <x-ui.page-header :title="__('cobro::plantillas.titulo')" :description="__('cobro::plantillas.descripcion')">
        <x-slot:actions>
            <x-ui.link-button :href="route('cobro.plantillas.preview', ['empresa' => $company->code->value])" variant="secondary" icon="eye" :external="true">{{ __('cobro::plantillas.acciones.vista_previa') }}</x-ui.link-button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex flex-col gap-6">
        @if (count($tabs) > 1)
            <x-ui.tabs :label="__('cobro::plantillas.empresas')" :items="$tabs" :current="$company->code->value" />
        @endif

        <x-ui.card :title="$company->name">
            <p class="mb-4">
                @if ($saved === null)
                    <x-ui.chip tone="neutral">{{ __('cobro::plantillas.estado.por_defecto') }}</x-ui.chip>
                @else
                    <x-ui.chip tone="info">{{ __('cobro::plantillas.estado.editada', ['nombre' => $saved->editor?->name ?? '—', 'fecha' => DateFormat::dateTime($saved->updated_at)]) }}</x-ui.chip>
                @endif
            </p>

            <form method="POST" action="{{ route('cobro.plantillas.update', ['empresa' => $company->code->value]) }}" class="flex flex-col gap-4">
                @csrf
                @method('PUT')

                <x-ui.input name="asunto" :label="__('cobro::plantillas.campos.asunto')" :value="$template->subject" :required="true" maxlength="200" />
                <x-ui.textarea name="inicio" :label="__('cobro::plantillas.campos.inicio')" :value="$template->intro" :rows="7" :hint="__('cobro::plantillas.campos.ayuda_parrafos')" :required="true" />
                <x-ui.textarea name="cierre" :label="__('cobro::plantillas.campos.cierre')" :value="$template->closing" :rows="6" :hint="__('cobro::plantillas.campos.ayuda_parrafos')" :required="true" />

                <div class="flex flex-wrap gap-2">
                    <x-ui.button type="submit" icon="check">{{ __('cobro::plantillas.acciones.guardar') }}</x-ui.button>
                </div>
            </form>

            @if ($saved !== null)
                <x-slot:footer>
                    <form method="POST" action="{{ route('cobro.plantillas.reset', ['empresa' => $company->code->value]) }}">
                        @csrf
                        <x-ui.button type="submit" variant="ghost" icon="refresh">{{ __('cobro::plantillas.acciones.restaurar') }}</x-ui.button>
                    </form>
                </x-slot:footer>
            @endif
        </x-ui.card>

        <x-ui.card :title="__('cobro::plantillas.variables.titulo')" :description="__('cobro::plantillas.variables.descripcion')">
            <x-ui.table
                :caption="__('cobro::plantillas.variables.tabla')"
                :caption-hidden="true"
                :columns="[
                    ['key' => 'name', 'label' => __('cobro::plantillas.variables.columnas.dato')],
                    ['key' => 'meaning', 'label' => __('cobro::plantillas.variables.columnas.significa')],
                ]"
            >
                @foreach (Placeholders::ALLOWED as $name => $meaning)
                    <tr>
                        <td><code>{{ '{'.$name.'}' }}</code></td>
                        <td>{{ $meaning }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    </div>
</x-layouts.app>
