<?php

namespace App\Modules\Soportes\Application\Upload;

use App\Models\User;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\Data\ClientDetail;
use App\Modules\Core\Contracts\Data\Invoice;
use App\Modules\Soportes\Domain\Exceptions\ReceiptRefused;
use App\Modules\Soportes\Domain\ReceiptStatus;
use App\Modules\Soportes\Infrastructure\Files\ReceiptStorage;
use App\Modules\Soportes\Infrastructure\Models\Receipt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Uploads a payment receipt: a pending receipt of one client covering one
 * or more of its overdue invoices, with its files.
 *
 * It runs as the authenticated user, because the portfolio scope comes from
 * them: CarteraRepository::client() must find the client (an advisor only
 * finds their own clients; the portfolio team, any client of the companies
 * in scope) and every document number must be one of its overdue invoices.
 * The client and invoice fields are copied, so the receipt never depends on
 * a later snapshot. The app never marks an invoice as paid and sends no
 * e-mail.
 *
 * The files are written first; when the database work fails they are
 * deleted again, so no file is left without its receipt.
 */
final readonly class UploadReceipt
{
    public function __construct(
        private CarteraRepository $cartera,
        private OverdueInvoices $overdue,
        private ReceiptStorage $storage,
        private AuditLogger $audit,
    ) {}

    /**
     * @throws ReceiptRefused When the client is out of scope, a document is not an overdue invoice
     *                        of the client, or a file is not really a PDF, JPG or PNG.
     */
    public function handle(NewReceipt $input, User $uploader): Receipt
    {
        $client = $this->cartera->client($input->clientId) ?? throw ReceiptRefused::clientNotFound($input->clientId);
        $invoices = $this->invoices($input);

        foreach ($input->files as $file) {
            $this->storage->acceptedType($file);
        }

        $paths = [];

        try {
            $files = [];

            foreach ($input->files as $file) {
                $paths[] = $path = $this->storage->store($file, $client->summary->company, CarbonImmutable::now());
                $files[] = [
                    'path' => $path,
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'mime' => $this->storage->acceptedType($file),
                    'size' => (int) $file->getSize(),
                ];
            }

            return DB::transaction(fn (): Receipt => $this->save($input, $client, $invoices, $files, $uploader));
        } catch (Throwable $e) {
            $this->storage->delete($paths);

            throw $e;
        }
    }

    /**
     * The overdue invoices of the selected documents, every installment of
     * each one.
     *
     * @return list<Invoice>
     *
     * @throws ReceiptRefused When a document is not an overdue invoice of the client.
     */
    private function invoices(NewReceipt $input): array
    {
        $documents = [];

        foreach ($this->overdue->byDocument($input->clientId) as $installments) {
            $documents[$installments[0]->documentNumber] = $installments;
        }

        $missing = array_values(array_filter(
            array_unique($input->documentNumbers),
            fn (string $number): bool => ! isset($documents[$number]),
        ));

        if ($missing !== []) {
            throw ReceiptRefused::invoicesNotOverdue($input->clientId, $missing);
        }

        $invoices = [];

        foreach (array_unique($input->documentNumbers) as $number) {
            $invoices = [...$invoices, ...$documents[$number]];
        }

        return $invoices;
    }

    /**
     * @param  list<Invoice>  $invoices
     * @param  list<array{path: string, original_name: string, mime: string, size: int}>  $files
     */
    private function save(NewReceipt $input, ClientDetail $client, array $invoices, array $files, User $uploader): Receipt
    {
        $summary = $client->summary;

        $receipt = Receipt::query()->create([
            'company' => $summary->company,
            'client_id' => $summary->id,
            'client_code' => $summary->code,
            'client_name' => $summary->name,
            'advisor_code' => $summary->advisor?->code,
            'amount' => $input->amount,
            'paid_at' => $input->paidAt->format('Y-m-d'),
            'method' => $input->method,
            'note' => $input->note,
            'status' => ReceiptStatus::Pending,
            'uploaded_by' => $uploader->getKey(),
        ]);

        foreach ($invoices as $invoice) {
            $receipt->invoices()->create([
                'document_number' => $invoice->documentNumber,
                'document_type' => $invoice->documentType,
                'due_at' => $invoice->dueAt->format('Y-m-d'),
                'balance' => number_format($invoice->balance, 2, '.', ''),
                'days_overdue' => $invoice->daysOverdue,
            ]);
        }

        $receipt->files()->createMany($files);

        $this->audit->record('soportes.receipt.uploaded', 'soporte', $receipt->id, [
            'company' => $summary->company->value,
            'client_id' => $summary->id,
            'amount' => $receipt->amount,
            'paid_at' => $input->paidAt->format('Y-m-d'),
            'method' => $input->method?->value,
            'status' => ReceiptStatus::Pending->value,
        ], [
            'invoices' => count(array_unique($input->documentNumbers)),
            'files' => count($files),
        ]);

        return $receipt;
    }
}
