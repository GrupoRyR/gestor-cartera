<?php

namespace App\Modules\Comisiones\Infrastructure\Report;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Draws a Blade view as a letter landscape PDF with dompdf (§8.1, §8.2),
 * which needs no system dependency, so it runs on the shared hosting.
 */
final class PdfRenderer
{
    /**
     * @param  array<string, mixed>  $data  What the view needs.
     * @return string The bytes of the PDF.
     */
    public function render(string $view, array $data): string
    {
        return Pdf::loadView($view, $data)
            ->setPaper('letter', 'landscape')
            // dompdf only reads local files: nothing in the view may call out.
            ->setOption('isRemoteEnabled', false)
            ->output();
    }
}
