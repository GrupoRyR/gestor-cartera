@use('Illuminate\Contracts\Pagination\LengthAwarePaginator')
@use('Illuminate\Contracts\Pagination\Paginator')
@use('Illuminate\Support\Arr')

{{--
    Previous / next pagination (addition to C.5). Modules use it instead of
    ->links(). Renders nothing when everything fits in one page; a
    length-aware paginator also shows "Página X de Y". The links keep the
    current query string (search, filters, sort) except the page parameter.
--}}
@props(['paginator', 'label' => 'Paginación'])

@php
    if (! $paginator instanceof Paginator) {
        throw new InvalidArgumentException('The pagination component needs an '.Paginator::class.'.');
    }

    $pageName = method_exists($paginator, 'getPageName') ? $paginator->getPageName() : 'page';
    $paginator->appends(Arr::except(request()->query(), [$pageName]));
@endphp

@if ($paginator->hasPages())
    <nav {{ $attributes->merge(['aria-label' => $label]) }}>
        @if ($paginator->onFirstPage())
            <span data-part="previous" data-disabled>Anterior</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" data-part="previous">Anterior</a>
        @endif

        @if ($paginator instanceof LengthAwarePaginator)
            <p data-part="status">Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</p>
        @endif

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" data-part="next">Siguiente</a>
        @else
            <span data-part="next" data-disabled>Siguiente</span>
        @endif
    </nav>
@endif
