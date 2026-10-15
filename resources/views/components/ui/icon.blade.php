{{--
    Icon placeholder (addition to C.5). Phase 0b draws the icon set and keys
    it on data-icon. Without a label the icon is decorative and hidden from
    assistive technology; with one it is announced as an image.
--}}
@props(['name', 'label' => null])

<span {{ $attributes->merge([
    'data-icon' => $name,
    'role' => filled($label) ? 'img' : null,
    'aria-label' => filled($label) ? $label : null,
    'aria-hidden' => filled($label) ? null : 'true',
]) }}></span>
