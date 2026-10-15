@use('App\View\Components\Support\IconSet')

{{--
    Icon (addition to C.5), drawn from App\View\Components\Support\IconSet
    and keyed on data-icon. It takes the color of the surrounding text and
    measures 1.25rem unless a base size class (size-4, size-6…) is passed;
    a responsive one alone (md:size-6) keeps the default below it.
    Without a label the icon is decorative and hidden from assistive
    technology; with one it is announced as an image.
--}}
@props(['name', 'label' => null])

<span {{ $attributes->merge([
    'data-icon' => $name,
    'role' => filled($label) ? 'img' : null,
    'aria-label' => filled($label) ? $label : null,
    'aria-hidden' => filled($label) ? null : 'true',
])->class(['inline-block shrink-0 align-middle', 'size-5' => preg_match('/(^|\s)size-/', (string) $attributes->get('class')) !== 1]) }}>{!! IconSet::svg($name) !!}</span>@php /* Ending on a PHP tag drops the final newline: no stray space before "." or "," after the component. */ @endphp
