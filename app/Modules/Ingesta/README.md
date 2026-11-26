# Ingesta

Recibe los lotes del robot de SAP (`etl/`), los valida contra el esquema de cada lote, los guarda como instantáneas con fecha y avisa a los consumidores con `BatchAccepted`. Implementa el contrato `IngestaSink` (`docs/arquitectura.md` §6.8 y §6.9).

## Endpoint del robot

`POST /api/ingesta/{lote}` (ruta `ingesta.api.lotes.store`), donde `{lote}` es un valor de `Dataset`: `cartera`, `asesores`, `contactos`, `ventas`, `recaudos`, `cruces`, `maestro_clientes` o `maestro_vendedores`. Otro valor responde 404. Límite (`throttle:ingesta-robot`): 60 peticiones por minuto por IP y 120 por minuto entre todas las IP; pasado cualquiera de los dos responde 429. El robot reintenta un 429 esperando lo que diga `Retry-After` (60 s si no viene), hasta 3 veces y 10 minutos de espera en total; después el lote queda fallido y se avisa como cualquier otro fallo.

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

`firma = hex(hmac_sha256(secreto, cadena))`. La ruta firmada es la de la ruta de Laravel que atiende la petición, con sus parámetros puestos: `/api/ingesta/{lote}` para un lote (el robot la arma con `{lote}`) y `/api/ingesta/reportes` para un reporte de falla. El servidor la saca de la plantilla de la ruta, nunca de la URL de la petición, así que un subdirectorio, un proxy o una cadena de consulta no la rompen, y una firma hecha para una ruta no sirve en otra. `VerifyRobotSignature` revisa, antes de descomprimir o leer el JSON, que la llave exista, que el timestamp esté a menos de `INGESTA_HMAC_MAX_SKEW` segundos (300 por defecto) de la hora del servidor y que la firma coincida (`hash_equals`). Cualquier falla responde 401 `{"message": "Firma inválida."}`, el mismo texto para todas, y queda en el log con el motivo, el id de la llave y la IP, nunca con el secreto.

Cada firma sirve una sola vez. Ya verificada, queda en la caché (`ingesta:sig:{firma}`) por el doble de `INGESTA_HMAC_MAX_SKEW`, así que la misma petición exacta enviada otra vez responde 401 y queda en el log con el motivo `replayed`. Solo se guardan firmas válidas, para que nadie sin la llave pueda llenar la caché. Un lote repetido debe firmarse de nuevo, como hace el robot en cada intento; firmado otra vez, responde 200 con `duplicate: true`.

Vector de prueba compartido con el robot (`tests/Modules/Ingesta/Unit/RequestSignerTest.php`): secreto `k2026a-secreto-de-prueba`, timestamp `1792152000`, ruta `/api/ingesta/cartera` y cuerpo `{"rows":[]}` dan `sha256=ba04577457e09c8e9a6bec8983b85a09559f01b466e48a8f8fb2090e3a5b0fc9`. Ese secreto solo prueba la firma: tiene menos de 32 bytes, así que en `INGESTA_HMAC_KEYS` se ignoraría.

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
- `columns`: exactamente los nombres «pg» de `etl/esquema.py` del lote, en cualquier orden y sin repetir (máximo 100; el 422 cita cada columna desconocida hasta 40 caracteres); cada fila de `rows` es una lista con un valor por columna.
- Valores: enteros como número JSON; montos (`NUMERO`, hasta 16 cifras enteras y 2 decimales, el rango de `decimal(18,2)`) y tasas o cantidades (`TASA`, 6 decimales) como texto decimal con punto y sin separador de miles (`"1234567.89"`); fechas `"AAAA-MM-DD"`; texto hasta 4000 caracteres; `null` si falta. Las columnas obligatorias no pueden llegar nulas ni en blanco.
- Un lote con llave natural no puede repetirla; solo `ventas`, `recaudos` y `cruces` pueden llegar vacíos; máximo `ingesta.max_rows` filas (20 000).
- Las columnas y la cantidad de filas se revisan antes de leer cualquier fila: unas columnas distintas de las del lote (faltan o sobran) responden 422 en `columns` y más de `ingesta.max_rows` filas responden 422 en `rows`, sin entrada en el historial. `BatchValidator` vuelve a revisar el tope para los demás orígenes.

