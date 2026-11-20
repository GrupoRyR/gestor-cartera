# Cobro

Correo de cobro por Microsoft Graph desde el buzón de cada empresa: cobro semanal de facturas vencidas (4.1), circularización mensual al cierre (4.2), lista de exclusión, plantilla editable, informe de entrega y bandeja de respuestas. Implementa los contratos `MailSender` y `MailboxReader` (`docs/arquitectura.md` §6.10 y §6.11).

## Pantallas

| Ruta | Nombre | Permiso | Qué hace |
|---|---|---|---|
| `GET /cobro/semanal` | `cobro.semanal.show` | `cobro.view` | Vista previa del próximo envío: quién lo recibe, quién no tiene correo, quién está excluido y cuándo sale. |
| `GET /cobro/semanal/{empresa}/correo/{cliente}` | `cobro.semanal.correo` | `cobro.view` | El correo de un cliente tal como saldría. |
| `POST /cobro/semanal/{empresa}/prueba` | `cobro.semanal.prueba` | `cobro.manage` | Envía el correo de un cliente, marcado como prueba, solo al usuario. |
| `GET /cobro/circularizacion` (y las mismas subrutas) | `cobro.circularizacion.*` | igual | Lo mismo para la circularización. |
| `GET /cobro/respuestas` | `cobro.respuestas.index` | `cobro.replies` | Respuestas y rebotes, pendientes o leídos; se marcan con casillas. |
| `GET /cobro/respuestas/{mensaje}/outlook` | `cobro.respuestas.outlook` | `cobro.replies` | Abre el mensaje en Outlook en la web (ver «Abrir en Outlook»). |
| `POST /cobro/respuestas/{mensaje}/responder` | `cobro.respuestas.reply` | `cobro.replies` | Responde al cliente desde el buzón de la empresa (ver «Responder desde la app»). |
| `GET /cobro/lotes` | `cobro.lotes.index` | `cobro.view` | Informes de envío: enviados, rebotados, sin correo, excluidos y no enviados. |
| `GET /cobro/lotes/{run}` | `cobro.lotes.show` | `cobro.view` | Un envío cliente por cliente, con el motivo del rebote leído del buzón. |
| `GET /cobro/exclusiones` | `cobro.exclusiones.index` | `cobro.manage` | Lista de exclusión con casillas, motivo y fecha de vencimiento opcional. |
| `GET /cobro/plantillas` | `cobro.plantillas.edit` | `cobro.manage` | Asunto y textos del cobro semanal por empresa, con datos como `{cliente}`. |
| `GET /cobro/copias` | `cobro.copias.edit` | `cobro.manage` | Copias (CC) del cobro semanal y de la circularización por empresa: correos fijos, el asesor del cliente y los gerentes de cada grupo de SAP. |

Permisos (`CobroPermissionSeeder`): `cobro.view` para administrador, gerente y analista de cartera; `cobro.manage` y `cobro.replies` para gerente y analista.

## Tareas programadas y colas

| Comando | Cuándo (hora de Colombia) | Qué hace |
|---|---|---|
| `cobro:sincronizar-buzones` | Cada 5 minutos | Lee con una consulta delta de Graph los correos nuevos de la bandeja de entrada de cada buzón, los une al envío y al cliente, y registra la respuesta en la bitácora. Una empresa sin credencial de Graph (propia ni común) solo avisa y se salta; la otra se sigue leyendo. |
| `cobro:cobro-semanal` | Miércoles 17:00 (`cobro.weekly`) | Para Segurex y Derivados, cada una con su plantilla y sus copias: prepara el envío y pone en cola un trabajo `SendRunItem` por cliente. Correrlo de nuevo el mismo día no envía nada. |
| `cobro:circularizacion` | Quinto día hábil del mes a las 09:00 (`cobro.circularizacion.business_day`) | Igual, una vez al mes, para Segurex y Derivados: **envía de verdad** con la foto del cierre del mes anterior (ver «Circularización»), pero no envía una empresa cuya foto del cierre no haya llegado; `--con-corte-actual` la envía con la última carga. Solo si se vuelve a poner `cobro.circularizacion.draft` en `true` deja de enviar; entonces `--aun-en-borrador` permite probar el motor. |
| `cobro:cerrar-envios-interrumpidos` | Cada 10 minutos | Marca «No se pudo enviar» a los clientes que un proceso interrumpido dejó «enviando» más de 30 minutos, para que el envío se cierre y el informe lo muestre. |
| `cobro:diagnosticar-rebote` | A mano | Muestra cómo la regla de rebotes clasifica un mensaje del buzón, sin guardar nada (ver «Qué cuenta como rebote»). |

