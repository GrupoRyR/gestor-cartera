@use('App\View\Components\Support\ButtonStyle')
@use('Illuminate\Contracts\Pagination\LengthAwarePaginator')
@use('Illuminate\Contracts\Pagination\Paginator')
@use('Illuminate\Support\Arr')

{{--
    Previous / next pagination (addition to C.5). Modules use it instead of
    ->links(). Renders nothing when everything fits in one page; a
    length-aware paginator also shows "Página X de Y". The links keep the
    current query string (search, filters, sort) except the page parameter.
    On the first or last page the missing link stays in place as a disabled
    link (role="link" aria-disabled="true"), so assistive tech and contrast
    checkers treat it as an inactive control.
--}}
@props(['paginator', 'label' => 'Paginación'])

@php
    if (! $paginator instanceof Paginator) {
        throw new InvalidArgumentException('The pagination component needs an '.Paginator::class.'.');
    }

    $pageName = method_exists($paginator, 'getPageName') ? $paginator->getPageName() : 'page';
    $paginator->appends(Arr::except(request()->query(), [$pageName]));

    $link = ButtonStyle::classes('secondary');
    $disabled = $link.' pointer-events-none opacity-45';
@endphp

@if ($paginator->hasPages())
    <nav {{ $attributes->merge(['aria-label' => $label])->class('flex flex-wrap items-center justify-between gap-3 pt-4') }}>
        @if ($paginator->onFirstPage())
            <span role="link" aria-disabled="true" data-part="previous" data-disabled class="{{ $disabled }}"><x-ui.icon name="chevron-left" class="-ms-1 size-[1.125rem]" />Anterior</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" data-part="previous" class="{{ $link }}"><x-ui.icon name="chevron-left" class="-ms-1 size-[1.125rem]" />Anterior</a>
        @endif

        @if ($paginator instanceof LengthAwarePaginator)
            <p data-part="status" class="text-sm font-medium text-fg-muted tabular-nums max-sm:order-first max-sm:w-full max-sm:text-center">Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</p>
        @endif

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" data-part="next" class="{{ $link }}">Siguiente<x-ui.icon name="chevron-right" class="-me-1 size-[1.125rem]" /></a>
        @else
            <span role="link" aria-disabled="true" data-part="next" data-disabled class="{{ $disabled }}">Siguiente<x-ui.icon name="chevron-right" class="-me-1 size-[1.125rem]" /></span>
        @endif
    </nav>
@endif
