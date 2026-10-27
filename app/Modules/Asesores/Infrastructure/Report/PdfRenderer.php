<?php

namespace App\Modules\Asesores\Infrastructure\Report;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Draws a Blade view as an A4 PDF with dompdf, which needs no system
 * dependency, so it runs on the shared hosting.
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
            ->setPaper('a4', 'portrait')
            // Nothing in the view may call out, read a local file (the views
            // load none; dompdf always reaches its own DejaVu Sans under its
            // root) nor leave a script in the PDF for the reader to run.
            ->setOption('isRemoteEnabled', false)
            ->setOption('chroot', [base_path('vendor/dompdf/dompdf/lib/fonts')])
            ->setOption('isJavascriptEnabled', false)
            ->output();
    }
}
