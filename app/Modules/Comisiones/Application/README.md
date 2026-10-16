# Commission settlement engine

Settles the monthly commissions of Segurex (SGX): sales and collection commissions per seller code,
crossings, special cases, channel directors, totals per code and per person, alerts, controls and the
settlement lifecycle. Business rules: [docs/especificaciones/liquidacion-comisiones.md](../../../../docs/especificaciones/liquidacion-comisiones.md)
(Part A §4–§10 and §14, Annex 1 and 2).

The engine is plain PHP. It never reads the clock, the configuration, the database or SAP: the
infrastructure passes everything in through three ports and stores what the use cases return.

## Where things live

| Folder | Contents |
|---|---|
| `Domain/Shared` | `Money` (exact decimals), `Percentage`, `Period`, `Validity`, `Dates`, `SellerCode`, `PersonRef` |
| `Domain/Parameters` | Versioned rules: `SellerCodeRule`, `TrancheTable`, `DirectorTeam`, `DerivedCommissionRule`, `OptionsVersion`, `ParameterBook` → `PeriodParameters` (the frozen snapshot of a period) |
| `Domain/Parameters/Options` | `SettlementOptions` and its enums: the open points 14.2–14.7 |
| `Domain/Input` | `SettlementInput` and the lines and captures it carries |
| `Domain/Calculation` | `SettlementCalculator::calculate(SettlementInput, PeriodParameters): SettlementResult` and its collaborators |
| `Domain/Settlement` | `Settlement` (one version and its lifecycle), `SettlementStatus` and the lifecycle exceptions |
| `Application` | The use cases, `Ports/`, `SettlementNotFound` and `DefaultParameters` |

## How the infrastructure uses it

1. **Implement the ports** in `Application/Ports`:
   - `SettlementInputs::forPeriod(Period): SettlementInput`: the period's lines and captures (mapping below).
   - `CommissionParameters::book(): ParameterBook`: every version of the parameters.
   - `Settlements::latest(Period): ?Settlement` and `Settlements::save(Settlement)`: keep every version;
     replace an open version in place; never overwrite a closed one.
2. **Seed the parameters** once with `DefaultParameters::book()`. Later edits are new versions with their
   own `Validity`; a period takes, per key, the version with the latest start in force on any of its days.
3. **Call the use cases** (constructor-injected ports, method `handle`), passing the user and the current
   time from outside:

   | Use case | Spec | Does |
   |---|---|---|
   | `CalculateSettlement::handle(Period, $analyst, $at)` | §6.4 | No version yet → draft v1. Open (draft or approved) → recalculated in the same version, back to draft. Closed → `SettlementLocked`. |
   | `ApproveSettlement::handle(Period, $approver, $at, $justifications)` | §6.5, §9.7, §10 | Approves the latest draft. `$justifications` maps alert key → text for every blocking alert. |
   | `CloseSettlement::handle(Period, $user, $at)` | §6.6 | Closes the latest approved version; it is frozen from then on. |
   | `RegisterComplementarySettlement::handle(Period, $analyst, $reason, $at)` | §6.7 | Recalculates a closed period as a new draft, version + 1, with its reason; the closed version stays. |

4. **Persist each `Settlement`** with its `SettlementResult`, which carries the `PeriodParameters` it was
   calculated with (`$settlement->result->parameters()`), so a stored version never depends on later
   parameter changes. Rebuild it through the public `Settlement` constructor, which rejects states the
   flow cannot reach.
5. **Map the exceptions** to Spanish messages for the user:

   | Exception | When |
   |---|---|
   | `SettlementNotFound` | Approve, close or complement a period that was never calculated |
   | `SettlementLocked` | Recalculate a closed version |
   | `InvalidTransition` | Approve a version that is not a draft, close one that is not approved, complement one that is not closed |
   | `SegregationOfDuties` | The approver is the analyst who calculated it (users compared trimmed, case-insensitive) |
   | `UnresolvedAlerts` | A blocking alert has no justification; `keys()` lists them |
   | `InvalidArgumentException` | Blank user or reason, or a justification for anything but a blocking alert of the result |
   | `MissingParameters` | No tranche table in force for the period |