`Domain/Schema/DatasetSchemas.php` replica `etl/esquema.py`; `DatasetSchemasTest` lee el archivo de Python y falla si los dos se separan. Si cambias el esquema, cambia ambos.

### Respuestas

| Estado | Cuándo | Cuerpo |
|---|---|---|
| 201 | Carga nueva aceptada | `{"load": {...}, "duplicate": false}` |
| 200 | `batch_id` de una carga ya aceptada | `{"load": {...carga original}, "duplicate": true}` |
| 401 | Firma inválida o ya usada, llave desconocida, timestamp fuera de margen o sin llaves configuradas | `{"message": "Firma inválida."}` |
| 404 | `{lote}` desconocido | estándar |
| 413 | Cuerpo de más de `ingesta.max_request_bytes` (4 MB). Se revisa antes que la firma: primero `Content-Length` y luego el cuerpo. | `{"message": ...}` |
| 422 | Cuerpo mal formado: gzip o JSON inválido, sobre incompleto, columnas distintas de las del lote, más de `ingesta.max_rows` filas, fila con otro número de valores. No queda en el historial. | `{"message": ..., "errors": {...}}` |
| 422 | El lote no cumple el esquema. Queda en el historial como rechazado y su `batch_id` se puede reenviar ya corregido. | `{"message": "El lote tiene N errores.", "errors": {"rows.3.saldo": [...]}}`, máximo 50 llaves |

Una carga nueva aceptada queda en la auditoría como `ingesta.batch.accepted` (sujeto `ingesta_load`, con el lote, la empresa, las filas, el `batch_id` y el id de la llave) cuando hay un `AuditLogger` enlazado; un lote repetido o rechazado no deja entrada.

`load` trae `id`, `dataset`, `company`, `cutoff_date` (`AAAA-MM-DD`), `source`, `status`, `row_count`, `total` y `overdue` (texto con 2 decimales, solo cartera; `null` en los demás), `received_at` (ISO 8601) y `error`. Las filas de los errores se numeran desde 0, como las llaves `rows.N`.

Cada 422 deja en el log una advertencia «Ingesta: rejected a robot batch.» con `dataset`, `company` (solo si es una empresa válida; si no, `null`), `rows` (cuántas filas trae, o `null` si `rows` no es una lista), `reason` (`encoding`, `gzip`, `json`, `columns`, `envelope`, `too_many_rows`, `rows` o `schema`; `schema` es todo rechazo de `BatchValidator`, también un `batch_id` mal formado o un corte futuro, y `fields` dice cuál), `fields` (hasta 10 llaves de error, como `rows.12.saldo`) y `key_id` (el id de la llave que firmó). Nunca lleva datos del lote: ni valores de las filas, ni mensajes de error (algunos citan valores, como la llave repetida), ni el `batch_id`.

Los topes salen de los lotes reales: el más grande trae 1458 filas (ventas de SGX) y los maestros, hasta 6178. 20 000 filas de cartera, el lote más ancho (38 columnas), ocupan unos 15,8 MB de JSON y 1,8 MB en gzip, y decodificarlas usa unos 86 MB de memoria. Sin gzip, los 4 MB alcanzan para unas 5000 filas de cartera. El gzip descomprimido no puede pasar de `ingesta.max_payload_bytes` (16 MB). `ingesta.max_rows` también limita el Excel de la carga manual. El `post_max_size` de PHP en el servidor debe ser al menos `ingesta.max_request_bytes`.

## Reporte de fallas de carga

`POST /api/ingesta/reportes` (ruta `ingesta.api.reportes.store`): el robot avisa que no pudo cargar un lote. Usa el mismo límite (`throttle:ingesta-robot`) y la misma firma que los lotes (encabezados, llaves, margen del timestamp y firma de un solo uso), firmada para la ruta `/api/ingesta/reportes`; una firma de lote no sirve aquí ni al revés. Un reporte final (`final: true`) además envía la alerta por correo y el aviso en la campana (ver «Alerta de carga fallida» y «Aviso en la campana»).

Cuerpo: un objeto JSON sin comprimir (`Content-Encoding` vacío o `identity`) de hasta 8 KB (`ReceiveLoadFailureController::MAX_BYTES`):

