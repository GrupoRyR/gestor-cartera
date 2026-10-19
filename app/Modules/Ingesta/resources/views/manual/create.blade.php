{{--
    GET /ingesta/carga-manual (ingesta.manual.create): company, cutoff date
    and the .xlsx of the SAP cartera export. Posting it shows the preview.
    $companies: value => label of the companies the user may upload for;
    $today: "Y-m-d", the default and latest cutoff.
--}}
<x-layouts.app :title="__('ingesta::manual.titulo')">
    <x-ui.page-header :context="__('ingesta::historial.titulo')" :title="__('ingesta::manual.titulo')" :description="__('ingesta::manual.descripcion')" />

    <x-ui.card>
        <form method="POST" action="{{ route('ingesta.manual.preview') }}" enctype="multipart/form-data" class="flex max-w-xl flex-col gap-4">
            @csrf

            <x-ui.select
                name="empresa"
                :label="__('ingesta::manual.formulario.empresa')"
                :options="$companies"
                :value="count($companies) === 1 ? array_key_first($companies) : null"
                :placeholder="count($companies) === 1 ? null : __('ingesta::manual.formulario.empresa_elige')"
                :required="true"
            />

            <x-ui.input
                name="fecha_corte"
                type="date"
                :label="__('ingesta::manual.formulario.corte')"
                :value="$today"
                :hint="__('ingesta::manual.formulario.corte_ayuda')"
                :required="true"
                :max="$today"
            />

            <x-ui.input
                name="archivo"
                type="file"
                :label="__('ingesta::manual.formulario.archivo')"
                :hint="__('ingesta::manual.formulario.archivo_ayuda')"
                :required="true"
                accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
            />

            <div class="flex flex-wrap gap-2">
                <x-ui.button type="submit" icon="upload">{{ __('ingesta::manual.formulario.enviar') }}</x-ui.button>
                <x-ui.link-button :href="route('ingesta.index')" variant="ghost">{{ __('ingesta::manual.formulario.cancelar') }}</x-ui.link-button>
            </div>
        </form>
    </x-ui.card>
</x-layouts.app>
