# Bancos

Bank movements and collections (Part B, module 5 of `docs/prompt-gestor-cartera.md`), **without the reconciliation** (5.8, waiting for the file from the user: Part D, item 2).

Treasury downloads the statements of the bank accounts; the debits are theirs and the **credits** are Cartera's. This module reads the statements, keeps the credits once, lets the analyst note the cash receipt number once the payment is applied in SAP, and reports what came in per day and per week.

```
folder (every morning) ─┐
upload in the app ──────┼─▶ TabularFile ─▶ StatementReader (one per bank) ─▶ StatementReading ─┐
                        │                                                                      ├─▶ MovementImporter ─▶ bancos_movimientos
Treasury API (POST) ────┴────────────────── already normalized (StatementMovement) ────────────┘        (credits only, no duplicates)
                                                                                                              │
                                              RecaudoProvider ◀── EloquentRecaudoProvider ◀───────────────────┘
                                                   │
                         report /bancos/recaudo ◀──┴──▶ GET /api/recaudo (Treasury's cash flow)
```

## Layout

| Where | What |
|---|---|
| `Domain/Statement` | `StatementReader` (the interface), `TabularStatementReader` (the shared logic), `Banks/*` (one class per bank, only its `BankProfile`), `Cells` (dates, amounts, headers), `StatementMovement` (the normalized movement). Plain PHP. |
| `Domain/Collections` | `CollectionBuilder` (adds credits into the contract's `DailyCollection`/`WeeklyCollection`, in cents), `DayReport`, `WeekReport`. Plain PHP. |
| `Application/Import` | `MovementImporter` (the only way in), `StatementImport` (a file), `FolderImporter` (the drop folder), `ReceiveMovements` (the API). |
| `Application/Movements`, `Application/Reports` | Queries of the screens and the status change; the report use cases. |
| `Infrastructure` | Eloquent models (`BankMovement`, `BankLoad`), `TabularFile` (csv/txt, xlsx, and xls when it is an HTML table), `EloquentRecaudoProvider`. |
| `Http`, `routes`, `resources` | Screens, the API, texts (`resources/lang/es`). |

## The statements: the real layouts

> The layouts of **Banco de Bogotá, Davivienda and Bancolombia are the ones of their real exports** (samples of October 2026, Part D, item 3). **Miami is still assumed**: no sample has arrived. The fixtures in `tests/Modules/Bancos/fixtures` keep the real structure with invented data (see the README there). When a bank changes its export, adjust its profile (its class in `Domain/Statement/Banks`) and the fixture; nothing else changes.

A file is `.csv`, `.txt` (delimiter and encoding detected: UTF-8 or Windows-1252, with or without BOM), `.xlsx` (first sheet) or `.xls` when it is really an HTML table, as Davivienda exports it (every `<tr>` becomes a row, cells lose Excel's leading apostrophe). A real binary `.xls` (Excel 97) is refused with a message that says how to save it.

Most banks have a header row: the reader finds it in the first 40 rows (a bank may print a title above it) and maps the columns **by name**, so the order does not matter. Headers are compared without accents, case or punctuation. **Bancolombia has no header**: its profile gives the position of each column, every non-empty row is a movement, and a file whose first rows do not fit (too few columns, no date where the date goes) is refused as not being Bancolombia's.

| Bank | File | Columns used | Credit or debit | Dates | Numbers |
|---|---|---|---|---|---|
| Banco de Bogotá | `.csv`, `,`, Windows-1252, header in row 1, 18 columns | `Fecha`, `Valor`, `Indicador Débito / Crédito`, `Descripción de Transacción` + `Texto de Referencia`, `Referencia del Banco`, `Número de Cuenta`, `Moneda de Cuenta` | indicator `C` / `D` (`Tipo de Transacción` is a numeric code, not the direction) | `dd/mm/aaaa` in `Fecha` (`Fecha de Transacción` comes empty) | `1234567.89`, unsigned |
| Davivienda | `.xls` that is an HTML table, a `Movimientos` title, 17 columns, totals at the foot | `Fecha`, `Valor Total`, `Tran`, `Desc Mot.`, `Doc.` | `Tran`: `Notas Credito` / `Notas Debito` | `dd/mm/aaaa` | `$ 1.234.567,89`, unsigned |
| Bancolombia | `.csv`, `,`, **no header**, 9 columns | by position: 0 account, 3 date, 4 document, 5 amount, 7 description (1 office, 6 transaction code; 2 and 8 always `1` and `0`, meaning unknown) | the sign of the amount | `ddmmaaaa`, no separators | `-1234567.89` |
| Miami (**assumed**) | `.csv` | `Date`, `Description`, `Reference`, `Deposits`, `Withdrawals` | separate columns | `mm/dd/aaaa` | `1,234,567.89`, **USD** |

What the real files taught:

- **No bank gives a unique movement id.** Bogotá's `Referencia del Banco` is shared by a credit and the fee and GMF debits charged on it. Davivienda's `Doc.` is the closest (one per movement), but the bank's own charges reuse `99999999` and `99999998`. Bancolombia's document column is empty except for cheques. So the fingerprint of a movement stays the texts plus their position in the file (see Loading).
- Bogotá's description joins `Descripción de Transacción` and `Texto de Referencia` (where the payer is) with ` · `, without the bank's labels (`Oficina Receptora: 0940`, `Otros detalles del movimiento:`). A reader can override `cleanText()` for that. Descriptions are cut to 500 characters, the size of the column that stores them.
- Davivienda's `Desc Mot.` and Bancolombia's description (up to about 30 characters) hold the payer's NIT or name, cut short by the bank.
- Only Bogotá has a currency column (`Moneda de Cuenta`); the three accounts are in pesos.

The **company and the bank never come from the file**: they come from the folder it is dropped in, or from what the person picks in the form, or from the body of the API call.

Rules the readers follow:

- **Amounts are never guessed.** `1234567.89` in a Colombian-style file (dot as thousands separator with groups that are not of three) is refused, not read as 123.456.789. Each bank has a number style.
- A row with a date it cannot read, or an amount it cannot read, is an **error with its row number**; rows without a date (totals, notes) or with a zero amount are counted as ignored. In a file without a header, a row with fewer cells than the columns need is an error too. Nothing disappears silently: the load history shows the counts and the first 50 errors.
- A Type column that says neither credit nor debit is an error, not a guess.

## Loading

- **Folder.** `bancos:importar-carpeta`, scheduled every day at `BANCOS_IMPORT_AT` (06:30, America/Bogota). Layout: `<BANCOS_IMPORT_PATH>/<SGX|SDC>/<bogota|davivienda|bancolombia|miami>/<file>`. A relative path is on the `local` disk (`storage/app/private`); an absolute one is used as it is. Imported files move to `procesados/<year-month>/`; a file that cannot be imported moves to `errores/` next to a `.error.txt`, is recorded in the history as a failed load, and makes the command exit with failure (so the cron mail shows it). Files touched in the last minute are left for the next run.
- **App.** `/bancos/cargas`: company, bank and file. Needs `bancos.import`.
- **API.** `POST /api/movimientos` (below).

All three go through `MovementImporter`:

- Only **credits** are stored. Debits are counted and skipped.
- **No duplicates.** The fingerprint is company + bank + date + amount + currency + reference + description + account, plus the position of the movement among identical ones in the same file. Two real payments with the same amount, reference and date in one file are both kept; loading that file again, or an overlapping one, adds nothing. The unique index makes it safe under concurrency.
- **Known limit:** two identical payments (same date, amount, reference and text) that arrive in *separate* loads cannot be told from the same statement loaded twice, so the second is skipped. Identical payments in the same file are kept. The real exports carry no movement id of the bank that would tell them apart (see above): two Bancolombia transfers of the same amount on the same day look the same. Davivienda prints a time (`Hora`) that is not used yet.
- Credits dated **after today** are not stored: a bank cannot post tomorrow's money, so the date was read in the wrong order. The load reports them.
- The currency comes from the reader (COP; USD for Miami), the file's `Currency` column if it has one, or `bancos.currencies.<bank>`.

## Treasury API

Both endpoints are JSON, behind a bearer token, rate-limited (60 per minute per IP) and **closed while their token is not configured**. They use different tokens so a leaked read token cannot write.

### `POST /api/movimientos` — `BANCOS_INGEST_TOKEN`

The normalized movement every reader produces, so the app of Cartera does not change when Treasury switches from files to the API.

```json
{
  "empresa": "SGX",
  "banco": "bogota",
  "referencia_lote": "extracto-15-oct",
  "movimientos": [
    { "fecha": "2026-10-15", "tipo": "credito", "valor": "1250000.00",
      "descripcion": "TRANSFERENCIA ACH", "referencia": "ACH123", "cuenta": "000-111", "moneda": "COP" }
  ]
}
```

`tipo` is `credito` (default) or `debito` (ignored). `valor` is positive, with a dot for decimals and no thousands separator. Up to 5000 movements per call. Answer: `201` with `{carga, nuevos, repetidos, debitos_omitidos, errores}` when something was new, `200` when everything was already there. Sending the same call twice is safe. Invalid bodies get a `422` that names `movimientos.N.campo`.

### `GET /api/recaudo?desde=2026-10-01&hasta=2026-10-31[&empresa=SGX]` — `BANCOS_API_TOKEN`

The `RecaudoProvider` contract as JSON: `diario` (per day and company, with `por_banco` and `movimientos`) and `semanal` (ISO weeks, Monday to Sunday). Both ends included, up to 366 days. Pesos only.

## Collections (recaudo)

- The sum of the credits per **movement date**, whatever their status: an ignored credit still reached the bank account, and Treasury's cash flow wants the cash.
- Only credits in `bancos.collection_currency` (COP) add up. Dollar credits are stored and listed, but there is **no exchange rate** to convert them; the report warns when there are any.
- Movements arrive the day after (día vencido): the report opens on yesterday and the week shows the days that have not arrived as "Aún no llega", not as zero. The week is compared with the previous one over the same weekdays.
- The sheet María Paula fills in today is **not known yet** (Part D, item 4), nor how Treasury builds the weekly accumulated (item 5): the layouts here are proposals.

## Who can do what

| Permission | Roles | What |
|---|---|---|
| `bancos.view` | `gerente_cartera`, `analista_cartera`, `tesoreria` | Report, movements, load history. Scoped to the companies of `CurrentCompany`. |
| `bancos.import` | the same | Upload a statement. |
| `bancos.apply` | `gerente_cartera`, `analista_cartera` | Set a credit to applied (with its cash receipt number), ignored or pending. Audited. |

The receipt number is free text (letters, digits, `-`, `/`, `,`), because one deposit can pay several receipts: `4521, 4522`.

## Configuration

| Variable | Default | Used for |
|---|---|---|
| `BANCOS_IMPORT_PATH` | `bancos/entrada` | The drop folder. |
| `BANCOS_IMPORT_AT` | `06:30` | Time of the daily folder import (Bogotá). |
| `BANCOS_API_TOKEN` | — | Read token of `GET /api/recaudo`. |
| `BANCOS_INGEST_TOKEN` | — | Write token of `POST /api/movimientos`. |
| `BANCOS_MIAMI_CURRENCY` | `USD` (the reader's) | Set to `COP` if the Miami account is kept in pesos. |

`.env.example` belongs to the base and only lists `BANCOS_IMPORT_PATH` and `BANCOS_API_TOKEN`; the other three need a line there.

## Not here yet

- **Reconciliation** (5.8): `Conciliacion/` is for the phase-2 session, when the file arrives.
- The cash flow (5.9) is Treasury's; this module only exposes the data.