```json
{
  "report_id": "6f1c2b0e-4d3a-4f5e-9a7b-1c2d3e4f5a6b",
  "run_id": "0a9b8c7d-6e5f-4a3b-8c2d-1e0f9a8b7c6d",
  "company": "SGX",
  "dataset": "cartera",
  "slot": "2026-11-23T05:00:00-05:00",
  "stage": "HANA",
  "cause": "ruta_hana",
  "transient": true,
  "attempt": 3,
  "max_attempts": 3,
  "final": true,
  "message": "No hay ruta a HANA tras 60 s",
  "occurred_at": "2026-11-23T05:21:10-05:00"
}
```

- `report_id`: UUID, la llave de idempotencia del reporte. `run_id`: UUID de la corrida, el mismo en todos sus intentos.
- `company`: `SGX` o `SDC`. `dataset`: un valor de `Dataset`.
- `slot` (la hora programada de la carga) y `occurred_at`: ISO 8601 con desfase (`2026-11-23T05:00:00-05:00`, con o sin microsegundos). Se guardan en la zona de la app.
- `stage`: la etapa de `etl/run_etl.py` (`FailureStage`): `CONFIGURACION`, `INICIO`, `VPN`, `HANA`, `VALIDACION`, `RESPALDO`, `CONTRATO`, `LARAVEL` o `SUPABASE`.
- `cause` (`FailureCause`): `vpn`, `ruta_hana`, `red`, `credenciales`, `datos`, `configuracion` o `inesperado`.
- `transient` (un reintento puede funcionar) y `final` (último intento, no habrá otro): booleanos.
- `attempt`: entero desde 1. `max_attempts`: entero, al menos `attempt` y máximo 10.
- `message`: texto obligatorio de hasta 2000 caracteres. `RecordLoadFailure` le quita los caracteres de control e invisibles, junta los espacios y lo corta a 300 caracteres.
- El origen (`source`) lo pone el servidor: `robot`. Si el cuerpo trae `source`, se ignora.

| Estado | Cuándo | Cuerpo |
|---|---|---|
| 201 | Reporte nuevo guardado | `{"report_id": "...", "status": "recorded", "duplicate": false}` |
| 200 | `report_id` ya guardado; no se guarda otra vez | `{"report_id": "...", "status": "recorded", "duplicate": true}` |
| 401 | Firma inválida, ya usada o hecha para otra ruta | `{"message": "Firma inválida."}` |
| 413 | Cuerpo de más de 8 KB | `{"message": ...}` |
| 422 | Cuerpo comprimido o que no es un objeto JSON (`body`), o un campo inválido | `{"message": ..., "errors": {...}}` |

Cada reporte recibido deja en el log «Ingesta: received a robot failure report.» con el `report_id`, la empresa, el lote, la etapa, la causa, `final` y `duplicate`, sin el mensaje. Cada 422 deja «Ingesta: rejected a robot failure report.» con los campos que fallaron y el id de la llave, nunca un valor.

Se guardan en `ingesta_load_failures`, uno por `report_id` (índice único, así que dos copias simultáneas guardan uno solo), con índice por empresa, lote y hora programada. `RecordLoadFailure` es la única entrada: hoy la usa el endpoint y después la usará el lector de Bases SAP, con el origen `bases_sap`.

### Alerta de carga fallida

Cuando `RecordLoadFailure` guarda un reporte nuevo con `final: true` (ya no habrá otro intento), pone en la cola `SendLoadFailureAlert` (después del commit si hay una transacción abierta). Un reporte que no es final, o un `report_id` repetido, no pone nada. El trabajo:

- sin destinatarios (`INGESTA_ALERT_RECIPIENTS` vacío) solo deja una advertencia en el log; sin implementación de `MailSender`, un error. En ninguno de los dos casos marca la hora programada, así que un reporte final posterior sí puede avisar;
- si no, reclama la alerta con un insert-or-ignore en `ingesta_load_alerts` (empresa, lote, hora programada y tipo `falla`, únicos juntos). Si la fila ya existía, otro reporte final de la misma hora ya avisó y no envía nada: **un correo por hora programada**, sin importar cuántos reportes finales lleguen;
- envía desde el buzón de Segurex un correo de categoría `alerta` a `ingesta.alert_recipients`, con el asunto «Alerta: falló la carga de {lote} de {empresa} de las {HH:MM} del {DD/MM/AAAA}» (hora de Bogotá): qué hacer según la causa (`vpn` y `ruta_hana`, `red`, `credenciales`, `datos`; otra causa nombra la etapa), y el detalle: etapa, intento, cuándo pasó y el mensaje guardado. Los textos están en `resources/lang/es/alerta.php` (`falla`).

