@use('App\Modules\Core\Http\Requests\Companies\UpdateCompanyParametersRequest')

{{--
    GET /empresas/{empresa}/editar (core.empresas.edit): the grace days and
    the due-soon window of one company, in whole days. $company:
    CompanyParameters. Saving queues the recompute of its client statuses.
--}}
<x-layouts.app :title="$company->name">
    <x-ui.page-header :context="__('core::empresas.parametros.titulo')" :title="$company->name" :description="__('core::empresas.parametros.formulario.descripcion')" />

    <x-ui.card>
        <form method="POST" action="{{ route('core.empresas.update', $company->code->value) }}">
            @csrf
            @method('PUT')

            <x-ui.input
                name="grace_days"
                type="number"
                :label="__('core::empresas.parametros.formulario.dias_gracia')"
                :value="$company->graceDays"
                :hint="__('core::empresas.parametros.formulario.dias_gracia_ayuda')"
                :suffix="__('core::empresas.parametros.formulario.unidad')"
                :required="true"
                min="0"
                :max="UpdateCompanyParametersRequest::MAX_GRACE_DAYS"
                step="1"
                inputmode="numeric"
            />

            <x-ui.input
                name="due_soon_days"
                type="number"
                :label="__('core::empresas.parametros.formulario.ventana')"
                :value="$company->dueSoonDays"
                :hint="__('core::empresas.parametros.formulario.ventana_ayuda')"
                :suffix="__('core::empresas.parametros.formulario.unidad')"
                :required="true"
                min="0"
                :max="UpdateCompanyParametersRequest::MAX_DUE_SOON_DAYS"
                step="1"
                inputmode="numeric"
            />

            <x-ui.button type="submit" icon="check">{{ __('core::empresas.parametros.formulario.guardar') }}</x-ui.button>
            <x-ui.link-button :href="route('core.empresas.index')" variant="ghost">{{ __('core::empresas.parametros.formulario.cancelar') }}</x-ui.link-button>
        </form>
    </x-ui.card>
</x-layouts.app>
