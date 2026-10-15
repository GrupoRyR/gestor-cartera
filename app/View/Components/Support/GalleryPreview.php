<?php

namespace App\View\Components\Support;

/**
 * Marks a request as a preview of the component gallery (/dev/componentes,
 * local only).
 *
 * The gallery binds an instance in the container before it renders, so
 * <x-layouts.app> shows its company selector even while Core has no
 * core.empresa.cambiar route, and the selector reloads the gallery with
 * ?empresa=SGX|SDC|grupo (GET) instead of switching the real session. No
 * other page binds it.
 */
final readonly class GalleryPreview
{
    /**
     * @param  string  $url  Where the company selector sends its GET request.
     */
    public function __construct(public string $url) {}
}