El reclamo y el envío van en una sola transacción: si Graph no acepta el correo (`MailNotSent`) o el worker se cae a mitad, el reclamo se deshace y el reintento todavía puede enviar. El trabajo se intenta hasta 3 veces, a 1 y 5 minutos; si los tres fallan, lo deja en el log como error.

### Aviso en la campana

Antes del correo, el mismo trabajo deja la falla en la campana de la plataforma (`AvisoService`, `Application/Failures/LoadNotices`): tipo `ingesta.carga`, llave `ingesta.carga:{empresa}:{lote}` (p. ej. `ingesta.carga:SGX:cartera`), con la empresa, el título «Falló la carga de {lote} de {empresa} de las {HH:MM}» (hora de Bogotá; `alerta.php`, `campana`), el texto de la causa del correo como cuerpo y, en `data`, el lote, la hora programada, la causa y la etapa.

- **Quién lo recibe:** los usuarios activos cuyo correo está en `INGESTA_ALERT_RECIPIENTS` (sin importar mayúsculas ni espacios) y los usuarios activos con el rol `admin`, una vez cada uno. No depende del correo: llega aunque no haya destinatarios de correo, falte `MailSender` o Graph rechace el envío. Si nadie lo recibe, o falta `AvisoService`, solo lo deja en el log.
- **Una vez por hora programada:** tiene su propio reclamo en `ingesta_load_alerts` (tipo `campana`) y su propia transacción, que se confirma antes de intentar el correo. Si el correo falla, el reintento encuentra la campana ya reclamada y no la repite. Si la campana falla, queda en el log y el correo sale igual.
- **Cuándo se resuelve:** `IngestaServiceProvider` escucha `BatchAccepted`; cualquier carga aceptada de la misma empresa y lote (del robot o manual, y después la del lector de Bases SAP) resuelve todos los avisos abiertos con esa llave, sea cual sea la hora programada. Un lote repetido de una carga ya aceptada (el robot manda el mismo lote mientras SAP no cambia) también los resuelve, sin volver a emitir `BatchAccepted`; si la campana falla, queda en el log y el robot recibe su 200 igual.
- **A dónde lleva:** al historial de cargas filtrado por el lote y la empresa del aviso (`/ingesta?lote=cartera&empresa=SGX`), para quien tiene `ingesta.view`; a los demás no les da enlace.

## Rotación de llaves

`INGESTA_HMAC_KEYS` admite varias parejas `id:secreto` separadas por comas (el secreto puede tener `:`; se parte en el primero). Cada secreto debe tener al menos 32 bytes; genera uno con `php -r "echo bin2hex(random_bytes(32));"`. Para rotar: agrega la llave nueva junto a la vieja, cambia el robot a la nueva y, cuando ya firme con ella, quita la vieja. Una pareja mal formada o con un secreto de menos de 32 bytes se ignora con una advertencia en el log, que da su posición (y el id de la llave, si el secreto es corto), nunca el secreto. Sin ninguna llave válida, el endpoint responde 401 a todo y lo registra como error.

## Almacenamiento

- `ingesta_loads`: el historial, aceptadas y rechazadas, con el corte, el origen, el usuario de una carga manual, los totales de cartera (`decimal(18,2)`, sumas exactas en centavos), el orden de columnas guardado y, en las rechazadas, el resumen y los errores por campo. `accepted_batch_id` es único y solo se llena en las aceptadas: así un rechazo se puede reenviar y dos copias simultáneas del mismo lote no crean dos cargas. `last_seen_at` guarda la última vez que llegó el lote de una carga aceptada, repetidos incluidos (solo avanza); en las cargas anteriores a esa columna queda vacío y vale `received_at`.
- `ingesta_load_failures`: los reportes de falla de carga, uno por `report_id` (ver «Reporte de fallas de carga»).
- `ingesta_load_alerts`: las alertas enviadas, una por empresa, lote, hora programada y tipo (`falla` para el correo, `campana` para el aviso en la campana; `no_llego` queda para la alerta de carga que no llegó), con el reporte final que la causó (ver «Alerta de carga fallida» y «Aviso en la campana»).
- `ingesta_load_chunks`: las filas de cada carga aceptada en bloques de 500 (`ChunkCodec`: JSON de listas de valores, gzip y base64). Las instantáneas nunca se sobrescriben.

