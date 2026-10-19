# Bank statement fixtures

**Every file here is invented.** Nothing in them comes from a real bank
statement: the layouts are the guesses documented in the reader of each bank
(`app/Modules/Bancos/Domain/Statement/Banks/`). Each file says so in its first
row. Replace them with real extracts (and fix the readers) when Treasury
delivers them (`docs/prompt-gestor-cartera.md`, Part D, item 3).

| File | Guessed layout |
|---|---|
| `bogota-ejemplo-inventado.csv` | `;`, ISO-8859-1, `Fecha / Descripción / Referencia / Débito / Crédito / Saldo`, `dd/mm/aaaa`, `1.234,56` |
| `davivienda-ejemplo-inventado.csv` | `,` with quoted amounts, `Fecha / Oficina / Descripción motivo / Documento / Transacción / Valor`, `dd/mm/aaaa`, `1.234,56` |
| `bancolombia-ejemplo-inventado.csv` | `,`, UTF-8 with BOM, `FECHA / DESCRIPCIÓN / SUCURSAL / REFERENCIA 1 / VALOR / SALDO`, `aaaa/mm/dd`, signed `-1234.56` |
| `miami-ejemplo-inventado.csv` | `,`, `Date / Description / Reference / Deposits / Withdrawals / Balance`, `mm/dd/aaaa`, `1,234.56`, USD |