**Derivados entra al cobro semanal**: el primer miércoles después de desplegar sale de verdad para sus clientes vencidos desde `COBRO_MAILBOX_SDC`. Antes, confirma que ese buzón está en el alcance de Graph (ver «Microsoft Graph») y envíate una prueba desde `/cobro/semanal`.

**Clientes en jurídico**: ni el cobro semanal ni la circularización mensual le escriben a un cliente en estado «Jurídico», en ninguna empresa. Queda en el envío como «Excluido», con el motivo «Cliente en cobro jurídico», en la vista previa y en el informe, sin agregarlo a la lista de exclusión. El estado se lee al preparar el envío.

Cada trabajo reclama su cliente antes de enviar (pendiente → enviando), así que un reintento nunca le manda dos correos al mismo cliente. Los trabajos salen escalonados a `cobro.send_per_minute` correos por minuto (25 por defecto; Exchange Online acepta 30 por minuto por buzón) y el limitador `cobro-mail` queda de respaldo: 300 clientes tardan unos 12 minutos en salir. El informe muestra en español por qué no salió un correo; el detalle técnico queda en el registro.

## Cómo se unen las respuestas

`GraphMailSender` crea un borrador en el buzón de la empresa y lo envía, porque `sendMail` no devuelve identificadores. Así guarda el `internetMessageId` y el `conversationId` de cada envío en `cobro_sent_messages`. La sincronización une cada mensaje recibido, en este orden:

1. por la misma conversación de Graph;
2. si es un rebote, por el `Message-ID` del envío que cita en su texto;
3. si es una respuesta, por el remitente, al último envío a esa dirección en los últimos 90 días.

Un rebote unido marca su cliente como «Rebotado» en el informe, con el código SMTP y la dirección que falló. Si el informe nombra varias direcciones del envío, cuenta primero la del cliente (Para) y después las copias. Si la dirección que falló es una copia (CC), el cliente sí recibió el correo: queda «Enviado» y la dirección queda en el registro.

### Qué cuenta como rebote

El remitente y el asunto no prueban nada: cualquiera puede escribir «Undeliverable: …» desde cualquier dirección (hallazgo S-24 de la auditoría). Por eso `BounceDetector::classify()` exige que Exchange le haya dado al mensaje la clase de un informe de no entrega, `REPORT.<clase>.NDR`. Exchange solo la pone cuando el correo trae de verdad la estructura DSN (un `multipart/report` con su parte `message/delivery-status`); una respuesta común queda como `IPM.Note`. Además, el mensaje debe ser uno de estos dos:

1. **Un informe de nuestro Exchange**: el encabezado `X-MS-Exchange-Organization-AuthAs` dice `Internal` (Exchange lo borra en el correo que entra de afuera), el remitente es `postmaster` o `MicrosoftExchange` seguido de 32 dígitos hexadecimales, y su dominio es exactamente el de uno de los buzones de `cobro.mailboxes` (hoy `segurex.com` y `derivadoscol.com`) o uno de `COBRO_TENANT_DOMAINS` o de los dominios del inquilino de la empresa del buzón (`COBRO_TENANT_DOMAINS_SGX` o `COBRO_TENANT_DOMAINS_SDC`); un subdominio no cuenta.
2. **Un informe de otro servidor**: el remitente es un buzón de sistema (`postmaster`, `mailer-daemon`, `mail-daemon` o `mail.delivery.subsystem` y sus variantes sin puntos) de cualquier dominio y el texto cita el `Message-ID` de uno de nuestros envíos. Si solo coincide la conversación y no cita ningún `Message-ID`, no es un rebote.

Los dos marcan el envío como «Rebotado» sin más confirmación. Todo lo demás es una respuesta normal: un asunto «Undeliverable:» con clase `IPM.Note`, los avisos de retraso (`*.Delayed.DR`), las confirmaciones de entrega (`*.DR`) y las respuestas automáticas.