## Cómo leen los consumidores

Escuchan `BatchAccepted`, que se despacha una vez por carga aceptada y después del commit (la cola `database` no espera commits), filtran por `$event->load->dataset` y recorren `app(IngestaSink::class)->rows($event->load->id)`. Las filas llegan en el orden recibido, como arreglos asociativos en el orden de columnas del esquema, y se leen un bloque a la vez. `latest()` da la instantánea vigente de un lote y empresa; `history()`, el historial más reciente primero.

## Permisos

| Permiso | Roles | Abre |
|---|---|---|
| `ingesta.view` | `admin`, `gerente_cartera`, `analista_cartera` | El historial de cargas |
| `ingesta.upload` | `admin`, `gerente_cartera`, `analista_cartera` | La carga manual del Excel de cartera |

## Historial de cargas

`GET /ingesta` (ruta `ingesta.index`, menú «Cargas de datos» en Administración): las cargas, aceptadas y rechazadas, de las empresas de `CurrentCompany::available()` (todas las del usuario, no solo la activa), 25 por página y la más reciente primero, con filtros por lote, empresa y estado (`?lote=cartera&empresa=SGX&estado=rechazada`; un valor desconocido o una empresa que el usuario no ve no filtra; el filtro de empresa solo ofrece las suyas). Arriba muestra la cartera vigente (`IngestaSink::latest()`) de cada empresa de `ingesta.alert_companies` que el usuario ve y, pasada la hora límite (`ingesta.alert_deadline`, 07:00 en Bogotá), un aviso por cada una que aún no tenga una carga de cartera **del robot**, aceptada y con corte de hoy (`DailyCarteraCheck`). Una carga manual no apaga el aviso: mantiene la plataforma al día, pero el robot igual necesita revisión.

`GET /ingesta/cargas/{id}` (`ingesta.cargas.show`): los datos de una carga; si se rechazó, el resumen y los mensajes por campo (las filas se numeran desde 0, como en los mensajes); si es una cartera aceptada, el saldo total y el vencido. Un id que no existe, o una carga de una empresa que el usuario no ve, responde 404.

`ingesta.alert_companies` trae solo `SGX`: el robot lee únicamente el esquema SEGUREX de HANA; SDC carga su cartera con el Excel manual hasta que se configure su esquema de SAP.

## Carga manual de cartera

Respaldo del robot, solo para el lote `cartera`. Desde el historial, «Carga manual» abre `GET /ingesta/carga-manual` (`ingesta.manual.create`, permiso `ingesta.upload`):

1. **Formulario.** Empresa (solo las de `CurrentCompany::available()`), fecha de corte (por defecto hoy, nunca posterior) y el Excel `.xlsx` «Cartera del día» de SAP, hasta 20 MB (`upload_max_filesize` y `post_max_size` de PHP deben permitirlo).
2. **Vista previa** (`POST /ingesta/carga-manual`, `ingesta.manual.preview`). Se lee la hoja «Datos» o, si no existe, la primera. La fila de títulos es la primera de las 20 primeras que tenga «Código SN» y «Nit»; los títulos se cruzan con el alias de HANA de cada columna (`Column::sourceHeader`), sin importar espacios ni mayúsculas (las tildes sí cuentan). Sin «Código SN», «Nit», «Documento», «Fecha vencimiento» o «Saldo» no hay vista previa. Los títulos desconocidos se ignoran y se listan; se saltan las filas vacías y las que no tienen ni código ni documento (como la fila de totales). Un archivo con más de `ingesta.max_rows` filas de datos (20 000) se rechaza con «El archivo tiene más de N filas»; además, la lectura se detiene después de `ingesta.max_rows` más 20 filas de la hoja, contando las vacías. La página muestra filas, clientes distintos, saldo total y vencido, las 20 primeras filas, cuántas quedaron sin código de asesor y, si `BatchValidator` encuentra errores, hasta 50 con su fila de Excel; con errores no se puede confirmar.
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

