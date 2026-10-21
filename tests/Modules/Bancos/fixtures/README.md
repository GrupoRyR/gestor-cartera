# Bank statement fixtures

**Every file here is invented.** Nothing in them comes from a real bank
statement: the layouts are the guesses documented in the reader of each bank
(`app/Modules/Bancos/Domain/Statement/Banks/`). Each file says so in its first
row. Replace them with real extracts (and fix the readers) when Treasury
delivers them (`docs/prompt-gestor-cartera.md`, Part D, item 3).

| File | Guessed layout |
|---|---|
| `bogota-ejemplo-inventado.csv` | Real layout: `,`, Windows-1252, header in row 1 with the 18 columns of the export (`Número de Cuenta` … `Indicador Débito / Crédito`, `Valor`, …, `Referencia del Banco`, `Fecha`), `dd/mm/aaaa`, unsigned `1667.98` with `C`/`D`. A quoted reference text with a comma, a glued `Referencia 2:`, and credits that share their bank reference with their fee or GMF debits |
| `davivienda-ejemplo-inventado.xls` | Real layout: an HTML table saved as `.xls`, a `Movimientos` title, 17 headers (`Fecha / Doc. / Tran / … / Desc Mot. / Valor Total / …`), `Notas Credito` / `Notas Debito`, `$ 1.234,56`, `dd/mm/aaaa`. Text references with a leading apostrophe and the totals at the foot |
| `bancolombia-ejemplo-inventado.csv` | Real layout: `,`, no header row, 9 columns by position (account `00000000000`, office, `1`, `ddmmaaaa`, document, signed `-1234.56`, code, description, `0`). Two identical transfers on one day and a cheque with its document number |
| `miami-ejemplo-inventado.csv` | `,`, `Date / Description / Reference / Deposits / Withdrawals / Balance`, `mm/dd/aaaa`, `1,234.56`, USD |