- **Los rebotes en texto plano ya no se detectan.** Un servidor que avisa del fallo con un correo común, sin la estructura DSN, queda como una respuesta normal en la bandeja; alguien debe leerlo y revisar el envío a mano.
- **Cómo se lee:** la consulta delta pide la clase de cada mensaje con `$expand=singleValueExtendedProperties($filter=id eq 'String 0x001A')`. Solo cuando la clase es de no entrega, la sincronización pide los encabezados del mensaje (`GET /users/{buzón}/messages/{id}?$select=internetMessageHeaders`). Si ese GET falla, el remitente cuenta como no autenticado: no puede ser un informe de nuestro Exchange, pero sí uno de otro servidor. Un mensaje sin clase queda como respuesta. Los dos casos dejan un `warning` en el registro con el buzón y el id de Graph, nunca con el contenido.
- **El dominio del inquilino:** si Exchange firma sus informes con el dominio de Microsoft 365 (`postmaster@gruporyr.onmicrosoft.com`), ponlo en `COBRO_TENANT_DOMAINS`, separado por comas si hay más de uno: `COBRO_TENANT_DOMAINS=gruporyr.onmicrosoft.com`. Vacía por defecto. Estos dominios solo cuentan como remitente de un informe de nuestro Exchange (con `AuthAs` `Internal` y clase de no entrega); no son buzones de la empresa ni dominios de copias.
- **El dominio del inquilino de cada empresa:** si una empresa tiene su buzón en otro inquilino (Derivados), sus dominios van en `COBRO_TENANT_DOMAINS_SDC` (para Segurex, `COBRO_TENANT_DOMAINS_SGX`), con el mismo formato: `COBRO_TENANT_DOMAINS_SDC=derivadoscol.onmicrosoft.com`. Opcionales y vacías por defecto. Se suman a `COBRO_TENANT_DOMAINS` solo para el buzón de esa empresa: un informe que llega al buzón de Derivados se juzga con los dominios comunes y los de Derivados, nunca con los de Segurex, y al revés. Están en `cobro.company_tenant_domains`.
- **Si Graph rechaza la clase:** cuando la primera página de una vuelta nueva falla con HTTP 400 o 501 (Graph no acepta la consulta, casi siempre por el `$expand`), la sincronización la pide una sola vez más sin el `$expand` y sigue la vuelta normal. Deja un `warning` por buzón y ejecución con el buzón y el código HTTP (`Graph refused the message class in the inbox delta query`), sin contenido. Mientras dure, ningún mensaje trae clase, así que **los rebotes se guardan como respuestas** y no marcan el envío. Otros errores (401, 403, 429, 5xx) y los enlaces guardados no se reintentan. Ejecuta `cobro:diagnosticar-rebote <buzón> --probar-delta`: siempre prueba la consulta con el `$expand` y muestra el código con que Graph la rechaza. Como Graph guarda la consulta en los enlaces, la vuelta sin clase sigue así aunque Graph vuelva a aceptarla: después de corregirlo hay que empezar una vuelta nueva (borrar `next_link` y `delta_link` del buzón en `cobro_mailbox_cursors`, como hace la migración de abajo). En esas ejecuciones siguientes, cada mensaje sin clase deja su propio `warning`.
- **Una sola vuelta nueva:** Graph guarda la consulta dentro de los enlaces `@odata.nextLink` y `@odata.deltaLink`, así que la migración `2026_10_05_000007_reset_mailbox_cursors_for_bounce_class` los borra en `cobro_mailbox_cursors`. La siguiente sincronización vuelve a leer los últimos `cobro.sync.initial_days` días con la consulta nueva; los mensajes ya guardados se saltan por su id de Graph y se quedan como estaban.
- **Riesgo que queda:** un cliente con su propio servidor de correo puede armar un informe DSN real que cite el `Message-ID` del envío que recibió y marcarlo como rebotado. Solo afecta la exactitud del informe.

**Antes de confiar en la regla, córrela una vez contra un rebote real** de cada buzón. Envía un correo de prueba desde el buzón a una dirección que no exista, copia el id de Graph del informe que llega y ejecuta:

```bash
php artisan cobro:diagnosticar-rebote SGX "<id de Graph>"
php artisan cobro:diagnosticar-rebote SGX --probar-delta
```