## Vigilante de cargas

`php artisan ingesta:vigilar-carga {hora}` (`Console/WatchLoadCommand`, `Application/Watch/WatchLoadSlot`) revisa una hora programada de carga. Por cada empresa de `ingesta.watch_companies` (por defecto solo `SGX`: SDC carga su cartera por Excel), si **desde esa hora** no llegó una carga de cartera aceptada de **cualquier origen** (robot, carga manual y después el lector de Bases SAP) **ni** un reporte de falla con `final: true` de esa misma hora (ese ya tiene su propia alerta), avisa. Un lote repetido de una carga ya aceptada, recibido desde esa hora, cuenta como llegada (`last_seen_at`): el robot manda el mismo lote cuando SAP no cambió, y eso prueba que la carga funciona. El aviso va así:

- **Campana:** el aviso `ingesta.carga` «No llegó la carga de {lote} de {empresa} de las {HH:MM}», con la misma llave `ingesta.carga:{empresa}:{lote}`, así que la siguiente carga aceptada lo resuelve. Usa el mismo reclamo `campana` que la alerta de carga fallida: **una campana por hora programada**, sea cual sea el motivo.
- **Correo:** a `ingesta.alert_recipients`, desde el buzón de Segurex, categoría `alerta`, asunto «Alerta: no llegó la carga de {lote} de {empresa} de las {HH:MM} del {DD/MM/AAAA}», con el enlace al historial filtrado. Lo reclama con el tipo `no_llego` en la misma transacción del envío: si Graph lo rechaza (`MailNotSent`), el reclamo se deshace y el comando termina con falla, así que la siguiente corrida todavía puede enviarlo. Sin destinatarios solo deja una advertencia; sin `MailSender`, un error y termina con falla.

Correrlo dos veces para la misma hora no envía nada nuevo. Con una hora que no está en `etl/horarios.json` termina con falla; un día en que esa hora no corre, o antes de la hora, no revisa nada.

**Textos** (`alerta.php`, `no_llego`), según `ingesta.expected_source` (`INGESTA_EXPECTED_SOURCE`):

- `robot` (por defecto): «No llegó la carga de las {HH:MM} ni un reporte del robot: el robot no corrió (¿PC apagado, sin sesión o sin internet?).»
- `bases_sap`: «No llegó la carga de las {HH:MM}: el lector de Bases SAP no corrió.»
- Si la hora tiene `antes_de`, se agrega: «Si no llega a tiempo, {antes_de} saldrá con los datos anteriores.»

### `etl/horarios.json`

Las horas del robot, versionadas con el código; `scripts/instalar_tarea.ps1` lee el mismo archivo para crear los disparadores de Windows. `ingesta.load_slots` guarda solo la ruta; el archivo se lee cuando se necesita y, si no existe, valen las cuatro horas por defecto (`Domain/Watch/LoadSlots::DEFAULT`):

```json
{ "zona": "America/Bogota", "vigilante_min": 30, "horarios": [
  {"hora": "00:00"}, {"hora": "05:00"}, {"hora": "12:00"},
  {"hora": "16:00", "dias": ["tue", "fri"], "antes_de": "el cobro semanal de las 17:00"} ] }
```

- `hora`: HH:MM, en la `zona`; `dias` (opcional): nombres de tres letras en inglés y minúsculas (`mon` … `sun`), sin `dias` corre todos los días; `antes_de` (opcional): lo que usa esa carga.
- `vigilante_min`: minutos después de cada hora en que corre el vigilante (de 1 a 180). El vigilante debe caer el mismo día: 00:00 se revisa a las 00:30 de ese día.
- Se valida estricto: una hora, un día, una zona o unos minutos inválidos, una hora repetida o un vigilante después de medianoche lanzan un error que dice qué corregir. Con un archivo inválido el comando falla y `schedule()` no programa el vigilante (lo deja en el log), sin tocar las demás tareas.

`IngestaServiceProvider::schedule()` programa `ingesta:vigilar-carga {hora}` a la hora + `vigilante_min` (hoy 00:30, 05:30 y 12:30 a diario y 16:30 los martes y viernes, antes del cobro semanal de las 17:00), en la `zona`, con `withoutOverlapping(10)`.
