@use('App\Support\Formatting\DateFormat')

{{--
    GET /cobro/firma (cobro.firma.edit): who signs one company's Cobro
    mails. $companies: list<Company> the user may choose (one tab each);
    $company: the selected Company; $person: SignaturePerson in use;
    $saved: ?SignatureSetting row with its editor.
--}}
@php
    $tabs = array_map(fn ($option) => [
        'key' => $option->code->value,
        'label' => $option->name,
        'href' => route('cobro.firma.edit', ['empresa' => $option->code->value]),
    ], $companies);
@endphp

<x-layouts.app :title="__('cobro::firma.titulo')">
    <x-ui.page-header :title="__('cobro::firma.titulo')" :description="__('cobro::firma.descripcion')" />

    <div class="flex flex-col gap-6">
        @if (count($tabs) > 1)
            <x-ui.tabs :label="__('cobro::firma.empresas')" :items="$tabs" :current="$company->code->value" />
        @endif

        <x-ui.card :title="$company->name">
            <p class="mb-4">
                @if ($saved === null)
                    <x-ui.chip tone="neutral">{{ __('cobro::firma.estado.por_defecto') }}</x-ui.chip>
                @else
                    <x-ui.chip tone="info">{{ __('cobro::firma.estado.editada', ['nombre' => $saved->editor?->name ?? '—', 'fecha' => DateFormat::dateTime($saved->updated_at)]) }}</x-ui.chip>
                @endif
            </p>

            <form method="POST" action="{{ route('cobro.firma.update', ['empresa' => $company->code->value]) }}" class="flex flex-col gap-6">
                @csrf
                @method('PUT')

                <x-ui.input name="nombre" :label="__('cobro::firma.campos.nombre')" :value="$person->name" maxlength="100" required />
                <x-ui.input name="cargo" :label="__('cobro::firma.campos.cargo')" :value="$person->title" maxlength="100" required />
                <x-ui.input name="movil" type="tel" :label="__('cobro::firma.campos.movil')" :value="$person->mobile" :hint="__('cobro::firma.campos.ayuda_movil')" maxlength="30" required />
                <x-ui.input name="extension" inputmode="numeric" :label="__('cobro::firma.campos.extension')" :value="$person->extension" :hint="__('cobro::firma.campos.ayuda_extension')" maxlength="6" />

                <div class="flex flex-wrap gap-2">
                    <x-ui.button type="submit" icon="check">{{ __('cobro::firma.acciones.guardar') }}</x-ui.button>
                </div>
            </form>

            @if ($saved !== null)
                <x-slot:footer>
                    <form method="POST" action="{{ route('cobro.firma.reset', ['empresa' => $company->code->value]) }}">
                        @csrf
                        @method('DELETE')
                        <x-ui.button type="submit" variant="ghost" icon="refresh">{{ __('cobro::firma.acciones.restaurar') }}</x-ui.button>
                    </form>
                </x-slot:footer>
            @endif
        </x-ui.card>
    </div>
</x-layouts.app>
