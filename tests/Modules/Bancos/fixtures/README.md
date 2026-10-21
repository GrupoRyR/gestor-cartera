# Bank statement fixtures

**Every value here is invented.** Nothing comes from a real bank statement:
companies, NITs, accounts, references and amounts are made up. The **structure**
of the Bogotá, Davivienda and Bancolombia files is the one of each bank's real
export (samples of October 2026), so the readers are tested against the shape
they will meet. The Miami file is still a guess: no real sample has arrived.

Each file says it is invented where its structure allows it, without adding
rows the real export does not have: the Bogotá account name is
`EJEMPLO INVENTADO`, the Davivienda file opens with an HTML comment, the
Bancolombia account is `00000000000`, and the Miami file says so in its first row.

| File | Layout | What it exercises |
|---|---|---|
| `bogota-ejemplo-inventado.csv` | Real: `,`, Windows-1252, header in row 1 with the 18 columns of the export (`Número de Cuenta` … `Indicador Débito / Crédito`, `Valor`, …, `Referencia del Banco`, `Fecha`), `dd/mm/aaaa`, unsigned `1667.98` with `C`/`D` | A quoted reference text with a comma, a glued `Referencia 2:`, a credit and its GMF debit sharing the bank reference, two days |
| `davivienda-ejemplo-inventado.xls` | Real: an HTML table saved as `.xls`, a `Movimientos` title, 17 headers (`Fecha / Doc. / Tran / … / Desc Mot. / Valor Total / …`), `Notas Credito` / `Notas Debito`, `$ 1.234,56`, `dd/mm/aaaa` | Text references with a leading apostrophe, the bank's charges with `Doc.` 99999999, the totals at the foot, two days |
| `bancolombia-ejemplo-inventado.csv` | Real: `,`, no header row, 9 columns read by position (account, office, `1`, `ddmmaaaa`, document, signed `-1234.56`, code, description, `0`) | Two identical transfers on one day, a cheque with its document number, two days |
| `miami-ejemplo-inventado.csv` | **Assumed**: `,`, `Date / Description / Reference / Deposits / Withdrawals / Balance`, `mm/dd/aaaa`, `1,234.56`, USD | Separate deposit and withdrawal columns, dollars |

The dates are before 16/10/2026, the day the feature tests travel to, because
credits dated after today are not stored.
