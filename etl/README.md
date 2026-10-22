# Robot de SAP del gestor de cartera

El robot extrae datos de SAP Business One (HANA, esquema `SEGUREX`) y los publica en el gestor de cartera (Laravel, módulo Ingesta). Corre en un equipo de Segurex con la VPN Sophos, programado en el Programador de tareas de Windows.

## Qué hace

Cada corrida tiene dos fases, a propósito:

1. **Con VPN:** conecta Sophos, ejecuta en HANA la consulta de cada lote y baja la VPN, aunque algo falle.
2. **Sin VPN:** valida cada lote, guarda el respaldo CSV y publica.

El perfil de la VPN es de túnel completo: con ella arriba, el tráfico hacia Laravel saldría por la red corporativa. Por eso nunca se publica con la VPN conectada.

Al final deja el resultado de cada lote en `etl/state/ultima_ejecucion.json` y, si algo falló, envía un correo de alerta (si configuraste el SMTP).

## Destinos

| Destino | Papel | Qué recibe |
|---|---|---|
| Laravel (`INGESTA_URL`) | Principal | Todos los lotes de `ETL_LOTES`, por HTTPS firmado con HMAC. |
| CSV local (`etl/out`) | Auditoría y plan B | Cada lote extraído; conserva los últimos 30 de cada uno. |
| Supabase (`SUPABASE_DB_URL`) | Respaldo del portal anterior | Solo cartera y asesores, igual que antes. Déjalo vacío cuando el portal anterior se apague. |

Necesitas al menos un destino: Laravel completo (`INGESTA_URL`, `INGESTA_KEY_ID` e `INGESTA_SECRET`) o Supabase.

### Cómo publica en Laravel

- `POST {INGESTA_URL}/api/ingesta/{lote}`, con el cuerpo JSON por columnas y comprimido con gzip.
- La firma es HMAC-SHA256 de la hora, la ruta y el hash del cuerpo exacto que se envía. Laravel rechaza una firma vencida (más de 5 minutos de diferencia), así que el reloj de este equipo debe estar en hora.
- El `batch_id` sale del contenido: si el robot reenvía la misma extracción, Laravel responde «duplicado» y no la carga dos veces.
- Reintenta hasta 3 veces (a los 2, 5 y 10 segundos) solo si no hubo respuesta o si Laravel respondió 5xx. Un 4xx (firma, lote desconocido, cuerpo inválido) no se reintenta: el lote queda fallido con el mensaje de Laravel.
- La fecha de corte es el día en Colombia (UTC−5) en que empieza la extracción de HANA: si la corrida extrae antes de medianoche y publica después, el lote conserva el día de la extracción.

## Configuración

Copia `etl/.env.example` como `etl/.env` y complétalo. El `.env` nunca se sube a Git; los secretos se ocultan en los logs.

| Variable | Para qué |
|---|---|
| `SOPHOS_*` | Usuario y conexión de la VPN Sophos. |
| `HANA_*` | Servidor, usuario y esquema de SAP HANA. |
| `INGESTA_URL` | Raíz del gestor de cartera, sin `/api` (por ejemplo `https://cartera.segurex.com`). Debe ser `https://`; `http://` solo se acepta para un Laravel local. |
| `INGESTA_KEY_ID`, `INGESTA_SECRET` | Par de la llave HMAC. Debe existir en `INGESTA_HMAC_KEYS` del `.env` de Laravel como `id:secreto`. |
| `INGESTA_EMPRESA` | `SGX` (Segurex, por defecto) o `SDC` (Soluciones y Derivados). |
| `INGESTA_TIMEOUT` | Segundos de espera por envío; por defecto 120. |
| `INGESTA_GZIP` | Comprimir el cuerpo; por defecto `true`. |
| `ETL_LOTES` | Lotes que se extraen y publican, separados por comas; por defecto `cartera,asesores`. |
| `SUPABASE_DB_URL` | Respaldo en Supabase (opcional). |
| `ALERTA_*` | Correo de alerta cuando algo falla (opcional). |

### Rotar la llave HMAC

Sin cortar las cargas:

