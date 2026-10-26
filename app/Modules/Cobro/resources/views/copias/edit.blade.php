@use('App\Support\Formatting\DateFormat')
@use('App\Modules\Cobro\Http\Requests\UpdateCopiesRequest')
@use('App\View\Components\Support\FieldStyle')

{{--
    GET /cobro/copias (cobro.copias.edit): the carbon copies of one
    company's weekly collection mail. $companies: list<Company> the user
    may choose (one tab each); $company: the selected Company; $settings:
    CopySettings in use; $saved: ?CopySetting row with its editor;
    $emptyRows: blank manager rows added after the saved ones (after a
    failed submit, the submitted rows are shown instead).
--}}
@php
    $tabs = array_map(fn ($option) => [
        'key' => $option->code->value,
        'label' => $option->name,
        'href' => route('cobro.copias.edit', ['empresa' => $option->code->value]),
    ], $companies);
    $managers = array_map(fn (array $manager) => ['grupo' => $manager['group'], 'correos' => implode(', ', $manager['emails'])], $settings->managers);
    $rows = max(count((array) old('gerentes', [])), count($managers) + $emptyRows);
    $addressHint = __('cobro::copias.campos.ayuda_correos').' '.__('cobro::copias.campos.ayuda_dominios', ['dominios' => UpdateCopiesRequest::domainList()]);
@endphp

<x-layouts.app :title="__('cobro::copias.titulo')">
    <x-ui.page-header :title="__('cobro::copias.titulo')" :description="__('cobro::copias.descripcion')" />

    <div class="flex flex-col gap-6">
        @if (count($tabs) > 1)
            <x-ui.tabs :label="__('cobro::copias.empresas')" :items="$tabs" :current="$company->code->value" />
        @endif

        <x-ui.card :title="$company->name">
            <p class="mb-4">
                @if ($saved === null)
                    <x-ui.chip tone="neutral">{{ __('cobro::copias.estado.por_defecto') }}</x-ui.chip>
                @else
                    <x-ui.chip tone="info">{{ __('cobro::copias.estado.editada', ['nombre' => $saved->editor?->name ?? '—', 'fecha' => DateFormat::dateTime($saved->updated_at)]) }}</x-ui.chip>
                @endif
            </p>

            <form method="POST" action="{{ route('cobro.copias.update', ['empresa' => $company->code->value]) }}" class="flex flex-col gap-6">
                @csrf
                @method('PUT')

                <x-ui.textarea name="fijos" :label="__('cobro::copias.campos.fijos')" :value="implode(PHP_EOL, $settings->fixed)" :rows="4" :hint="$addressHint" />

                <x-ui.checkbox name="asesor" :label="__('cobro::copias.campos.asesor')" :checked="$settings->advisor" :hint="__('cobro::copias.campos.ayuda_asesor')" />

                <fieldset class="flex min-w-0 flex-col gap-4">
                    <legend class="{{ FieldStyle::LABEL }}">{{ __('cobro::copias.gerentes.titulo') }}</legend>
                    <p class="{{ FieldStyle::HINT }}">{{ __('cobro::copias.gerentes.descripcion') }} {{ __('cobro::copias.gerentes.ayuda_vacias') }}</p>

                    @for ($i = 0; $i < $rows; $i++)
                        <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]" data-manager-row="{{ $i }}">
                            <x-ui.input :name="'gerentes['.$i.'][grupo]'" :label="__('cobro::copias.gerentes.grupo', ['numero' => $i + 1])" :value="$managers[$i]['grupo'] ?? ''" maxlength="100" />
                            <x-ui.input :name="'gerentes['.$i.'][correos]'" :label="__('cobro::copias.gerentes.correos', ['numero' => $i + 1])" :value="$managers[$i]['correos'] ?? ''" :hint="$addressHint" />
                        </div>
                    @endfor
                </fieldset>

                <div class="flex flex-wrap gap-2">
                    <x-ui.button type="submit" icon="check">{{ __('cobro::copias.acciones.guardar') }}</x-ui.button>
                </div>
            </form>

            @if ($saved !== null)
                <x-slot:footer>
                    <form method="POST" action="{{ route('cobro.copias.reset', ['empresa' => $company->code->value]) }}">
                        @csrf
                        @method('DELETE')
                        <x-ui.button type="submit" variant="ghost" icon="refresh">{{ __('cobro::copias.acciones.restaurar') }}</x-ui.button>
                    </form>
                </x-slot:footer>
            @endif
        </x-ui.card>
    </div>
</x-layouts.app>
