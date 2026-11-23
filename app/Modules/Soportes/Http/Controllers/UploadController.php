<?php

namespace App\Modules\Soportes\Http\Controllers;

use App\Models\User;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Soportes\Application\Upload\OverdueInvoices;
use App\Modules\Soportes\Application\Upload\UploadReceipt;
use App\Modules\Soportes\Domain\Exceptions\ReceiptRefused;
use App\Modules\Soportes\Domain\PaymentMethod;
use App\Modules\Soportes\Http\Requests\StoreReceiptRequest;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Upload of a payment receipt, in two steps on the same URL: without
 * ?cliente= the user searches their overdue clients; with ?cliente={id} the
 * form shows that client's overdue invoices. The ?cliente= link also lets
 * other screens send the user straight to the form.
 */
final readonly class UploadController
{
    private const int PER_PAGE = 25;

    public function __construct(
        private CarteraRepository $cartera,
        private OverdueInvoices $overdue,
        private UploadReceipt $upload,
    ) {}

    public function create(Request $request, CurrentCompany $current): View
    {
        if ($request->has('cliente')) {
            return $this->form($request->integer('cliente'));
        }

        $search = $request->string('buscar')->trim()->toString();

        return view('soportes::carga.clientes', [
            'clients' => $this->cartera->paginateClients(new ClientQuery(search: $search === '' ? null : $search, onlyOverdue: true), self::PER_PAGE),
            'search' => $search,
            'isGroup' => $current->isGroup(),
        ]);
    }

    public function store(StoreReceiptRequest $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        try {
            $this->upload->handle($request->toNewReceipt(), $user);
        } catch (ReceiptRefused $e) {
            return redirect()->route('soportes.create', ['cliente' => $request->clientId()])
                ->withErrors([$e->field => __('soportes::carga.errores.'.$e->reason)])
                ->withInput($request->except('archivos'));
        }

        return redirect()->route('soportes.create')->with('status', __('soportes::carga.resultado.cargado'));
    }

    /**
     * Step 2. A client the user may not see answers 404, as if it did not
     * exist.
     */
    private function form(int $clientId): View
    {
        $client = $this->cartera->client($clientId);
        abort_if($client === null, 404);

        return view('soportes::carga.formulario', [
            'client' => $client->summary,
            'documents' => $this->overdue->byDocument($clientId),
            'methods' => PaymentMethod::options(),
            'today' => CarbonImmutable::now()->format('Y-m-d'),
        ]);
    }
}
