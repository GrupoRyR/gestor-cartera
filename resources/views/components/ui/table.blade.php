{{--
    Data table (C.5) inside a focusable scroll region named by its caption.

    Columns: ['key' => string, 'label' => string, 'sortable' => bool,
    'align' => 'start'|'center'|'end']. Sortable headers link to the current
    URL with ?sort={key}&direction={asc|desc} (the names ClientQuery and
    CommitmentQuery use) and drop ?page. Default slot: body rows (<tr>);
    without rows, one row shows emptyMessage. Slot "footer": <tfoot> rows.
    Extra attributes go to <table>.
--}}
@props([
    'caption',
    'captionHidden' => false,
    'columns' => [],
    'sortBy' => null,
    'sortDirection' => 'asc',
    'emptyMessage' => 'No hay registros para mostrar.',
])

@php
    if (! in_array($sortDirection, ['asc', 'desc'], true)) {
        throw new InvalidArgumentException("Unknown sort direction [{$sortDirection}]; use asc or desc.");
    }

    $headers = [];

    foreach ($columns as $column) {
        if (! isset($column['key'], $column['label'])) {
            throw new InvalidArgumentException('Every table column needs a key and a label.');
        }

        $align = $column['align'] ?? 'start';

        if (! in_array($align, ['start', 'center', 'end'], true)) {
            throw new InvalidArgumentException("Unknown column alignment [{$align}]; use start, center or end.");
        }

        $sortable = (bool) ($column['sortable'] ?? false);
        $active = $sortable && $sortBy === $column['key'];
        // A click on the active column flips its direction; any other column starts ascending.
        $next = $active && $sortDirection === 'asc' ? 'desc' : 'asc';

        $headers[] = [
            'label' => $column['label'],
            'align' => $align,
            'ariaSort' => $active ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : null,
            'icon' => $active ? "sort-{$sortDirection}" : 'sort',
            'href' => $sortable
                ? request()->fullUrlWithQuery(['sort' => $column['key'], 'direction' => $next, 'page' => null])
                : null,
            'hint' => $next === 'asc' ? 'ascendente' : 'descendente',
        ];
    }
@endphp

<div role="region" aria-label="{{ $caption }}" tabindex="0" data-part="table-scroll">
    <table {{ $attributes }}>
        <caption @if ($captionHidden) data-visually-hidden @endif>{{ $caption }}</caption>

        @if ($headers !== [])
            <thead>
                <tr>
                    @foreach ($headers as $header)
                        <th scope="col" data-align="{{ $header['align'] }}" @if ($header['ariaSort']) aria-sort="{{ $header['ariaSort'] }}" @endif>
                            @if ($header['href'] !== null)
                                <a href="{{ $header['href'] }}"><span data-visually-hidden>Ordenar por </span>{{ $header['label'] }}<span data-visually-hidden>, {{ $header['hint'] }}</span><x-ui.icon :name="$header['icon']" /></a>
                            @else
                                {{ $header['label'] }}
                            @endif
                        </th>
                    @endforeach
                </tr>
            </thead>
        @endif

        <tbody>
            @if ($slot->hasActualContent())
                {{ $slot }}
            @else
                <tr data-part="empty">
                    <td colspan="{{ max(count($headers), 1) }}">{{ $emptyMessage }}</td>
                </tr>
            @endif
        </tbody>

        @isset($footer)
            <tfoot>{{ $footer }}</tfoot>
        @endisset
    </table>
</div>