The engine only knows the analyst who calculated a version. The rest of §10 belongs to the
infrastructure: roles, keeping parameter editors and adjustment loaders from approving, and the audit log.

## Input mapping

Amounts go in as `Money::of('1234567.89')` (plain decimals, no thousand separators) and dates as
`Dates::day('2026-07-31')`. Keys are the natural keys of §9.5; a repeated key raises `linea_duplicada`.
Lines dated outside the period may be sent (the robot adds 7 days of the previous month); they are ignored.

| Source | Type | Field mapping |
|---|---|---|
| Report 1, ventas | `SaleLine` | `key` = Número de documento + Posición; `sellerCode` = `SellerCode::fromSapText(Vendedor)` (null when there is none); `sellerText` = Vendedor; `documentDate` = Fecha de documento; `documentType` = Clase de documento (`factura`, `nota_credito`, `nota_debito`, `devolucion`); `clientCode`/`clientName` = Cd Cliente/Cliente; `total` = **Total, signed** (credit notes and returns negative); `currency` = Moneda; `exchangeRate` = TRM; `annulled` from Estado del documento |
| Report 2, recaudos | `PaymentLine` | `key` = Número de Pago + Número Factura + Línea; `sellerCode`/`sellerText` from Nombre Vendedor; `paymentDate` = Fecha Pago; `invoiceNumber`/`invoiceDate` = Número/Fecha Factura; `clientCode`/`clientName` = Código/Nombre Cliente; `invoiceAmount` = Valor Factura; `paidBase` = **Vlr Total Pagado** (already without VAT, the commission base); `exchangeRate` = TRM; `annulled` from Estado Documento |
| Report 4, cruces | `CrossingLine` | `key` = documento de compensación + factura + línea; `type` = Tipo de cruce (`anticipo`, `cliente_proveedor`, `otro`); `source` = `sap` (`manual` for the manual capture); `crossingDate` = Fecha de compensación; `invoiceNumber`/`invoiceDate` = factura compensada/Fecha Factura; `clientCode`/`clientName` = Código Cliente/Razón Social; `counterparty` = the supplier side; `invoiceAmount` = Valor Factura; `crossedAmount` = Valor Compensado (**with VAT**); `invoiceTaxBase` = Base Gravable; `invoiceVat` = IVA Factura; `crossedAmountWithoutVat` = Valor Compensado sin IVA; `sellerOverride` = a user's correction with support; `annulled` from Estado |
| Report 5, clientes | `ClientAssignment` | `clientCode`; `sellerCode` = Código de vendedor asignado; `effectiveFrom` = when the assignment took effect. Send the **whole history**, not only the period |
| Manual budget | `Budget` | `sellerCode`, `amount` for the period; several rows of a code are added up |
| Manual OBR/Escudos | `ObrSale` | `sellerCode`, `description`, `soldValue`, `rate` |
| Manual adjustments | `ManualAdjustment` | `sellerCode`, `kind` (`reintegro` adds, `descuento` subtracts), `amount` > 0, `description` |

A crossing's base without VAT is `crossedAmount × taxBase / invoiceAmount` when the tax base is given
(or invoice amount − VAT) and the invoice amount is not zero; otherwise `crossedAmountWithoutVat`;
otherwise the crossing is left out with `cruce_sin_base`. Reports 2 and 4 carry no currency column, so
their `currency` stays `COP`.

## Open points 14.2–14.7

Answered by `SettlementOptions`, versioned through `OptionsVersion`. The defaults reproduce the current Excel.