El primero muestra la clase del mensaje, `X-MS-Exchange-Organization-AuthAs`, el remitente, si trae `multipart/report`, los dominios propios y la decisión de la regla; nunca muestra el cuerpo ni el asunto. Debe decir «rebote de nuestro Exchange»: si el remitente llega con otro dominio (por ejemplo `…onmicrosoft.com`), agrégalo a `COBRO_TENANT_DOMAINS` (o, si solo lo usa esa empresa, a `COBRO_TENANT_DOMAINS_SGX` o `COBRO_TENANT_DOMAINS_SDC`) y vuelve a correrlo; la línea de dominios propios ya lo incluye. `--probar-delta` pide una sola página de un mensaje de la consulta delta con la clase y solo dice si Graph la aceptó y, si no, el código HTTP. El comando no escribe en la base ni mueve los enlaces de la sincronización.

### Abrir en Outlook

La bandeja y la pantalla de cada mensaje tienen el botón «Abrir en Outlook». El enlace no se guarda: cada clic le pide a Graph el `webLink` del mensaje (`GET /users/{buzón}/messages/{id}?$select=id,webLink`, con la credencial de la empresa) y redirige a él.

- **Si alguien movió el correo de carpeta**, su id de Graph cambió (la sincronización no pide ids inmutables) y Graph contesta 404. Entonces `MessageLocator` lo busca por su `internetMessageId`, que no cambia, y guarda el id nuevo. Si tampoco lo encuentra, la pantalla dice que ya no está en el buzón.
- **Solo redirige a Outlook en la web** (`outlook.office365.com` u `outlook.office.com`, con https). Otro enlace, o una falla de Graph, vuelve a la pantalla del mensaje con un aviso y deja un `warning` con el id del mensaje y el código HTTP, sin contenido.
- **Quien abre el enlace necesita acceso al buzón compartido** de la empresa en Outlook; si no lo tiene, Outlook no le muestra el correo.

### Responder desde la app

La pantalla de cada respuesta (no de un rebote) tiene el formulario «Responder desde la app», y la bandeja lleva a él con el enlace «Responder». Pueden responder el gerente y la analista de cartera (`cobro.replies`).

- **Cómo sale:** `ReplySender` hace tres pasos en Graph con la credencial de la empresa: `createReply` sobre el mensaje del cliente (Graph arma el borrador para el remitente, en el mismo hilo y con el mensaje citado), `PATCH` del cuerpo del borrador con el texto escrito encima de la cita (escapado, con sus saltos de línea) y `send`. Antes busca el mensaje igual que «Abrir en Outlook», por si lo movieron de carpeta.
- **Qué queda:** la respuesta se guarda en `cobro_sent_messages` con la categoría `respuesta`, así lo que el cliente conteste después se une a su ficha. El mensaje queda leído, la bitácora del cliente dice «Respuesta enviada a … por <nombre>» y la auditoría registra `cobro.reply.sent` con el usuario.
- **Si falla:** si Graph rechaza un paso, se borra el borrador y el texto escrito vuelve al formulario. Si el envío no recibe respuesta, el borrador queda en el buzón para revisarlo en Outlook, porque pudo haber salido. Una falla al guardar la bitácora o la auditoría después del envío se registra, pero la respuesta cuenta como enviada.

## Copias del cobro semanal y de la circularización

Cada correo del cobro semanal y de la circularización mensual lleva en copia (CC), en este orden y sin repetir direcciones ni repetir las del cliente:

1. los correos fijos de la empresa;
2. el asesor del cliente (su correo en SAP, `OSLP.Email`), si la casilla está marcada y SAP lo tiene activo;
3. los gerentes del grupo de SAP del cliente (`OCRG.GroupName`, por ejemplo `DISTRIBUCION` o `CONS. RESIDENCIAL`). El grupo se compara sin importar mayúsculas, tildes ni espacios; un cliente sin grupo no copia a ningún gerente.

Se editan en `/cobro/copias` y se guardan en `cobro_copias`, una fila por empresa (cada cambio queda en la auditoría como `cobro.copies.updated`; «Volver a las copias por defecto» borra la fila y queda como `cobro.copies.reset`). Mientras una empresa no guarde las suyas se usan las de `config('cobro.copias')`, que están vacías: sin correos fijos, sin asesor y sin gerentes, para que ningún correo de un empleado quede en el código (auditoría S-31).

