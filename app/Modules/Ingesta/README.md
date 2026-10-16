# Ingesta

Recibe los lotes del robot de SAP (`etl/`), los valida contra el esquema de cada lote, los guarda como instantáneas con fecha y avisa a los consumidores con `BatchAccepted`. Implementa el contrato `IngestaSink` (`docs/arquitectura.md` §6.8 y §6.9).

## Endpoint del robot

`POST /api/ingesta/{lote}` (ruta `ingesta.api.lotes.store`), donde `{lote}` es un valor de `Dataset`: `cartera`, `asesores`, `contactos`, `ventas`, `recaudos`, `cruces`, `maestro_clientes` o `maestro_vendedores`. Otro valor responde 404. Límite: 60 peticiones por minuto por IP (`throttle:ingesta-robot`).

### Encabezados

| Encabezado | Valor |
|---|---|
| `Content-Type` | `application/json` |
| `Content-Encoding` | `gzip` (opcional; el robot comprime por defecto) |
| `X-Ingesta-Key` | id de la llave, p. ej. `k2026a` |
| `X-Ingesta-Timestamp` | segundos Unix (UTC), entero |
| `X-Ingesta-Signature` | `sha256=` + HMAC-SHA256 en hexadecimal minúscula |

### Firma

Cadena canónica en UTF-8, campos unidos por `\n`, sin salto final:

```
{timestamp}
POST
/api/ingesta/{lote}
{sha256 en hexadecimal del cuerpo tal como viaja (los bytes gzip si va comprimido)}
```

`firma = hex(hmac_sha256(secreto, cadena))`. La ruta se arma con `{lote}` en ambos lados, nunca con la URL de la petición, así que un subdirectorio o un proxy no la rompen. `VerifyRobotSignature` revisa, antes de descomprimir o leer el JSON, que la llave exista, que el timestamp esté a menos de `INGESTA_HMAC_MAX_SKEW` segundos (300 por defecto) de la hora del servidor y que la firma coincida (`hash_equals`). Cualquier falla responde 401 `{"message": "Firma inválida."}`, el mismo texto para todas, y queda en el log con el motivo, el id de la llave y la IP, nunca con el secreto.

Vector de prueba compartido con el robot (`tests/Modules/Ingesta/Unit/RequestSignerTest.php`): secreto `k2026a-secreto-de-prueba`, timestamp `1792152000`, lote `cartera` y cuerpo `{"rows":[]}` dan `sha256=ba04577457e09c8e9a6bec8983b85a09559f01b466e48a8f8fb2090e3a5b0fc9`.

### Cuerpo

JSON por columnas, para que pese poco:

```json
{
  "batch_id": "cartera-SGX-2026-10-16-3f2a9c1b7d4e5f60",
  "company": "SGX",
  "cutoff_date": "2026-10-16",
  "columns": ["slp_code", "email_asesor", "..."],
  "rows": [[12, "ana@segurex.com", "..."]]
}
```

- `batch_id`: hasta 120 caracteres de `A-Z a-z 0-9 . _ : -`. Es la llave de idempotencia: el mismo lote reenviado devuelve la carga original.
- `company`: `SGX` o `SDC`. `cutoff_date`: `AAAA-MM-DD`, no posterior a hoy en Bogotá.
- `columns`: los nombres «pg» de `etl/esquema.py`, en cualquier orden y sin repetir; cada fila de `rows` es una lista con un valor por columna.
- Valores: enteros como número JSON; montos (`NUMERO`, hasta 16 cifras enteras y 2 decimales, el rango de `decimal(18,2)`) y tasas o cantidades (`TASA`, 6 decimales) como texto decimal con punto y sin separador de miles (`"1234567.89"`); fechas `"AAAA-MM-DD"`; texto hasta 4000 caracteres; `null` si falta. Las columnas obligatorias no pueden llegar nulas ni en blanco.
- Cada fila debe traer exactamente las columnas del lote; un lote con llave natural no puede repetirla; solo `ventas`, `recaudos` y `cruces` pueden llegar vacíos; máximo `ingesta.max_rows` filas (200 000).

`Domain/Schema/DatasetSchemas.php` replica `etl/esquema.py`; `DatasetSchemasTest` lee el archivo de Python y falla si los dos se separan. Si cambias el esquema, cambia ambos.

### Respuestas

| Estado | Cuándo | Cuerpo |
|---|---|---|
| 201 | Carga nueva aceptada | `{"load": {...}, "duplicate": false}` |
| 200 | `batch_id` de una carga ya aceptada | `{"load": {...carga original}, "duplicate": true}` |
| 401 | Firma inválida, llave desconocida, timestamp fuera de margen o sin llaves configuradas | `{"message": "Firma inválida."}` |
| 404 | `{lote}` desconocido | estándar |
| 413 | Cuerpo de más de `ingesta.max_request_bytes` (20 MB) | `{"message": ...}` |
| 422 | Cuerpo mal formado: gzip o JSON inválido, sobre incompleto, fila con otro número de valores. No queda en el historial. | `{"message": ..., "errors": {...}}` |
| 422 | El lote no cumple el esquema. Queda en el historial como rechazado y su `batch_id` se puede reenviar ya corregido. | `{"message": "El lote tiene N errores.", "errors": {"rows.3.saldo": [...]}}`, máximo 50 llaves |

