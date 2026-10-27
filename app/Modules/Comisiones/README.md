# Comisiones

Liquidación mensual de comisiones de Segurex (SGX): el analista calcula con las ventas, recaudos y cruces de SAP, el presupuesto y las capturas manuales; el aprobador revisa y aprueba; se cierra el período y salen el consolidado, los PDF de recaudo y el archivo de nómina. Todo queda en la bitácora del módulo (`/comisiones/auditoria`).

## Dónde está cada cosa

| Qué | Dónde |
|---|---|
| Reglas de negocio (fuente de verdad) | [docs/especificaciones/liquidacion-comisiones.md](../../../docs/especificaciones/liquidacion-comisiones.md) |
| Motor de cálculo, puertos y casos de uso | [Application/README.md](Application/README.md) (en inglés) |
| Configuración | `config/comisiones.php`, leída como `config('comisiones.*')` |

## Al desplegar

Dale a Tatiana el rol que aprueba. La pantalla Equipo de Core todavía no asigna los roles del módulo, así que se hace por consola en el servidor:

```bash
php artisan comisiones:rol tatiana.munoz@segurex.com comisiones_aprobador
```

1. **Antes**, el usuario debe existir y estar activo. Se crea solo la primera vez que entra con su cuenta de Microsoft (Entra ID), sin rol; también lo puede registrar un administrador en `/equipo/nuevo`. Si no existe o está desactivado, el comando responde «No hay un usuario activo con el correo …» y no cambia nada.
2. Corre el comando. Responde «Asignaste el rol comisiones_aprobador a tatiana.munoz@segurex.com.» y deja `comisiones.role.assigned` en la bitácora.
3. Al entrar, la página de inicio la lleva a Comisiones. Los dos roles del módulo traen `core.access` (lo da `ComisionesPermissionSeeder`), así que no necesita un rol de Core.

| Para | Comando |
|---|---|
| Quitar el rol | `php artisan comisiones:rol tatiana.munoz@segurex.com comisiones_aprobador --quitar` (bitácora: `comisiones.role.removed`) |
| Dar acceso de solo consulta | `php artisan comisiones:rol <correo> comisiones_consulta` |

El comando solo acepta `comisiones_aprobador` y `comisiones_consulta`; los roles de Core (por ejemplo `gerente_cartera`) se asignan en Equipo.

## Quién hace qué

Permisos de `ComisionesPermissionSeeder`. Quien calcula o captura nunca aprueba (§10).

| Permiso | Qué permite | Roles |
|---|---|---|
| `comisiones.view` | Tablero, liquidaciones, consolidado y controles | `gerente_cartera`, `comisiones_aprobador`, `comisiones_consulta` |
| `comisiones.calculate` | Calcular, recalcular y registrar complementarias | `gerente_cartera` |
| `comisiones.capture` | Presupuesto, OBR, reintegros y descuentos, cruces manuales y reasignaciones | `gerente_cartera` |
| `comisiones.approve` | Aprobar liquidaciones y capturas | `comisiones_aprobador` |
| `comisiones.close` | Cerrar el período | `gerente_cartera`, `comisiones_aprobador` |
| `comisiones.parameters` | Agregar versiones de parámetros | `admin`, `gerente_cartera` |
| `comisiones.reports` | PDF de recaudo, ZIP y archivo de nómina | `gerente_cartera`, `comisiones_aprobador` |
| `comisiones.audit` | La bitácora | `admin`, `gerente_cartera`, `comisiones_aprobador` |
| `core.access` | Entrar a la plataforma (Core lo da a sus roles; el módulo, a los suyos) | `comisiones_aprobador`, `comisiones_consulta` |

Además de los permisos, `ApprovalGuard` no deja aprobar a quien cargó capturas o cambió parámetros del período, y el motor no deja aprobar a quien calculó la versión.

## Soportes de las capturas

Cada OBR, ajuste, cruce manual o reasignación lleva un soporte en PDF, JPG, PNG, XLSX o CSV de hasta 10 MB (`CaptureFields::support()`). Además de la extensión, la regla `SupportFile` revisa el contenido:

- El tipo real del archivo (lo que lee finfo, nunca lo que dice el navegador) debe corresponder a la extensión: un HTML renombrado como `.pdf` se rechaza.
- En un CSV, ninguna celda puede empezar por `=`, `+`, `-` o `@` (después de los espacios, tabulaciones o saltos de línea iniciales), porque Excel la ejecutaría como fórmula al abrirla. Los números sí pasan: `-5.000,50`, `$ -5.000`, `-5000` o `+5000`. El error dice la fila y la columna de la primera celda, sin repetir su contenido. Se lee todo el archivo, en `;`, `,` o tabulación, en UTF-8 (con o sin BOM) o Windows-1252.
- Las fórmulas dentro de un XLSX no se revisan.

El soporte se guarda en el disco privado con la extensión que trae y se descarga como `application/octet-stream`, con el nombre `soporte-{tipo}-{id}.{ext}`.

## Informes de una versión cerrada

En `/comisiones/periodos/{periodo}/informes` primero se genera y después se descarga:

1. **Generar.** El botón «Generar los informes pendientes» (`POST …/informes/generar`) guarda los PDF de recaudo que faltan y el archivo de nómina. Cada documento se guarda una sola vez, con su sha256, y deja `comisiones.report.generated` en la bitácora. Un PDF cuyo total no cuadra con el consolidado (§9.3) no se genera.
2. **Descargar.** El PDF de cada código (`…/informes/{referencia}.pdf`), el ZIP (`…/informes.zip`) y la nómina (`…/informes/nomina.xlsx`) solo leen lo guardado, revisan su sha256 y dejan `comisiones.report.downloaded`. Si el documento todavía no existe (o, para el ZIP, falta alguno), te devuelven a la lista con «Ese informe todavía no se ha generado…» y no generan nada ni escriben en la bitácora. La lista muestra cada enlace de descarga solo cuando su documento ya está guardado.