**Antes del primer envío, guardar las copias en /cobro/copias**, para Segurex y para Derivados. Si un envío arranca sin copias guardadas, sale sin copias y deja el aviso `cobro.copies.not_saved` en el registro, con la empresa, la clase de envío y el id del envío. La vista previa no deja aviso.

Las dos clases de correo usan las mismas copias de la empresa: lo que se guarde en `/cobro/copias` aplica a ambas. La vista previa del envío muestra las copias de cada cliente; la prueba que se envía el usuario no lleva copias.

Las copias solo pueden ir a los dominios de `config('cobro.copy_domains')` (`segurex.com` y `derivadoscol.com`, una sola lista para las dos empresas). El dominio es lo que va después de la última `@` y debe coincidir exactamente, sin importar mayúsculas: `ana@mail.segurex.com` no cuenta.

- `/cobro/copias` rechaza los correos de otros dominios con el mensaje «Solo se permiten copias a correos de segurex.com o derivadoscol.com: …» y muestra esos dominios en la ayuda de cada campo.
- Justo antes de enviar, `SendRunItem` quita de la copia cualquier otro correo: filas guardadas antes de esta regla o un asesor de SAP con otro dominio. Deja un aviso `cobro.copies.dropped` en el registro con la empresa, el envío, cuántos correos quitó y solo sus dominios (no las direcciones, por minimización de datos de la Ley 1581). `GraphMailSender` no filtra, porque lo comparten otros módulos.

## Aviso de cambios en la plantilla

El cobro semanal sale solo a todos los clientes con saldo vencido, así que cada cambio de la plantilla avisa a TI y a control interno (hallazgo S-08 de la auditoría), en vez de pedir la aprobación de una segunda persona:

- **Cuándo:** al guardar la plantilla con algún texto distinto (guardar sin cambios no avisa) y al volver a los textos por defecto si los guardados eran otros.
- **A quién:** a los correos de `CORE_AUDIT_ALERT_RECIPIENTS`, los mismos de la alerta de la auditoría, que Cobro lee como `config('cobro.template_alert_recipients')`.
- **Desde dónde:** desde el buzón de la empresa de la plantilla (Segurex o Derivados), con la categoría `alerta`.
- **Qué dice:** la empresa, quién hizo el cambio (nombre y correo), cuándo (hora de Bogotá), si fue una edición o una vuelta a los textos por defecto, un enlace a la plantilla y, por cada texto que cambió, sus líneas con `−` (se quitó) y `+` (se agregó). Los textos van escapados: un enlace, un número de cuenta o una etiqueta HTML se ven tal como se escribieron.
- **Si falla:** lo envía el trabajo en cola `SendTemplateChangeNotice`, una sola vez. Sin destinatarios deja un `warning` en el registro y si Graph no lo acepta un `error`; en los dos casos la plantilla queda guardada.

## Microsoft Graph: lo que TI debe configurar

Variables de `.env` (`docs/arquitectura.md` §15): `GRAPH_TENANT_ID`, `GRAPH_CLIENT_ID`, `GRAPH_CLIENT_SECRET`, `COBRO_MAILBOX_SGX`, `COBRO_MAILBOX_SDC` y, si hace falta, `COBRO_TENANT_DOMAINS`, `COBRO_TENANT_DOMAINS_SGX` y `COBRO_TENANT_DOMAINS_SDC` (dominios del inquilino común y del de cada empresa que firman los rebotes de Exchange, ver «Qué cuenta como rebote»).

**Una credencial por empresa.** Las tres variables `GRAPH_*` son la credencial común. Si el buzón de una empresa vive en otro inquilino (Derivados, `derivadoscol.com`), esa empresa usa su propio registro de aplicación con `GRAPH_SDC_TENANT_ID`, `GRAPH_SDC_CLIENT_ID` y `GRAPH_SDC_CLIENT_SECRET` (para Segurex, `GRAPH_SGX_*`), en `cobro.graph.companies`. La credencial propia solo cuenta si tiene **las tres** variables con valor; si falta alguna, la empresa usa la común. Sin ninguna de las dos completa, esa empresa no envía (el informe dice «La conexión con Microsoft 365 no está configurada.») ni se lee su buzón. Un `.env` con solo las tres `GRAPH_*` sigue funcionando igual para las dos empresas. En el inquilino de cada empresa se repiten los pasos de abajo con su propio registro y su buzón.

