<?php

namespace App\Modules\Asesores\Http\Controllers;

use App\Modules\Asesores\Application\Export\ClientStatement;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\CarteraRepository;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * GET /asesores/clientes/{client}/estado-de-cuenta: the PDF account statement
 * of a client. The client is found through the portfolio first, so one
 * outside the user's scope is a 404.
 */
final readonly class StatementController
{
    public function __construct(
        private CarteraRepository $cartera,
        private ClientStatement $statement,
        private AuditLogger $audit,
    ) {}

    public function __invoke(int $client): Response
    {
        $detail = $this->cartera->client($client) ?? abort(404);

        Gate::authorize('view', $detail);

        $now = CarbonImmutable::now();
        $pdf = $this->statement->pdf($detail, $now);

        $this->audit->record('asesores.client.statement_downloaded', 'client', $client);

        $code = (string) preg_replace('/[^A-Za-z0-9_-]+/', '', $detail->summary->code);
        $name = __('asesores::exportar.archivo.estado_cuenta', ['codigo' => $code]).'-'.$now->format('Y-m-d').'.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }
}
