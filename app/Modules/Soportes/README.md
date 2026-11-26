# Soportes

Inbox of payment receipts (soportes de pago). Clients send receipts by mail or WhatsApp; the advisor or the portfolio team uploads each one against a client and one or more of its open invoices, overdue or not; the portfolio analyst applies the payment in SAP and records in the app that it was done, or rejects the receipt with a reason the advisor sees.

The app never marks an invoice as paid and does not change the weekly collection: an invoice only shows as paid once SAP has the payment and the robot brings the next snapshot. The module talks to the rest of the app only through Core's contracts (`docs/arquitectura.md` §6). The feature plan is `odd/tasks/soportes-pago.md`.

The module ships the data, the rules, the permissions, the upload, the inbox with its menu entry ("Soportes de pago"), the detail with its file downloads and in-browser view, and the review (apply or reject).

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
- `soporte_facturas`: the open invoices the receipt covers, copied at upload (document number and type, due date, balance, days overdue). Core's invoice id changes with every snapshot, so it is never stored.
- `soporte_archivos`: the files (PDF, JPG or PNG; 1 to 3 per receipt, 10 MB each), with the sniffed MIME type and size in bytes.

Deleting a receipt deletes its invoice and file rows.

## Upload

`Application\Upload\UploadReceipt::handle(NewReceipt, User)` creates a `pendiente` receipt. It runs as the authenticated user, because the portfolio scope comes from them:

- the client must be found by `CarteraRepository::client()` (an advisor finds only their own clients; the portfolio team, any client of the companies in scope); otherwise `ReceiptRefused` on `cliente`;
- every document number must be one of the client's open invoices (`OpenInvoices`: `openInvoices()` with `balance > 0`, so credit notes are left out; overdue or not, user decision 2026-11-26); otherwise `ReceiptRefused` on `facturas`. Invoices are matched by document number, never by `Invoice::$id`, which changes with every snapshot; a document with split payment terms copies every open installment;
- every file's sniffed content type must be PDF, JPEG or PNG; otherwise `ReceiptRefused` on `archivos`.

The files are written first, then the receipt, its invoices and files are saved in one transaction with the audit entry `soportes.receipt.uploaded` (subject `soporte`; company, client id, amount, payment date, method and status; context: how many invoices and files). If anything fails, the files already written are deleted. No e-mail is sent.

### Screens

Routes in `routes/web.php` (`auth` and `can:create` on `Receipt`, i.e. `soportes.upload`):

| Route | Name | What |
|---|---|---|
| `GET /soportes/nuevo` | `soportes.create` | Step 1: search the clients the user may upload for (`paginateClients`, by name or NIT), with their balance and days overdue («Al día» when none). |
| `GET /soportes/nuevo?cliente={id}` | `soportes.create` | Step 2: the form for that client (404 when `client()` does not find it): its open invoices as checkboxes, overdue or not («Al día» in the days column), amount, payment date, method, note and files. Other screens can link here. |
| `POST /soportes` | `soportes.store` | `StoreReceiptRequest`, then `UploadReceipt`. Errors and refusals go back to step 2 with the input; success opens the new receipt (`soportes.show`) with «Soporte cargado. Queda pendiente de revisión.». |

`StoreReceiptRequest` fields: `cliente`; `facturas[]` (document numbers, at least one, distinct); `valor` (pesos, digits with up to two decimals, above zero; the form shows it in an `<x-ui.money-input :decimals="2">`, which formats 1.234.567,50 while typing and sends the clean number, so the rule is unchanged); `fecha_pago` (`Y-m-d`, not after today); `metodo` (optional, `PaymentMethod`); `nota` (optional, up to 1,000 characters); `archivos[]` (1 to `max_files`, each `file`, `extensions` and `mimetypes` from the config, which Laravel checks against the sniffed content type, up to `max_file_kilobytes`). Texts in `resources/lang/es/carga.php`.

## Inbox

`GET /soportes` (`soportes.index`, `can:viewAny` on `Receipt`, i.e. `soportes.view`), `InboxController`. Tabs Pendientes, Aplicados and Rechazados (`?estado=`, pending by default) with their counts; filters by client name or code (`?buscar=`, on the copies taken at upload) and by payment date (`?desde=` and `?hasta=`, inclusive), read by `Application\Inbox\InboxFilter`, which drops values it does not know. Pending receipts come oldest first, as a queue; applied and rejected ones, latest review first; 25 per page. The Rechazados tab shows the reason. «Cargar soporte» links to the upload for whoever has `soportes.upload`.

`Application\Inbox\VisibleReceipts` decides which receipts the list holds, by the same rule as `ReceiptPolicy::view`:

- reviewers (`soportes.review`): the receipts of the companies of `CurrentCompany::scope()`, so the inbox follows the company switcher; the group view adds the company column;
- everyone else (the advisor; also the superadmin, who has `soportes.view` but not `soportes.review`): the receipts they uploaded, plus the ones of their own clients, whoever uploaded them. Ownership is read live: `CarteraRepository::allClients()` is walked once (it applies the advisor scope and the companies in view, like `client()` in the policy) and only the clients that have receipts are kept, so the query is `uploaded_by = user OR client_id IN (...)` with a short list, never one `client()` call per row.

