<?php

namespace App\Modules\Asesores\Application\Export;

use App\Modules\Asesores\Domain\Client\Position;
use App\Modules\Asesores\Infrastructure\Report\PdfRenderer;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\ClientDetail;
use App\Modules\Core\Contracts\Data\ClientQuery;
use Carbon\CarbonImmutable;

/**
 * The account statement of a client as a PDF: what they owe, how old it is
 * and the open documents. It is a document for the client, so it carries no
 * commitments or notes of the log, and it takes its colors and name from the
 * company of the client, not from the company the advisor has active.
 */
final readonly class ClientStatement
{
    public const string VIEW = 'asesores::documentos.estado-cuenta';

    public function __construct(
        private CarteraRepository $cartera,
        private CurrentCompany $companies,
        private PdfRenderer $pdf,
    ) {}

    /**
     * What the view prints.
     *
     * @return array<string, mixed>
     */
    public function data(ClientDetail $detail, CarbonImmutable $generatedAt): array
    {
        $company = $this->companies->of($detail->summary->company);
        $invoices = $this->cartera->openInvoices($detail->summary->id);

        return [
            'company' => $company,
            'palette' => $company->palette,
            'detail' => $detail,
            'position' => Position::of($invoices, $detail->creditLimit),
            'invoices' => $invoices,
            'cutoff' => $this->cartera->totals(new ClientQuery(companies: [$detail->summary->company]))->cutoffDate,
            'generatedAt' => $generatedAt,
        ];
    }

    /**
     * The bytes of the PDF.
     */
    public function pdf(ClientDetail $detail, CarbonImmutable $generatedAt): string
    {
        return $this->pdf->render(self::VIEW, $this->data($detail, $generatedAt));
    }
}
