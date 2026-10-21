@use('App\Modules\Asesores\Domain\Format\Percent')
@use('App\Modules\Asesores\Domain\Portfolio\AgingBars')
@use('App\Support\Formatting\Money')

{{--
    Balance by age as horizontal bars (skill dataviz: magnitude, one hue from
    light to dark as the debt gets older). Every bar is labelled with its
    amount and share, so nothing depends on color or on hovering, and the
    same numbers are one click away as a table. A bucket that nets credit
    notes or advances is drawn in gray and says "a favor".
--}}
@props(['aging'])

@php
    $rows = AgingBars::rows($aging);
    $hasMoney = collect($rows)->contains(fn ($row): bool => $row->value != 0.0);

    // Whole classes, so Tailwind finds them. Lightest = not due, darkest = over 90 days.
    $fills = [
        'not_due' => 'bg-fg-muted/40',
        'days_1_30' => 'bg-brand-primary/35',
        'days_31_60' => 'bg-brand-primary/55',
        'days_61_90' => 'bg-brand-primary/75',
        'over_90' => 'bg-brand-primary',
    ];

    $columns = [
        ['key' => 'rango', 'label' => __('asesores::resumen.edades.columnas.rango')],
        ['key' => 'saldo', 'label' => __('asesores::resumen.edades.columnas.saldo'), 'align' => 'end'],
        ['key' => 'participacion', 'label' => __('asesores::resumen.edades.columnas.participacion'), 'align' => 'end'],
    ];
@endphp

@if (! $hasMoney)
    <x-ui.empty icon="chart" :title="__('asesores::resumen.edades.vacio')" />
@else
    <div data-part="aging-bars" class="grid gap-4">
        <ul class="@container grid gap-4">
            @foreach ($rows as $row)
                <li class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1.5 @lg:grid-cols-[9.5rem_minmax(0,1fr)_minmax(9rem,auto)]">
                    <span class="text-sm font-semibold text-fg-soft @lg:col-start-1 @lg:row-start-1">{{ __('asesores::comun.edades.'.$row->key) }}</span>
                    <span class="text-end text-sm tabular-nums @lg:col-start-3 @lg:row-start-1">
                        <x-ui.money :value="$row->value" class="font-semibold" />
                        <span class="text-fg-muted">· {{ Percent::format($row->share) }}@if ($row->negative) · {{ __('asesores::resumen.edades.a_favor') }}@endif</span>
                    </span>
                    <span aria-hidden="true" class="col-span-2 h-3 overflow-hidden rounded-full bg-subtle @lg:col-span-1 @lg:col-start-2 @lg:row-start-1">
                        <span class="block h-full rounded-full {{ $row->negative ? 'bg-fg-muted' : $fills[$row->key] }}" style="width: {{ $row->width }}%"></span>
                    </span>
                </li>
            @endforeach
        </ul>

        <details class="text-sm">
            <summary class="inline-flex min-h-touch cursor-pointer items-center font-semibold text-brand-ink underline-offset-4 hover:underline">{{ __('asesores::resumen.edades.tabla') }}</summary>
            <x-ui.table :caption="__('asesores::resumen.edades.tabla_titulo')" :caption-hidden="true" :columns="$columns">
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ __('asesores::comun.edades.'.$row->key) }}</td>
                        <td data-align="end"><x-ui.money :value="$row->value" /></td>
                        <td data-align="end">{{ Percent::format($row->share) }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        </details>
    </div>
@endif
