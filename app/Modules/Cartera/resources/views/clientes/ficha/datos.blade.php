@use('App\Modules\Cartera\Application\Clients\AssigneeOptions')
@use('App\Modules\Core\Contracts\Enums\ClientStatus')

{{--
    Data of the client and, for those who may, the forms that change its
    manager and its status. $file, $client; $assigneeOptions: the "Gestor"
    choices, or null for those who may not assign managers.
--}}
<x-ui.card :title="__('cartera::ficha.datos.titulo')">
    {{-- Label beside value when the card is wide enough; one under the other when it is narrow (large text). --}}
    <div class="@container">
        <dl class="grid gap-x-6 gap-y-0.5 text-[0.9375rem] [overflow-wrap:anywhere] [&>dd]:mb-2 @xs:grid-cols-[fit-content(45%)_minmax(0,1fr)] @xs:gap-y-2 @xs:[&>dd]:mb-0">
            <dt class="text-fg-muted">{{ __('cartera::ficha.datos.estado') }}</dt>
            <dd><x-ui.badge-state :state="$client->status" /></dd>

            <dt class="text-fg-muted">{{ __('cartera::ficha.datos.codigo') }}</dt>
            <dd>{{ $client->code }}</dd>

            <dt class="text-fg-muted">{{ __('cartera::ficha.datos.nit') }}</dt>
            <dd>{{ $client->taxId }}</dd>

            <dt class="text-fg-muted">{{ __('cartera::ficha.datos.empresa') }}</dt>
            <dd>{{ $client->company->label() }}</dd>

            <dt class="text-fg-muted">{{ __('cartera::ficha.datos.grupo') }}</dt>
            <dd>{{ $detail->group ?? __('cartera::ficha.sin_dato') }}</dd>

            <dt class="text-fg-muted">{{ __('cartera::ficha.datos.ciudad') }}</dt>
            <dd>{{ $detail->city ?? __('cartera::ficha.sin_dato') }}</dd>

            <dt class="text-fg-muted">{{ __('cartera::ficha.datos.condicion') }}</dt>
            <dd>{{ $detail->paymentTerms ?? __('cartera::ficha.sin_dato') }}</dd>

            <dt class="text-fg-muted">{{ __('cartera::ficha.datos.asesor') }}</dt>
            <dd>{{ $client->advisor?->name ?? __('cartera::ficha.datos.sin_asesor') }}</dd>

            <dt class="text-fg-muted">{{ __('cartera::ficha.datos.gestor') }}</dt>
            <dd>
                {{ $client->manager?->name ?? __('cartera::ficha.datos.sin_gestor') }}

                @if ($assigneeOptions !== null)
                    <form method="POST" action="{{ route('cartera.clientes.gestor', $client->id) }}" class="mt-2 flex flex-wrap items-start gap-2">
                        @csrf
                        <div class="min-w-0 flex-1">
                            <x-ui.select
                                name="nuevo_gestor"
                                :label="__('cartera::ficha.gestor.etiqueta')"
                                :label-hidden="true"
                                :options="$assigneeOptions"
                                :value="$client->manager?->id ?? AssigneeOptions::UNASSIGNED"
                                :required="true"
                            />
                        </div>
                        <x-ui.button type="submit" variant="secondary">{{ __('cartera::ficha.gestor.boton') }}</x-ui.button>
                    </form>
                @endif
            </dd>
        </dl>
    </div>

    @can('core.clients.change_status')
        <form method="POST" action="{{ route('cartera.clientes.estado', $client->id) }}" class="mt-6 grid gap-4 border-t border-line pt-5">
            @csrf
            <h3 class="text-base font-bold text-fg">{{ __('cartera::ficha.cambiar_estado.titulo') }}</h3>
            <x-ui.select name="estado" :label="__('cartera::ficha.cambiar_estado.estado')" :options="ClientStatus::options()" :value="$client->status->value" :required="true" />
            <x-ui.textarea name="nota" :label="__('cartera::ficha.cambiar_estado.nota')" :hint="__('cartera::ficha.cambiar_estado.nota_ayuda')" :rows="2" />
            <div>
                <x-ui.button type="submit" variant="secondary">{{ __('cartera::ficha.cambiar_estado.boton') }}</x-ui.button>
            </div>
        </form>
    @endcan
</x-ui.card>
