# Soportes

Inbox of payment receipts (soportes de pago). Clients send receipts by mail or WhatsApp; the advisor or the portfolio team uploads each one against a client and one or more of its overdue invoices; the portfolio analyst applies the payment in SAP and records in the app that it was done, or rejects the receipt with a reason the advisor sees.

The app never marks an invoice as paid and does not change the weekly collection: an invoice only shows as paid once SAP has the payment and the robot brings the next snapshot. The module talks to the rest of the app only through Core's contracts (`docs/arquitectura.md` §6). The feature plan is `odd/tasks/soportes-pago.md`.

This base ships the data, the rules and the permissions. The upload, the inbox, the review actions and the menu entry ("Soportes de pago") come in the next pull requests.

## States

| Status | Value | Chip | Meaning |
|---|---|---|---|
| Pending | `pendiente` | warning | Uploaded, waiting for the analyst. |
| Applied | `aplicado` | success | The payment was applied in SAP; optional cash receipt number (`receipt_number`). |
| Rejected | `rechazado` | danger | Not applied; `rejection_reason` says why and the advisor sees it. |

Only `pendiente` moves, and only to `aplicado` or `rechazado` (`ReceiptStatus::canMoveTo()`; `moveTo()` throws `InvalidReceiptTransition` otherwise). Both are final: a correction is a new receipt. Payment methods (optional): transferencia, consignación, PSE, otro.

## Permissions

`SoportesPermissionSeeder`:

| Permission | asesor | analista_cartera | gerente_cartera | superadmin |
|---|---|---|---|---|
| `soportes.view` | yes | yes | yes | yes |
| `soportes.upload` | yes | yes | yes | excluded |
| `soportes.review` | no | yes | yes | excluded |

`admin`, `gerente_comercial` and `tesoreria` get none. The superadmin sees the inbox but neither uploads nor reviews (`superAdminExcludes()`): whoever collects uploads and whoever applies the payment in SAP reviews, as with Cartera's commitments.

`ReceiptPolicy` (registered in `SoportesServiceProvider::bootModule()`):

- `viewAny`: `soportes.view`. `create`: `soportes.upload`.
- `view`, for a reviewer (`soportes.review`): the receipt's company is in `CurrentCompany::available()`.
- `view`, for everyone else (the advisor): they uploaded the receipt, or its client is one of their own clients, i.e. `CarteraRepository::client($receipt->client_id)` finds it. The repository applies the advisor scope, so ownership is read live: a client reassigned to another advisor moves its receipts with it, and the advisor still sees the ones they uploaded.
- `review`: `soportes.review`, the receipt's company is available and the receipt is still `pendiente`.

## Data

Migrations `2026_10_09_*`:

- `soportes`: the receipt. `company`, `client_id`, `client_code`, `client_name` and `advisor_code` are copies taken at upload, so the inbox lists receipts from its own rows and never depends on the current snapshot or on the company the user has open. `client_id` is Core's client id, without a foreign key (modules never constrain each other's tables); it is read live only for the advisor's `view` rule. `uploaded_by` restricts deletes (users are soft-deleted, so who uploaded is never lost); `reviewed_by` is set to null if the user row is ever removed.
- `soporte_facturas`: the overdue invoices the receipt covers, copied at upload (document number and type, due date, balance, days overdue). Core's invoice id changes with every snapshot, so it is never stored.
- `soporte_archivos`: the files (PDF, JPG or PNG; 1 to 3 per receipt, 10 MB each), with the sniffed MIME type and size in bytes.

Deleting a receipt deletes its invoice and file rows.

## Upload

`Application\Upload\UploadReceipt::handle(NewReceipt, User)` creates a `pendiente` receipt. It runs as the authenticated user, because the portfolio scope comes from them:

- the client must be found by `CarteraRepository::client()` (an advisor finds only their own clients; the portfolio team, any client of the companies in scope); otherwise `ReceiptRefused` on `cliente`;
- every document number must be one of the client's overdue invoices (`OverdueInvoices`: `openInvoices()` with `daysOverdue > 0` and `balance > 0`, so credit notes are left out); otherwise `ReceiptRefused` on `facturas`. Invoices are matched by document number, never by `Invoice::$id`, which changes with every snapshot; a document with split payment terms copies every overdue installment;
- every file's sniffed content type must be PDF, JPEG or PNG; otherwise `ReceiptRefused` on `archivos`.

The files are written first, then the receipt, its invoices and files are saved in one transaction with the audit entry `soportes.receipt.uploaded` (subject `soporte`; company, client id, amount, payment date, method and status; context: how many invoices and files). If anything fails, the files already written are deleted. No e-mail is sent.

## Storage

Files live on the private `local` disk (`storage/app/private`), never under `public/`, at `soportes/{company}/{yyyy}/{mm}/{uuid}.{ext}` under a generated name; the uploaded name is only shown. They are served only by the authorized download action. Settings in `config/soportes.php`, read with `config('soportes.<key>')`:

| Key | Default | Purpose |
|---|---|---|
| `disk` | `local` | Disk of the files. |
| `directory` | `soportes` | Folder on that disk. |
| `max_files` | `3` | Files per receipt. |
| `max_file_kilobytes` | `10240` | Largest file, in KB. |
| `allowed_extensions` | `pdf`, `jpg`, `jpeg`, `png` | Accepted extensions. |
| `allowed_mimetypes` | `application/pdf`, `image/jpeg`, `image/png` | Accepted content types, sniffed from the bytes. |