1. Registrar en Entra ID una aplicación aparte de la de ingreso, de un solo inquilino, con un secreto de cliente. **No** agregarle permisos de Microsoft Graph ni dar consentimiento de administrador: un permiso de Entra ID abre todos los buzones del inquilino, y los de Exchange se suman a él en vez de limitarlo.
2. En Exchange Online PowerShell (rol Organization Management), crear el apuntador a la aplicación con los ids de **Aplicaciones empresariales** (no los de Registros de aplicaciones):

   ```powershell
   New-ServicePrincipal -AppId <id de la aplicación> -ObjectId <id de objeto del service principal> -DisplayName "Gestor de cartera - correo"
   ```

3. Limitar el alcance a los dos buzones, por ejemplo con un grupo de seguridad habilitado para correo que tenga solo a esos dos buzones como miembros directos:

   ```powershell
   New-ManagementScope -Name "Gestor de cartera - buzones" -RecipientRestrictionFilter "MemberOfGroup -eq '<nombre distintivo del grupo>'"
   ```

4. Asignar los dos roles de aplicación con ese alcance:

   ```powershell
   New-ManagementRoleAssignment -App <id de objeto del service principal> -Role "Application Mail.Send" -CustomResourceScope "Gestor de cartera - buzones"
   New-ManagementRoleAssignment -App <id de objeto del service principal> -Role "Application Mail.ReadWrite" -CustomResourceScope "Gestor de cartera - buzones"
   ```

5. Comprobar que solo alcanza esos buzones (`InScope` debe ser `True` para los dos y `False` para cualquier otro):

   ```powershell
   Test-ServicePrincipalAuthorization -Identity <id de la aplicación> -Resource segurex.info@<dominio>
   ```

`Application Mail.Send` permite enviar y `Application Mail.ReadWrite` crear el borrador (de ahí salen los identificadores), borrarlo si el envío falla y leer la bandeja de entrada. Los cambios de permisos tardan entre 30 minutos y 2 horas en aplicarse.

Protecciones de `GraphClient`:

- **Cada buzón con la credencial de su empresa**: `GraphClient` no hace peticiones sin empresa; quien lo usa pide `$graph->for($empresa)` (`GraphMailSender` con la empresa del correo, `MailboxSync` y `cobro:diagnosticar-rebote` con la del buzón). Así el token de un inquilino nunca viaja a un buzón del otro.
- **Token cifrado en la caché, uno por credencial**: el token de aplicación se guarda en la caché con la clave `cobro.graph.token.<sha256 de inquilino|id de cliente>` (nunca el secreto), cifrado con `APP_KEY`. Dos empresas con la credencial común comparten el mismo token; una con credencial propia tiene el suyo. Si no se puede descifrar (un token guardado en texto plano o una `APP_KEY` rotada), cuenta como si no estuviera y se pide uno nuevo. Un 401 de Graph borra solo el token de esa credencial. La clave única de antes (`cobro.graph.token`) se borra al guardar el primer token nuevo.
- **Solo URLs de Graph**: un enlace absoluto (el `@odata.nextLink` o `@odata.deltaLink` guardado en `cobro_mailbox_cursors`) solo se sigue si es `https`, con host exactamente `graph.microsoft.com`, sin usuario ni puerto y con ruta bajo `/v1.0/`. Cualquier otro se rechaza antes de leer el token, así que el token nunca sale hacia otro host; el error nombra el host, nunca la consulta. La sincronización borra entonces los dos enlaces del buzón y la siguiente vuelta empieza de nuevo, igual que cuando Graph responde 410.

## Circularización (4.2)

**El envío programado sale de verdad**: el quinto día hábil de cada mes a las 9:00 a. m. (hora de Bogotá), para Segurex y Derivados (`cobro.circularizacion`, con `business_day` en 5 y `draft` en `false`). Día hábil es de lunes a viernes sin festivos de Colombia (`App\Support\Calendar\ColombianHolidays`); por ejemplo, en diciembre de 2026 es el lunes 7. La tarea corre todos los días a las 9:00 y solo actúa ese día. **Antes del primer envío real, envíate una prueba desde `/cobro/circularizacion` a un buzón interno** y revisa el correo de cada empresa.

