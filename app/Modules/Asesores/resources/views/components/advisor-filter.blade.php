@use('App\Modules\Asesores\Domain\Portfolio\AdvisorKey')

{{--
    Picks the advisor whose portfolio a screen shows. It appears only for who
    can look at more than one advisor (a sales manager, or an advisor with a
    SAP code in each company); everybody else has nothing to choose. $advisors
    comes from CarteraRepository::advisors(), so it never lists a stranger.
    The other filters of the screen travel along as hidden fields; $extra adds
    more (the history range, for one).
--}}
@props(['advisors', 'filter', 'action', 'extra' => []])

@if (count($advisors) > 1)
    @php
        $options = [];

        foreach ($advisors as $advisor) {
            $options[AdvisorKey::of($advisor)->value()] = __('asesores::comun.asesor.con_empresa', ['nombre' => $advisor->name, 'empresa' => $advisor->company->value]);
        }
    @endphp

    <form method="GET" action="{{ $action }}" data-part="advisor-filter" class="flex flex-wrap items-end gap-3">
        @foreach ([...$filter->params(['asesor' => null]), ...$extra] as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach

        <div class="min-w-[min(14rem,100%)] flex-1 sm:max-w-sm">
            <x-ui.select name="asesor" :label="__('asesores::comun.asesor.etiqueta')" :options="$options" :value="$filter->advisor?->value()" :placeholder="__('asesores::comun.asesor.todos')" />
        </div>
        <x-ui.button type="submit" variant="secondary">{{ __('asesores::comun.asesor.ver') }}</x-ui.button>
    </form>
@endif
