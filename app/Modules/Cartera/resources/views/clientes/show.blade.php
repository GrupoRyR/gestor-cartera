@use('App\Support\Formatting\Money')

{{--
    GET /cartera/clientes/{client} (cartera.clientes.show): the client file.
    $file: Application\Clients\ClientFile. The sections live in
    clientes/ficha/: data and status on the side, invoices, commitments, log
    and mail replies in the main column.
--}}
@php
    $detail = $file->detail;
    $client = $detail->summary;
    $creditLeft = $detail->creditLimit === null ? null : $detail->creditLimit - $client->balance;
@endphp

<x-layouts.app :title="$client->name">
    <x-ui.page-header
        :title="$client->name"
        :context="__('cartera::ficha.contexto', ['empresa' => $client->company->value, 'nit' => $client->taxId])"
    >
        <x-slot:actions>
            <x-ui.link-button :href="route('cartera.clientes.index')" variant="ghost" icon="chevron-left">{{ __('cartera::ficha.volver') }}</x-ui.link-button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-6">
        <div class="grid grid-cols-1 gap-3 min-[22rem]:grid-cols-2 md:gap-4 lg:grid-cols-4">
            <x-ui.stat :label="__('cartera::ficha.indicadores.total')">
                <x-ui.money :value="$client->balance" />
            </x-ui.stat>
            <x-ui.stat :label="__('cartera::ficha.indicadores.vencido')" :tone="$client->overdue > 0 ? 'danger' : 'success'">
                <x-ui.money :value="$client->overdue" />
            </x-ui.stat>
            <x-ui.stat
                :label="__('cartera::ficha.indicadores.mora')"
                :value="trans_choice('cartera::ficha.indicadores.mora_valor', $client->maxDaysOverdue)"
                :tone="$client->maxDaysOverdue > 0 ? 'warning' : 'success'"
            />
            <x-ui.stat
                :label="__('cartera::ficha.indicadores.cupo')"
                :hint="$creditLeft === null
                    ? __('cartera::ficha.indicadores.sin_cupo')
                    : ($creditLeft >= 0
                        ? __('cartera::ficha.indicadores.cupo_disponible', ['valor' => Money::format($creditLeft)])
                        : __('cartera::ficha.indicadores.cupo_excedido', ['valor' => Money::format(abs($creditLeft))]))"
                :tone="$creditLeft !== null && $creditLeft < 0 ? 'danger' : 'neutral'"
            >
                <x-ui.money :value="$detail->creditLimit" :fallback="__('cartera::ficha.sin_dato')" />
            </x-ui.stat>
        </div>

        <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <div class="grid gap-6">
                @include('cartera::clientes.ficha.gestion')
                @include('cartera::clientes.ficha.facturas')
                @include('cartera::clientes.ficha.compromisos')
                @include('cartera::clientes.ficha.bitacora')
                @include('cartera::clientes.ficha.respuestas')
            </div>

            <div class="grid gap-6">
                @include('cartera::clientes.ficha.datos')
                @include('cartera::clientes.ficha.contactos')
            </div>
        </div>
    </div>
</x-layouts.app>