1. En Laravel, agrega el par nuevo junto al viejo: `INGESTA_HMAC_KEYS=k2026a:<viejo>,k2026b:<nuevo>` y corre `php artisan config:cache`.
2. En `etl/.env`, cambia `INGESTA_KEY_ID=k2026b` e `INGESTA_SECRET=<nuevo>`.
3. Cuando las cargas lleguen firmadas con la llave nueva, quita el par viejo en Laravel y vuelve a cachear la configuración.

## Lotes

Cada lote es una consulta de `etl/queries` y su contrato de columnas en `etl/esquema.py` (nombres, tipos, columnas obligatorias, llave natural y si admite 0 filas). Ese archivo es la fuente de verdad: Laravel lo replica, así que cualquier cambio de columnas va en los dos lados.

- **Cartera y asesores** son la base. Si su consulta falla, cambia de columnas o la cartera llega vacía, se aborta toda la corrida y no se publica nada.
- **Los demás lotes** son de mejor esfuerzo: si uno falla, se registra, se avisa al final y la corrida termina con código 1, pero la cartera se publica igual.
- Un lote que no admite 0 filas y llega vacío no se envía; Laravel conserva su última carga.

`ETL_LOTES` decide qué lotes corren. Agrega un lote nuevo solo después de validar su consulta con datos reales.

| Lote | Consulta | Lo consume | Llave natural | Admite 0 filas |
|---|---|---|---|---|
| `cartera` | `cartera.sql` | Core | (ninguna) | No: aborta la corrida |
| `asesores` | `asesores.sql` | Core | `slp_code` | No: no se envía y se conserva la carga anterior |
| `contactos` | `contactos_clientes.sql` | Core | `codigo_sn`, `contacto_id` | No |
| `ventas` | `ventas.sql` | Comisiones | `tipo_objeto`, `doc_entry`, `linea` | Sí |
| `recaudos` | `recaudos.sql` | Comisiones | `doc_entry_pago`, `linea` | Sí |
| `cruces` | `cruces.sql` | Comisiones | `numero_cruce`, `linea` | Sí |
| `maestro_clientes` | `maestro_clientes.sql` | Comisiones | `codigo_cliente` | No |
| `maestro_vendedores` | `maestro_vendedores.sql` | Comisiones | `slp_code` | No |

Ventas, recaudos y cruces traen el mes en curso más los últimos 7 días del mes anterior, hasta hoy; incluyen los documentos anulados con su estado. Las notas crédito de ventas llegan con signo negativo. Los maestros traen todos los registros, activos o no.

### Confirmado con Miguel

- **Canal:** es el grupo del cliente (`OCRG.GroupName`, unido por `OCRD.GroupCode`), en `maestro_clientes` y en `ventas` (el cliente de la factura). Es el mismo valor que la columna «Grupo» de la cartera.
- **Id pedido app:** `OINV.U_SGX_IdPedidoApp`. Las notas crédito lo traen vacío.
- **Período de ventas:** la fecha de la factura (`DocDate`), que llega en `fecha_contabilizacion`. `fecha_documento` (`TaxDate`) se conserva como referencia. La ventana de ventas también va por `DocDate`.
- **Cruces:** solo cruces de anticipos y compensaciones cliente-proveedor. Una reconciliación que no es ninguna de las dos no se extrae.
- **Zona del vendedor:** queda vacía; SAP no la maneja.

Valores de texto que Comisiones puede filtrar:

| Lote | Columna | Valores |
|---|---|---|
| `ventas` | `clase_documento` | `factura`, `nota_debito`, `nota_credito` |
| `ventas` | `estado_documento` | `anulado`, `anulacion`, `cerrado`, `abierto` |
| `recaudos` | `estado_pago` | `anulado`, `vigente` |
| `cruces` | `tipo_cruce` | `anticipo`, `cliente-proveedor` |
| `cruces` | `estado` | `anulado`, `vigente` |
| `maestro_clientes`, `maestro_vendedores` | `estado` | `activo`, `inactivo` |
| `contactos` | `principal`, `activo` | `Y`, `N` |

### Pendiente de validar con datos reales

Cada punto está marcado en su consulta con `VALIDAR CON DATOS REALES`. Valídalos con `--diagnostico` y el informe equivalente de SAP antes de agregar el lote a `ETL_LOTES`.

**contactos_clientes.sql**