| Point | Option | Default (Excel) | Alternative |
|---|---|---|---|
| 14.2 VAT in sales | `salesBasis`, `salesVatRate` | `AsReported`: SAP Total is the base | `IncludesVat`: base = total / (1 + VAT), 19 % unless set |
| 14.2 Credit notes and returns | `creditNotes` | `Net`: signed lines net in their period | `Exclude`: left out |
| 14.3 Crossings in 85.1 | `crossingsInDerivedCollections` | `false` | `true` |
| 14.3 Crossings in the director base | `crossingsInDirectorCollections` | `false`, **an assumption to confirm**: the Excel is taken to add only the SAP collections | `true` |
| 14.4 Director commission of 50 and 53 | `directorPlacement` | `Separate`: its own "Director {channel}" role | `AssociatedCode`: added to the total of code 50 or 53 |
| 14.5 Seller of a crossing | `crossingSeller` | `CrossingDate`: seller in force on the crossing date | `InvoiceDate` |
| 14.6 Compliance scope | `complianceScope` | `Code` | `Person`: a person's codes share one compliance |
| 14.7 Foreign currency | `currency` | `AsReported`: amount kept, `moneda_extranjera` warning | `Convert`: amount × TRM, to cents |

**AssociatedCode fallback.** When a team has no associated code, or that code has no parameters in the
period, the team is still paid as its own director role (`DirectorSettlement::$paidWithCode` is null), so
the director never loses it.

## Rounding

- Line commissions are exact. Each concept is rounded **once** to the peso, half up (away from zero): the
  sale commission of a code, its collection commission (Σ exact payment and crossing lines), its OBR
  commission, each derived commission (81.1, 85.1), and the sales and collection parts of a director.
- Derived bases are rounded to cents, half up: a crossing's base without VAT, sales without VAT
  (`IncludesVat`) and converted amounts (`Convert`).
- The minimum compliance is checked exactly (`sales ≥ budget × minimum`); the compliance shown is
  sales / budget at 6 decimals, half up.

## Alerts

`Alert::key()` is `"{kind}:{reference}"`. It is stable between recalculations and unique within a
result, so justifications are keyed by it. Blocking alerts stop the approval until justified (§9.7).

| Kind | Severity | Reference | Raised when |
|---|---|---|---|
| `codigo_desconocido` | blocking | the code (`99`), the SAP seller text, or `sin vendedor` | A line or capture whose code has no parameters, or a line without code |
| `cliente_sin_vendedor` | blocking | the crossing key | A crossing whose client had no seller on the date |
| `sin_presupuesto` | blocking | the code | A code with a minimum, sales and no budget |
| `comision_negativa` | blocking | the code (`34`), the derived key (`81.1`) or the team key (`distribucion`) | A negative sale, collection or total commission of a code; a negative derived commission; a negative sales part, collection part or total of a director team |
| `linea_duplicada` | blocking | `venta:{key}`, `pago:{key}` or `cruce:{key}` | The same natural key twice in one list |
| `cruce_sin_base` | blocking | the crossing key | A crossing without any base without VAT |
| `pago_antes_de_factura` | warning | `pago:{key}` or `cruce:{key}` | Paid before the invoice date; counted as 0 days |
| `recaudo_anulado` | warning | `pago:{key}` or `cruce:{key}` | An annulled payment or crossing, left out |
| `moneda_extranjera` | warning | `venta:{key}`, `pago:{key}` or `cruce:{key}` | Another currency or a TRM other than 1; with `Convert`, only another currency at TRM 1 |

Derived and team keys are never plain numbers, and a team and a derived commission never share a key,
so the three kinds of `comision_negativa` references never collide.

## Controls

`SettlementResult::controls()` returns `ControlTotals`:

- §9.1 `salesReconcile()`: loaded sales = sales by code, control codes 80 and 100 included.
- §9.2 `collectionsReconcile()`: loaded collections = settled collections (payments and crossings).
- `unassignedSales` and `unassignedCollections`: what lines without a known code or seller left out.

"Loaded" is every in-period line the engine kept, after duplicates, annulments and options. §9.3 holds by
construction: a code's collection commission is the rounded sum of its `collectionDetails`, which the PDF
lists. §9.4 (the SAP `.ctl` file) and the idempotent loads of §9.5 belong to the infrastructure.

## Default parameters

`DefaultParameters::book($from = '2026-01-01')` holds the seed values: the Annex 1 codes, the §5.3
tranches, the §5.6 teams and the §5.5 derived commissions (81.1, 85.1), all valid from `$from` and
open-ended, with no options (the Excel defaults). Seed them once; from then on the stored book, edited
through new versions, is the source.