`load` trae `id`, `dataset`, `company`, `cutoff_date` (`AAAA-MM-DD`), `source`, `status`, `row_count`, `total` y `overdue` (texto con 2 decimales, solo cartera; `null` en los demás), `received_at` (ISO 8601) y `error`. Las filas de los errores se numeran desde 0, como las llaves `rows.N`.

El gzip descomprimido no puede pasar de `ingesta.max_payload_bytes` (60 MB). El `post_max_size` de PHP en el servidor debe ser al menos `ingesta.max_request_bytes`.

## Rotación de llaves

`INGESTA_HMAC_KEYS` admite varias parejas `id:secreto` separadas por comas (el secreto puede tener `:`; se parte en el primero). Para rotar: agrega la llave nueva junto a la vieja, cambia el robot a la nueva y, cuando ya firme con ella, quita la vieja. Una pareja mal formada se ignora con una advertencia en el log. Sin ninguna llave válida, el endpoint responde 401 a todo y lo registra como error.

## Almacenamiento

- `ingesta_loads`: el historial, aceptadas y rechazadas, con el corte, el origen, el usuario de una carga manual, los totales de cartera (`decimal(18,2)`, sumas exactas en centavos), el orden de columnas guardado y, en las rechazadas, el resumen y los errores por campo. `accepted_batch_id` es único y solo se llena en las aceptadas: así un rechazo se puede reenviar y dos copias simultáneas del mismo lote no crean dos cargas.
- `ingesta_load_chunks`: las filas de cada carga aceptada en bloques de 500 (`ChunkCodec`: JSON de listas de valores, gzip y base64). Las instantáneas nunca se sobrescriben.

## Cómo leen los consumidores

Escuchan `BatchAccepted`, que se despacha una vez por carga aceptada y después del commit (la cola `database` no espera commits), filtran por `$event->load->dataset` y recorren `app(IngestaSink::class)->rows($event->load->id)`. Las filas llegan en el orden recibido, como arreglos asociativos en el orden de columnas del esquema, y se leen un bloque a la vez. `latest()` da la instantánea vigente de un lote y empresa; `history()`, el historial más reciente primero.

## Permisos

| Permiso | Roles | Abre |
|---|---|---|
| `ingesta.view` | `admin`, `gerente_cartera`, `analista_cartera` | El historial de cargas |
| `ingesta.upload` | `admin`, `gerente_cartera`, `analista_cartera` | La carga manual del Excel de cartera |

## Historial de cargas

`GET /ingesta` (ruta `ingesta.index`, menú «Cargas de datos» en Administración): todas las cargas, aceptadas y rechazadas, 25 por página y la más reciente primero, con filtros por lote, empresa y estado (`?lote=cartera&empresa=SGX&estado=rechazada`; un valor desconocido no filtra). Arriba muestra la cartera vigente (`IngestaSink::latest()`) de cada empresa de `ingesta.alert_companies` y, pasada la hora límite (`ingesta.alert_deadline`, 07:00 en Bogotá), un aviso por cada una que aún no tenga una carga de cartera **del robot**, aceptada y con corte de hoy (`DailyCarteraCheck`). Una carga manual no apaga el aviso: mantiene la plataforma al día, pero el robot igual necesita revisión.

`GET /ingesta/cargas/{id}` (`ingesta.cargas.show`): los datos de una carga; si se rechazó, el resumen y los mensajes por campo (las filas se numeran desde 0, como en los mensajes); si es una cartera aceptada, el saldo total y el vencido. Un id que no existe responde 404.

`ingesta.alert_companies` trae solo `SGX`: el robot lee únicamente el esquema SEGUREX de HANA; SDC carga su cartera con el Excel manual hasta que se configure su esquema de SAP.

## Carga manual de cartera

Respaldo del robot, solo para el lote `cartera`. Desde el historial, «Carga manual» abre `GET /ingesta/carga-manual` (`ingesta.manual.create`, permiso `ingesta.upload`):

