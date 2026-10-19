<?php

namespace App\Modules\Asesores\Application\Export;

use App\Modules\Asesores\Domain\Client\InvoiceAging;
use App\Modules\Asesores\Infrastructure\Export\Column;
use App\Modules\Asesores\Infrastructure\Export\XlsxFile;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\Data\ClientDetail;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Data\ClientSummary;
use App\Modules\Core\Contracts\Data\Invoice;
use Illuminate\Support\Facades\Lang;

/**
 * The Excel of a portfolio: one sheet with the clients and one with their open
 * documents. Everything is read through CarteraRepository, so the file holds
 * what the signed-in user may see and nothing else: there is no other way
 * into the data from here.
 */
final readonly class PortfolioWorkbook
{
    public function __construct(private CarteraRepository $cartera) {}

    /**
     * The clients that match $query, with their documents.
     */
    public function ofClients(ClientQuery $query): Workbook
    {
        $clients = [];

        foreach ($this->cartera->allClients($query) as $client) {
            $clients[] = $client;
        }

        return $this->write($clients);
    }

    /**
     * One client, with their documents.
     */
    public function ofClient(ClientDetail $detail): Workbook
    {
        return $this->write([$detail->summary]);
    }

    /**
     * @param  list<ClientSummary>  $clients
     */
    private function write(array $clients): Workbook
    {
        $file = XlsxFile::create();

        $file->sheet(__('asesores::exportar.hojas.clientes'), [
            Column::text(__('asesores::exportar.clientes.codigo'), 14),
            Column::text(__('asesores::exportar.clientes.cliente'), 42),
            Column::text(__('asesores::exportar.clientes.nit'), 16),
            Column::text(__('asesores::exportar.clientes.empresa'), 22),
            Column::text(__('asesores::exportar.clientes.asesor'), 28),
            Column::text(__('asesores::exportar.clientes.estado'), 16),
            Column::money(__('asesores::exportar.clientes.saldo')),
            Column::money(__('asesores::exportar.clientes.vencido')),
            Column::number(__('asesores::exportar.clientes.dias')),
            Column::date(__('asesores::exportar.clientes.gestion')),
        ]);

        foreach ($clients as $client) {
            $file->row([
                $client->code,
                $client->name,
                $client->taxId,
                $client->company->label(),
                $client->advisor?->name,
                $client->status->label(),
                $client->balance,
                $client->overdue,
                $client->maxDaysOverdue,
                $client->lastActivityAt,
            ]);
        }

        $file->sheet(__('asesores::exportar.hojas.facturas'), [
            Column::text(__('asesores::exportar.facturas.codigo'), 14),
            Column::text(__('asesores::exportar.facturas.cliente'), 42),
            Column::text(__('asesores::exportar.facturas.nit'), 16),
            Column::text(__('asesores::exportar.facturas.asesor'), 28),
            Column::text(__('asesores::exportar.facturas.documento'), 16),
            Column::text(__('asesores::exportar.facturas.tipo'), 16),
            Column::date(__('asesores::exportar.facturas.emision')),
            Column::date(__('asesores::exportar.facturas.vencimiento')),
            Column::number(__('asesores::exportar.facturas.dias'), 16),
            Column::money(__('asesores::exportar.facturas.valor')),
            Column::money(__('asesores::exportar.facturas.saldo')),
            Column::text(__('asesores::exportar.facturas.edad'), 18),
        ]);

        $limit = (int) config('asesores.export.max_clients_with_invoices');
        $rows = 0;

        foreach (array_slice($clients, 0, $limit) as $client) {
            foreach ($this->cartera->openInvoices($client->id) as $invoice) {
                $file->row($this->invoiceRow($client, $invoice));
                $rows++;
            }
        }

        $truncated = count($clients) > $limit;

        if ($truncated) {
            $file->row([__('asesores::exportar.facturas.recortado', ['limite' => $limit])]);
        }

        return new Workbook($file->finish(), count($clients), $rows, $truncated);
    }

    /**
     * @return list<string|int|float|\DateTimeInterface|null>
     */
    private function invoiceRow(ClientSummary $client, Invoice $invoice): array
    {
        $typeKey = 'asesores::ficha.facturas.tipos.'.$invoice->documentType;

        return [
            $client->code,
            $client->name,
            $client->taxId,
            $client->advisor?->name,
            $invoice->documentNumber,
            Lang::has($typeKey) ? __($typeKey) : $invoice->documentType,
            $invoice->issuedAt,
            $invoice->dueAt,
            $invoice->daysOverdue,
            $invoice->amount,
            $invoice->balance,
            __('asesores::comun.edades.'.InvoiceAging::key($invoice->daysOverdue)),
        ];
    }
}