- [ ] `principal`: compara `OCPR.Name` con `OCRD.CntctPrsn` (SAP guarda ahí el nombre, no el código). Revisar que coincidan exactamente y que cada cliente tenga un solo `Y`.
- [ ] Fila general: sale para todos los clientes, aunque no tengan correo ni teléfonos. Confirmar con Core si la quiere así.

**ventas.sql**

- [ ] `canal` e `id_pedido_app`: confirmar que llegan llenos (ver «Confirmado con Miguel»).
- [ ] `pedido`: solo cuando la línea viene de un pedido (`BaseType` 17). Si la factura sale de una entrega, queda `NULL`.
- [ ] `ciudad_envio`: va en `NULL`; candidata `INV12.CityS` / `RIN12.CityS`.
- [ ] `costo_unitario` y `costo_total`: salen de `StockPrice`. Confirmar contra el margen del informe actual.
- [ ] `precio_lista`: es `PriceBefDi` (precio antes del descuento de la línea), no la lista de precios del artículo.

**recaudos.sql**

- [ ] Solo aplicaciones a facturas (`InvType` 13): quedan fuera las de facturas de anticipo, asientos y notas crédito.
- [ ] `fecha_factura`: es `DocDate`. Confirmar que no sea `TaxDate` y que coincida con ventas.
- [ ] `slp_code`: es el vendedor de la factura, no el asignado hoy al cliente.
- [ ] `comision_sap`: va en `NULL`; no se conoce su origen en Business One.

**cruces.sql** (toda la consulta está por validar)

- [ ] Que los cruces que hoy se digitan a mano sean las reconciliaciones internas de tipo 0, 1 y 2.
- [ ] `tipo_cruce`: la regla de anticipo (línea de pago recibido) y de cliente-proveedor (línea de proveedor o documento de compras). Lo que no cumple ninguna de las dos queda fuera: confirmar que no se pierde ningún cruce digitado a mano.
- [ ] `codigo_proveedor`: el proveedor de la reconciliación o, si no hay, `OCRD.ConnBP` del cliente.
- [ ] `fecha_factura`: es `DocDate`, como en recaudos.
- [ ] `usuario`: va en `NULL`; no hay una columna confirmada en `OITR`.

**maestro_clientes.sql**

- [ ] `estado`: `inactivo` solo si el cliente está congelado (`frozenFor`); no mira los rangos de fechas.

**maestro_vendedores.sql**

- [ ] `canal`: va en `NULL`; no hay una columna conocida en `OSLP`.
- [ ] `fecha_modificacion`: va en `NULL`; revisar si la versión de SAP tiene `OSLP.UpdateDate`.

## Validar una consulta nueva

```powershell
.venv\Scripts\python.exe etl\run_etl.py --diagnostico --lotes ventas
```

El modo diagnóstico conecta la VPN, ejecuta la consulta, baja la VPN, guarda el CSV en `etl/out` y revisa cada fila contra el contrato de `esquema.py` (tipos y columnas obligatorias). No publica en ningún lado. `--lotes` reemplaza a `ETL_LOTES` en esa corrida; acepta varios separados por comas.

Revisa el CSV y compara los totales con el informe equivalente de SAP antes de agregar el lote a `ETL_LOTES`.

## Pruebas

Desde la raíz del repositorio, solo con la biblioteca estándar:

```powershell
python -m unittest discover -s etl/tests -t etl -v
```

Las pruebas no usan la VPN, SAP, Laravel ni Supabase: trabajan con datos de muestra y transportes falsos. La única conexión real es a un servidor de prueba en `127.0.0.1`.

## Programación

`scripts/instalar_tarea.ps1` registra la tarea `CarteraWeb-ETL` con tres corridas diarias:

| Hora | Para qué |
|---|---|
| 00:00 | Corrida principal. |
| 05:00 | Reintento antes de la alerta de Laravel de las 07:00. Repetir una extracción idéntica no duplica nada. |
| 12:00 | Actualización de mediodía. |

Las horas evitan las 06:00 y las 12:30 del robot de cuentas por pagar, que comparte la VPN: si se solapan, uno le baja el túnel al otro.

```powershell
powershell -ExecutionPolicy Bypass -File scripts\instalar_tarea.ps1
powershell -ExecutionPolicy Bypass -File scripts\instalar_tarea.ps1 -Horas 00:00,05:00
powershell -ExecutionPolicy Bypass -File scripts\instalar_tarea.ps1 -Desinstalar
```