1. **Formulario.** Empresa (solo las de `CurrentCompany::available()`), fecha de corte (por defecto hoy, nunca posterior) y el Excel `.xlsx` «Cartera del día» de SAP, hasta 20 MB (`upload_max_filesize` y `post_max_size` de PHP deben permitirlo).
2. **Vista previa** (`POST /ingesta/carga-manual`, `ingesta.manual.preview`). Se lee la hoja «Datos» o, si no existe, la primera. La fila de títulos es la primera de las 20 primeras que tenga «Código SN» y «Nit»; los títulos se cruzan con el alias de HANA de cada columna (`Column::sourceHeader`), sin importar espacios ni mayúsculas (las tildes sí cuentan). Sin «Código SN», «Nit», «Documento», «Fecha vencimiento» o «Saldo» no hay vista previa. Los títulos desconocidos se ignoran y se listan; se saltan las filas vacías y las que no tienen ni código ni documento (como la fila de totales). La página muestra filas, clientes distintos, saldo total y vencido, las 20 primeras filas, cuántas quedaron sin código de asesor y, si `BatchValidator` encuentra errores, hasta 50 con su fila de Excel; con errores no se puede confirmar.
3. **Confirmar o descartar** (`POST /ingesta/carga-manual/{token}/confirmar` o `/descartar`). Mientras tanto el archivo queda en el disco `local` como `ingesta/manual/{token}.xlsx` y la sesión guarda la ruta, la empresa, el corte, el SHA-256 y el usuario. Confirmar vuelve a leer el archivo, revisa el hash y que sea el mismo usuario (otro usuario recibe 404), y entrega a `IngestaSink::receive()` el lote `manual-cartera-{empresa}-{AAAA-MM-DD}-{16 primeros hex del SHA-256}` con `LoadSource::Manual` y `receivedBy`. El mismo archivo con el mismo corte es la misma carga: la segunda vez muestra «Esta carga ya estaba registrada». Confirmar y descartar borran el archivo y la entrada de la sesión. Una carga nueva queda en la auditoría como `ingesta.load.uploaded` cuando hay un `AuditLogger` enlazado. Un archivo que se suba y nunca se confirme ni se descarte queda en `ingesta/manual/` hasta la limpieza diaria.

**Limpieza de cargas sin confirmar.** El comando `ingesta:limpiar-cargas-manuales` (`Console/PruneManualUploadsCommand`) borra los archivos que están directamente en `ingesta/manual/` del disco `local` (`ManualUploadController::DISK` y `::DIRECTORY`) y cuya última modificación tiene más de `ingesta.manual_upload_ttl_days` días (7 por defecto, mínimo 1), y dice cuántos borró. No toca subcarpetas ni nada fuera de ese directorio; si el directorio no existe, no hace nada. `IngestaServiceProvider::schedule()` lo corre todos los días a las 03:00 de Bogotá con `withoutOverlapping(10)`. Correrlo a mano es seguro.

**Conversión de celdas** (`Domain/Manual/CellConverter`): fechas de celdas de fecha, números de serie de Excel (época 1899-12-30) o texto `AAAA-MM-DD` y `DD/MM/AAAA`; montos de números o de texto como `$ 1.234.567,89`, `1,234,567.89`, `1.234` o `12,5` (con `.` y `,` manda el último como decimal; una `,` sola seguida de una o dos cifras es decimal); enteros de números enteros; texto recortado, y un código leído como `9280.0` queda `"9280"`. Lo que no se pueda leer pasa tal cual y lo rechaza el validador con su mensaje.

**Columnas que el Excel no trae.** `dias` (corte menos vencimiento; positivo = vencida), `sin_vencer`, las edades `d_0_30`…`d_mas_180`, `rango` y `saldo_vencido` se recalculan siempre desde `saldo` como en `etl/queries/cartera.sql` (`Domain/Manual/ReceivablesAging`); `valor_cheques` y `valor_abonado` quedan en `0.00` si faltan; `base_gravable`, `saldo_base`, `total_plazos` y `no_plazo`, en `null`.

**Código de asesor** (`Application/Manual/AdvisorCodes`). Core asigna el asesor de cada cliente con `slp_code`, así que un `null` lo dejaría sin asesor. Se llena `slp_code` y `email_asesor`, en este orden: por nombre, cruzando `asesor` («Empleado ventas / compras») con `nombre` de la última carga aceptada de `asesores` de la empresa (recortado y sin importar mayúsculas); si no, con el asesor del mismo `codigo_sn` en la última carga aceptada de `cartera` de la empresa; si no, queda `null` y la vista previa lo advierte.

## Alerta de carga faltante

`php artisan ingesta:verificar-carga` (`Console/CheckDailyLoadCommand`) no hace nada antes de la hora límite (`ingesta.alert_deadline`): lo dice en la consola, no envía nada y termina bien. Desde esa hora revisa, por cada empresa de `ingesta.alert_companies`, que haya una carga de cartera **del robot**, aceptada, con corte de hoy (Bogotá) y recibida hasta ese momento. Si falta:

- sin destinatarios (`INGESTA_ALERT_RECIPIENTS` vacío) solo deja una advertencia en el log y termina bien;
- sin implementación de `MailSender` (la pone Cobro) registra «no se pudo enviar la alerta: MailSender sin implementación» y termina con falla;
- si no, envía por `MailSender` un correo de categoría `alerta` con el asunto «Alerta: el robot no publicó la cartera de {empresa} del {DD/MM/AAAA}»: la hora límite, la última cartera aceptada (corte, origen y recibida), el error del último rechazo del robot de hoy si lo hubo, si ya hay una carga manual con corte de hoy (que no apaga la alerta) y el enlace al historial. Si Graph no acepta el correo (`MailNotSent`), lo registra y termina con falla.

`IngestaServiceProvider::schedule()` la programa cada día a la hora de `INGESTA_ALERT_DEADLINE` (07:00 por defecto) en `America/Bogota`, con `withoutOverlapping(10)`. Correrla a mano es seguro: solo vuelve a avisar.
