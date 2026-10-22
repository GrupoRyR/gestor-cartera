<?php

namespace App\Modules\Comisiones\Infrastructure\Report;

use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;

/**
 * The consolidated report as a letter landscape PDF (§8.1) with the Segurex
 * brand: the file GET …/consolidado.pdf downloads and the approval mail
 * attaches, so both always carry the same bytes for a version.
 */
final readonly class ConsolidatedPdf
{
    public function __construct(
        private PdfRenderer $renderer,
        private CurrentCompany $companies,
    ) {}

    /**
     * @param  string|null  $generatedBy  Name printed as who generated it; null prints none.
     * @return string The bytes of the PDF, named by ConsolidatedReport::fileName('pdf').
     */
    public function render(ConsolidatedReport $report, ?string $generatedBy): string
    {
        return $this->renderer->render('comisiones::pdf.consolidado', [
            'report' => $report,
            'palette' => $this->companies->of(CompanyCode::SGX)->palette,
            'generatedAt' => CarbonImmutable::now(),
            'generatedBy' => $generatedBy,
        ]);
    }
}