The page is titled «Soportes de pago» for whoever reaches every client of the companies in view: reviewers and the superadmin (whose portfolio scope covers every client, so "the ones of their own clients" is all of them). The advisor gets «Mis soportes».

### Menu

`SoportesServiceProvider::navigation()` adds «Soportes de pago» (`soportes.bandeja`, permission `soportes.view`) at the end of the Cartera group (order 45), so `/` still opens the dashboard of the portfolio roles. Its badge counts the pending receipts of the companies in view, only for reviewers (`VisibleReceipts::pendingCount()`); the advisor gets no badge, and nobody gets one when nothing is pending.

## Detail and downloads

Every route here sits behind `can:view,soporte` (`ReceiptPolicy::view`), so a user who may not open the receipt gets 403 and an unknown id 404. `ReceiptController`:

| Route | Name | What |
|---|---|---|
| `GET /soportes/{soporte}` | `soportes.show` | The payment data (client, company, amount, payment date, method, note, who uploaded it and when), the review (status, who reviewed it and when, the cash receipt number or the rejection reason), the invoices it covers (the copies taken at upload) and its files. The inbox rows link here. |
| `GET /soportes/{soporte}/archivos/{archivo}` | `soportes.files.download` | The file, streamed from `config('soportes.disk')` as an attachment named as it was uploaded (path separators replaced), with its stored sniffed type and `X-Content-Type-Options: nosniff`. A file of another receipt answers 404; a file missing on the disk answers 404 and logs a warning. 60 downloads a minute. Never a public URL. |
| `GET /soportes/{soporte}/archivos/{archivo}/ver` | `soportes.files.show` | The same file for the browser to show: `Content-Disposition: inline`, the stored type, `nosniff`, `Cross-Origin-Resource-Policy: same-origin` and `Cache-Control: private, no-store`. Only for `ReceiptController::INLINE` (PDF, JPEG, PNG); any other stored type answers 404, so SVG or HTML never render inline. Same 404s and limit as the download. |

In the detail, a JPG or PNG is shown in the page (`<img>`) and a PDF opens in another tab («Ver PDF»), each next to «Descargar». A PDF is never framed: every response carries `X-Frame-Options: DENY` (`public/.htaccess`) and `frame-ancestors 'none'` (Core's CSP middleware), and `object-src 'none'` rules out `<object>` (user decision 2026-11-26: another tab). On a phone the PDF opens in the phone's own viewer.

## Review

`PATCH /soportes/{soporte}/aplicar` (`soportes.apply`) and `PATCH /soportes/{soporte}/rechazar` (`soportes.reject`), `ReviewController`, forms in the detail's "Revisión de cartera" card, shown only while `ReceiptPolicy::review` allows it. 30 decisions a minute.

- Who: the route checks `ReceiptPolicy::view`; `ApplyReceiptRequest` and `RejectReceiptRequest` answer 403 to anyone without `soportes.review` (the advisor, treasury, the superadmin) before validating; the controller then checks `ReceiptPolicy::review` (company available, still pending). A receipt already applied or rejected goes back to the detail with «Este soporte ya fue revisado, así que no se cambió nada.» instead of 403, so a late reviewer knows what happened.
- Fields: apply takes the optional cash receipt number (`recibo`, up to 60 characters); reject requires the reason (`motivo`, up to 500 characters), which the advisor sees in the Rechazados tab and on the detail. Errors go back to the detail.
- `Application\Review\ReviewReceipt` moves the status with `ReceiptStatus::moveTo()`, then updates the row only `WHERE id = ? AND status = 'pendiente'`, setting `reviewed_by` and `reviewed_at`, in one transaction with the audit entry `soportes.receipt.applied` or `soportes.receipt.rejected` (subject `soporte`; changes: `status` and `receipt_number` or `rejection_reason`, old and new; context: company and client id). When two reviewers decide at once, the second update matches no row and throws `InvalidReceiptTransition`; the controller turns it into the same message, and only the first decision and its audit entry stay.
- Nothing else changes: no invoice is marked paid, no e-mail is sent, the weekly collection is untouched. The menu badge drops as receipts leave pending.

## Storage

Files live on the private `local` disk (`storage/app/private`), never under `public/`, at `soportes/{company}/{yyyy}/{mm}/{uuid}.{ext}` under a generated name; the uploaded name is only shown, and given back as the download name. They are served only by the authorized download and view actions (see «Detail and downloads»). Settings in `config/soportes.php`, read with `config('soportes.<key>')`:

| Key | Default | Purpose |
|---|---|---|
| `disk` | `local` | Disk of the files. |
| `directory` | `soportes` | Folder on that disk. |
| `max_files` | `3` | Files per receipt. |
| `max_file_kilobytes` | `10240` | Largest file, in KB. |
| `allowed_extensions` | `pdf`, `jpg`, `jpeg`, `png` | Accepted extensions. |
| `allowed_mimetypes` | `application/pdf`, `image/jpeg`, `image/png` | Accepted content types, sniffed from the bytes. |
