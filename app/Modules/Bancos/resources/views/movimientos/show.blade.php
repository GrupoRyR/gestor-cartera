@use('App\Support\Formatting\DateFormat')

{{--
    GET /bancos/movimiento/{movimiento} (bancos.movimientos.show): one credit
    and, for those with bancos.apply, the form that sets its status and the
    cash receipt number.
    $movement: BankMovement with bankLoad and appliedBy; $statuses: value =>
    label; $back: URL of the list the user came from.
--}}
<x-layouts.app :title="__('bancos::movimientos.detalle.titulo', ['banco' => $movement->bank->label()])">
    <x-ui.page-header
        :context="__('bancos::movimientos.detalle.contexto')"
        :title="__('bancos::movimientos.detalle.titulo', ['banco' => $movement->bank->label()])"
    >
        <x-slot:actions>
            <x-ui.link-button :href="$back" variant="secondary" icon="chevron-left">{{ __('bancos::movimientos.detalle.volver') }}</x-ui.link-button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid items-start gap-6 lg:grid-cols-2">
        <x-ui.card :title="__('bancos::movimientos.detalle.datos')">
            <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                <div>
                    <dt class="text-sm text-fg-soft">{{ __('bancos::movimientos.detalle.banco') }}</dt>
                    <dd class="font-semibold">{{ $movement->bank->label() }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-fg-soft">{{ __('bancos::movimientos.detalle.empresa') }}</dt>
                    <dd class="font-semibold">{{ $movement->company->label() }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-fg-soft">{{ __('bancos::movimientos.detalle.fecha') }}</dt>
                    <dd class="font-semibold"><x-ui.date :value="$movement->movement_date" /></dd>
                </div>
                <div>
                    <dt class="text-sm text-fg-soft">{{ __('bancos::movimientos.detalle.valor') }}</dt>
                    <dd class="font-semibold"><x-bancos::amount :value="$movement->amount" :currency="$movement->currency" /></dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-sm text-fg-soft">{{ __('bancos::movimientos.detalle.descripcion') }}</dt>
                    <dd class="break-words">{{ $movement->description ?? __('bancos::movimientos.detalle.sin_dato') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-fg-soft">{{ __('bancos::movimientos.detalle.referencia') }}</dt>
                    <dd class="break-words">{{ $movement->reference ?? __('bancos::movimientos.detalle.sin_dato') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-fg-soft">{{ __('bancos::movimientos.detalle.cuenta') }}</dt>
                    <dd class="break-words">{{ $movement->account ?? __('bancos::movimientos.detalle.sin_dato') }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-sm text-fg-soft">{{ __('bancos::movimientos.detalle.carga') }}</dt>
                    <dd>
                        {{ $movement->bankLoad->file_name ?? $movement->bankLoad->source->label() }}
                        · <a class="font-semibold text-brand-ink underline underline-offset-4" href="{{ route('bancos.cargas.show', $movement->bankLoad) }}">{{ __('bancos::movimientos.detalle.ver_carga') }}</a>
                    </dd>
                </div>
            </dl>
        </x-ui.card>

        <x-ui.card :title="__('bancos::movimientos.formulario.titulo')" :description="__('bancos::movimientos.formulario.descripcion')">
            <div class="mb-4 flex flex-wrap items-center gap-3">
                <span class="text-sm text-fg-soft">{{ __('bancos::movimientos.detalle.estado') }}</span>
                <x-ui.chip :tone="$movement->status->tone()">{{ $movement->status->label() }}</x-ui.chip>
                @if ($movement->receipt_number)
                    <span class="text-sm text-fg-soft">{{ __('bancos::movimientos.detalle.recibo') }}</span>
                    <span class="font-semibold">{{ $movement->receipt_number }}</span>
                @endif
            </div>

            @if ($movement->appliedBy)
                <p class="mb-4 text-sm text-fg-soft">
                    {{ __('bancos::movimientos.detalle.aplicado_por') }} {{ $movement->appliedBy->name }}
                    · <x-ui.date :value="$movement->applied_at" :time="true" />
                </p>
            @endif

            @can('bancos.apply')
                <form method="POST" action="{{ route('bancos.movimientos.update', $movement) }}" class="flex flex-col gap-4">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="volver" value="{{ $back }}">

                    <x-ui.select
                        name="estado"
                        :label="__('bancos::movimientos.formulario.estado')"
                        :options="$statuses"
                        :value="$movement->status"
                        :required="true"
                    />

                    <x-ui.input
                        name="recibo"
                        :label="__('bancos::movimientos.formulario.recibo')"
                        :hint="__('bancos::movimientos.formulario.recibo_ayuda')"
                        :value="$movement->receipt_number"
                        maxlength="60"
                    />

                    <div>
                        <x-ui.button type="submit" icon="check">{{ __('bancos::movimientos.formulario.guardar') }}</x-ui.button>
                    </div>
                </form>
            @else
                <x-ui.alert tone="info">{{ __('bancos::movimientos.formulario.solo_lectura') }}</x-ui.alert>
            @endcan
        </x-ui.card>
    </div>
</x-layouts.app>