`Circularizacion/CircularizationRun.php` define el envío: programación mensual, lote, envío e informe, con la misma lista de exclusión, el mismo informe de entrega y la misma bandeja de respuestas del cobro semanal. Cada cliente con saldo, salvo los que están en jurídico (ver «Clientes en jurídico»), recibe todas sus partidas abiertas (las notas crédito incluidas, como en el cobro semanal) con el total que debe.

**Sale con la foto del cierre, no con la última carga.** El robot corre a las 00:00, 05:00 y 12:00 y fecha cada carga con el día en que corre; la foto real del cierre del mes es la carga de las 00:00 del día 1 del mes siguiente. Core la entrega con `CarteraRepository::closingSnapshot(empresa, año, mes)` y su fecha de corte es el último día del mes. El envío lee de esa foto los saldos y las facturas; los contactos, el estado del cliente y el asesor son los de hoy. El envío guarda la fecha de la foto (`cobro_runs.cutoff_date`), el informe la muestra y cada correo del lote se arma con la misma foto aunque salga minutos después.

**Espera la foto del cierre.** Si la foto del cierre del mes anterior no llegó para una empresa, esa empresa no se envía: no se guarda el envío, así que el mes queda libre para enviarlo a mano después, y queda un aviso en la consola y en el registro (`Log::warning` con la empresa y el cierre esperado). Las demás empresas salen normalmente; si ninguna sale, el comando termina con error.

- Cuando la foto llegue: `php artisan cobro:circularizacion --empresa=SGX` (o `SDC`). Usa el mismo envío del mes (el quinto día hábil), así que el informe y la regla de una vez al mes no cambian.
- Si la foto no va a llegar: agrega `--con-corte-actual` para enviarla con la última carga; el correo dice ese corte («Corte al 6 de diciembre de 2026»).
- La vista previa (`/cobro/circularizacion`) muestra la fecha de corte que va a leer el próximo envío. Mientras la foto no llegue, usa la última carga y lo avisa.

El correo (`resources/views/circularizacion/estado-cartera.blade.php`) copia el formato que cada empresa envía hoy, sin el marco de la marca, como el cobro semanal:

- Asunto `Circularización de Cartera – Corte al {fecha_cierre}` en Segurex y `… Corte al {fecha_cierre_del}` en Derivados. `{fecha_cierre}` es la fecha de corte en letras, por ejemplo «31 de mayo de 2026», y `{fecha_cierre_del}` la misma con «del», «31 de mayo del 2026» (las dos dan «—» si no hay corte); `{fecha_corte}` sigue siendo DD/MM/AAAA.
- Textos de cada empresa, palabra por palabra (con «usted», como los escriben), en `resources/lang/es/plantillas.php` (`por_defecto.circularizacion.SGX` y `.SDC`). La pantalla de plantillas todavía edita solo el cobro semanal.
- La tabla de cada empresa (`Circularizacion/CircularizationTable.php`) con sus columnas en el orden del original y el nombre de la empresa donde el original lleva el logo.
- Solo Segurex: el aviso en rojo bajo la tabla («…corresponde al cierre del 31 de mayo…», `Circularizacion/ClosingNotice.php`) y el pie de firma con la dirección, los teléfonos y el aviso legal (`resources/lang/es/circularizacion.php`).
- Copias (CC): las mismas del cobro semanal de la empresa (ver «Copias del cobro semanal y de la circularización»).

Quedan por fuera las columnas Mora y Moneda, la marca de importancia alta, los logos y los íconos de redes: la cartera no trae esos datos y el contrato de envío no admite importancia ni imágenes. No se escribe un «COP» fijo ni se deduce «Corriente».

`cobro.circularizacion.draft` se conserva para un formato futuro en revisión: en `true` el envío programado no sale y los correos dicen «Borrador»; `--aun-en-borrador` permite probar el motor.

## Pruebas

Ninguna prueba envía correo real ni llama a Graph: los flujos usan `FakeMailSender` (`ContractFakes`) y la integración con Graph usa respuestas grabadas en `tests/Modules/Cobro/Fixtures/graph` a través de `tests/Modules/Cobro/Support/GraphFake.php`.