Los PDF los dibuja dompdf sin JavaScript, sin archivos remotos y con un `chroot` que solo deja leer sus propias fuentes (`vendor/dompdf/dompdf/lib/fonts`): las plantillas no cargan imágenes ni archivos locales.

Todas las descargas del módulo (PDF, ZIP y nómina de los informes, consolidado en Excel y PDF, soportes de las capturas y plantilla del presupuesto) comparten un máximo de 10 por minuto por usuario (`comisiones-exports`); la siguiente recibe un 429.

## Huella del resultado guardado

Cada versión guarda su resultado serializado en `comisiones_liquidaciones.resultado` y su huella en `resultado_sha256`: un HMAC-SHA256 con una clave derivada de `APP_KEY`, no un sha256 simple, así que quien pueda escribir en la tabla sin conocer `APP_KEY` no logra que la app acepte un resultado alterado. Una huella que no coincide se rechaza (`CorruptSettlement`) y la versión no se usa.

**Si rotas `APP_KEY`**, pon la clave anterior en `APP_PREVIOUS_KEYS` (separadas por coma si son varias). La app verifica las huellas con la clave actual y después con cada clave anterior, así que las liquidaciones cerradas se siguen leyendo; lo que se guarda desde entonces lleva la clave nueva. Si quitas de `APP_PREVIOUS_KEYS` una clave con la que hay versiones guardadas, esas versiones dejan de leerse.

## Bitácora

Cada evento del módulo es una fila de `comisiones_bitacora` (la escribe solo `AuditTrail`) y una entrada de la auditoría de Core. La bitácora solo agrega:

- `AuditRecord` lanza `LogicException` si intentas guardar cambios en un evento o borrarlo, por cualquier camino del modelo: `save()`, `update()`, `saveQuietly()`, `delete()`, `deleteQuietly()`, `forceDelete()`, `destroy()` y también dentro de `withoutEvents()`.
- Las escrituras del constructor de consultas no pasan por el modelo: nunca uses `update()` ni `delete()` masivos sobre `AuditRecord::query()` o `DB::table('comisiones_bitacora')`.
- La llave foránea de `user_id` es `restrictOnDelete()`: borrar un usuario nunca cambia la bitácora. Los usuarios se borran de forma lógica; el borrado definitivo (`forceDelete()`) de un usuario con eventos falla con una `QueryException`.

## Correo al aprobar

Cuando el aprobador aprueba una liquidación (`POST /comisiones/periodos/{periodo}/aprobar`), sale un correo con el consolidado de la versión aprobada. Se envía **en segundo plano**: la aprobación pone en la cola un trabajo `SendApprovalMail` y el aprobador no espera el PDF ni a Microsoft Graph.

| Qué | Cómo |
|---|---|
| Cuándo | Solo al aprobar. Ni al calcular, ni al cerrar, ni cuando la aprobación se rechaza. Sale cuando corre el worker de la cola, el mismo que envía el cobro de Cobro: `queue:work`, que el programador lanza cada minuto (necesita el cron de `schedule:run`). |
| Cuántas veces | Una. El trabajo no se reintenta (`$tries = 1`): un reintento después de un envío que Graph aceptó mandaría el correo dos veces. Si la versión se recalculó antes de que el trabajo corra, no se envía. |
| Desde | El buzón de Segurex (`COBRO_MAILBOX_SGX`), por el contrato `MailSender` que implementa Cobro. |
| Para | `config('comisiones.approval_mail.recipients')`: hoy Marcela Sanabria y Mónica Pinzón. Para cambiarlos, edita esa lista en `config/comisiones.php`. |
| Asunto | «Liquidación de comisiones aprobada – julio de 2026». |
| Cuerpo | Período, versión y revisión, quién aprobó y cuándo, total del equipo regular y total para nómina, y un enlace a la liquidación. |
| Adjunto | El mismo PDF que descarga `GET …/consolidado.pdf`, por ejemplo `Consolidado_Comisiones_2026-07_v1.pdf`. Lo arma `Infrastructure/Report/ConsolidatedPdf`. |
| Si falla | La aprobación **no** se deshace. El fallo queda en el registro y en la bitácora como «Correo de aprobación no enviado» (`comisiones.settlement.mail_failed`), con el motivo en `fallo`: `sin_destinatarios`, `sin_mail_sender`, `no_enviado` (Graph lo rechazó), `recalculada` o `error` (falló el PDF, la vista o el trabajo; queda reportado). La pantalla del período lo muestra en «Quién hizo qué»: «No se pudo enviar el correo de aprobación (…); descarga el consolidado y envíalo.» |

Un envío correcto queda en la bitácora como «Correo de aprobación enviado» (`comisiones.settlement.mailed`), con los destinatarios, y la pantalla del período dice «Correo de aprobación enviado a … el …». Mientras el trabajo espera en la cola, la pantalla no dice nada del correo.

**Respuestas.** Si Marcela o Mónica responden, la respuesta llega al buzón de Segurex, no al aprobador. La sincronización de Cobro la trae a la bandeja «Respuestas de clientes» (`/cobro/respuestas`, empresa Segurex) como «Sin cliente identificado», porque el correo no es de un cliente. Quien la lea allí debe pasársela a Tatiana.
