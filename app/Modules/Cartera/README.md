# Cartera (Módulo 2)

Gestor de cartera para la analista (María Paula) y el gerente (Miguel). Spec: `docs/prompt-gestor-cartera.md`, Parte B, «Módulo 2».

## Pantallas

| URL | Ruta | Qué hace |
|---|---|---|
| `/cartera` | `cartera.tablero` | Total, vencido y % vencido, edades de la deuda, 10 clientes que más deben, compromisos de la semana, vencidos sin gestión, actividad reciente. En la vista Grupo compara SGX con SDC. Al final de la descripción del título, cuándo llegaron los datos («Datos actualizados hoy a las 07:10.»; ver «Decisiones»). |
| `/cartera/kanban` | `cartera.kanban` | Siete columnas, una por estado. Arrastrar o botón «Mover» (teclado y celular). «Al día» y «Pago recibido» arrancan ocultas. Si el tablero no cabe, los botones «Columnas anteriores» y «Columnas siguientes» lo desplazan de a una vista (suave, salvo con movimiento reducido) y una línea dice qué columnas se ven; el tablero también se mueve con las flechas del teclado. |
| `/cartera/clientes` | `cartera.clientes.index` | Lista con búsqueda por nombre o NIT, filtros (también «Gestor»), orden por columna y paginación. |
| `/cartera/clientes/{id}` | `cartera.clientes.show` | Ficha: datos, cupo, facturas abiertas, contactos de SAP, compromisos, bitácora, respuestas de correo. Con `?nuevo-compromiso=1` abre «Nuevo compromiso de pago» a quien puede escribir los compromisos del cliente, con `?valor=` como valor sugerido (solo un número mayor que cero; lo escrito antes gana). |
| `/cartera/compromisos` | `cartera.compromisos.index` | Bandeja de compromisos con filtros por estado (pestañas Pendientes, Incumplidos, Cumplidos, Abonados, Anulados y Todos; `?estado=`), asesor y fecha. |
| `/cartera/clientes/exportar`, `/cartera/clientes/{id}/exportar` | `cartera.clientes.exportar*` | Excel de la lista y de la ficha. Entre las dos, máximo 10 descargas por minuto por usuario (`cartera-exports`); la siguiente recibe un 429. |

## Gestor del cliente

El gestor es la persona del equipo de cartera que se encarga del cliente (`ClientSummary::$manager`). Es solo un filtro: todo el equipo sigue viendo todos los clientes.

**Filtro «Gestor» de la lista** (`?gestor=`, `Application/Clients/ManagerFilter`):

| Valor | Opción | Qué muestra |
|---|---|---|
| `todos` | Toda la cartera | Todos los clientes. |
| `mios` | Mi cartera | Los clientes cuyo gestor es el usuario (`ClientQuery::$managerUserId`). |
| `sin_asignar` | Sin asignar | Los clientes sin gestor (`ClientQuery::$unassigned`). |

Sin `gestor` en la URL, quien tiene el permiso `cartera.clients.start_on_mine` (solo la analista, `analista_cartera`) entra con «Mi cartera» y el gerente (y cualquier otro) con «Toda la cartera»; un `gestor` explícito gana. Un valor desconocido vuelve al valor por defecto del usuario. Si el valor por defecto del usuario no es «Toda la cartera», los enlaces llevan siempre el `gestor` y «Quitar filtros» lleva a `?gestor=todos`. El Excel de la lista aplica el mismo filtro, con el mismo valor por defecto, y lo deja en la auditoría.

**Asignar gestor** (permiso de Core `core.clients.assign`: gerente, analista y admin). Las opciones son «Sin asignar» y las personas de `ClientAssignmentService::assignees()`; el formulario manda `nuevo_gestor` = id del usuario o `sin_asignar`. Quien no tiene el permiso no ve los formularios y recibe un 403 si los envía. Core escribe la bitácora y la auditoría de cada cambio.

| Dónde | Ruta | Qué hace |
|---|---|---|
| Lista | `POST /cartera/clientes/gestor` (`cartera.clientes.asignar`) | Una casilla por fila (`clientes[]`) dentro de un solo formulario, así funciona sin JavaScript; Alpine solo agrega «Elegir los clientes de esta página» (marcada solo si todas las filas lo están, también al volver con un error) y el conteo de elegidos. Con «Aplicar a los N clientes filtrados» (`todos_filtrados=1`) asigna todos los que coinciden con los filtros de la lista (no solo la página): el formulario lleva los filtros en campos ocultos y arma el mismo `ClientQuery` (`assignMatching()`); si no, `assign()` con los elegidos. Filas elegidas y «todos los filtrados» a la vez se rechazan («Elige filas o marca «todos los filtrados», no las dos cosas.»), para que nadie asigne toda la lista creyendo que asigna unas filas; con JavaScript, enviar «todos los filtrados» pide confirmar con el número de clientes. Avisa cuántos cambiaron («No cambió ningún cliente.», «Asignamos el gestor a 1 cliente.», «… a N clientes.»). |
| Ficha | `POST /cartera/clientes/{id}/gestor` (`cartera.clientes.gestor`) | Junto a «Gestor responsable», un select con el gestor actual elegido y «Guardar» (`assign([$id], …)`): «Gestor actualizado.». Guardar el gestor que el cliente ya tiene no llama a Core y dice «El cliente ya tenía ese gestor.» (así no falla con un gestor actual que ya no es asignable). Si el gestor actual ya no está entre las opciones, sigue en la lista. Un cliente fuera de alcance es un 404. |

- **Tope** (`cartera.assignment.max_bulk`, 1000; pendiente conocido: el total se cuenta antes de asignar, así que una carga que llegue en medio puede pasarlo un poco): con «todos los filtrados», si coinciden más clientes (el total del paginador de la misma consulta), no cambia nada y avisa «Son N clientes; asigna de a máximo 1.000. Filtra un poco más.». La lista ya apaga esa casilla cuando su total pasa el tope y dice por qué («Son N clientes y el máximo es 1.000; filtra un poco más para usar esta opción.»). Cada cambio escribe una fila de auditoría y una entrada de bitácora en la misma petición, y el hosting compartido tiene que terminarla a tiempo. La lista de elegidos tiene el mismo tope.
- **Errores**: un gestor que ya no está entre las personas asignables o que no trabaja en la empresa de todos los clientes (`InvalidArgumentException`, Core no distingue los dos casos) vuelve con «Esa persona no puede encargarse de todos los clientes elegidos: ya no gestiona cartera o no trabaja en la empresa de alguno. Elige otra o filtra por empresa.» (en la ficha, «Esa persona no puede encargarse de este cliente: ya no gestiona cartera o no trabaja en su empresa. Elige otra.»); un cliente que ya no existe o salió del alcance (`RecordNotFound`), con «Alguno de los clientes ya no está disponible. Vuelve a cargar la lista.».

## Permisos (`CarteraPermissionSeeder`)

- `cartera.view`: gerente y analista. Todas las pantallas.
- `cartera.manage`: gerente y analista. Registrar llamadas (`cartera.llamadas.store`), notas y descuentos ofrecidos.
- `cartera.export`: gerente y analista. Exportar a Excel (queda en la auditoría).
- `cartera.commitments.write`: solo la analista. Crear, editar y anular compromisos. Refleja la regla de Core (`CommitmentWriteRule`): en SDC escribe la analista; en SGX, el asesor del cliente desde Asesores. El `CompromisoService` sigue siendo la autoridad.
- `cartera.commitments.status`: gerente y analista. Marcar cualquier compromiso «Cumplido» (`POST /cartera/compromisos/{id}/cumplir`), «Abonado» (`POST /cartera/compromisos/{id}/abonar`, `cartera.compromisos.abonar`) o «Incumplido» (`POST /cartera/compromisos/{id}/incumplir`) en SGX y SDC, también los que creó el asesor, desde la bandeja y la ficha (`<x-cartera::commitment-status-actions>`). Refleja `CommitmentWriteRule::authorizeStatus()`. «Marcar cumplido» y «Registrar abono» salen en un compromiso pendiente o incumplido; «Marcar incumplido», solo en uno pendiente. «Registrar abono» abre el modal del compromiso (`abono_{id}[valor]`, en pesos como el valor de un compromiso nuevo, y `abono_{id}[nota]` opcional, hasta 1.000 caracteres) y llama `markPartiallyPaid()`: «Registraste el abono.». Un valor que Core rechaza (cero o el valor completo o más) vuelve al modal con «El abono debe ser mayor que cero y menor que el valor del compromiso. Si pagó todo, márcalo cumplido.». En la bandeja y la ficha, un compromiso abonado muestra «Pagó $ X · saldo $ Y» bajo su valor. En SGX no hay editar ni anular. El superadmin no lo tiene (`superAdminExcludes()`).
- `cartera.clients.start_on_mine`: solo la analista. La lista de clientes abre en «Mi cartera» en vez de «Toda la cartera». Es un valor por defecto, no una restricción.

«En gestión» se retiró (migración de Core `2026_10_01_000015`); `en_gestion` ya no es un estado válido.

El cambio de estado usa el permiso de Core `core.clients.change_status`; asignar el gestor, `core.clients.assign`, y borrar una gestión, `core.activities.delete`.

## Registro de llamadas

Cada llamada al cliente queda como fila propia del módulo y como entrada de la bitácora. Lo hace `Application/Calls/LogCall::log(NewCall, User)`, desde el botón «Registrar llamada» de la ficha.

- **Pantalla.** El botón abre el diálogo `gestion-llamada` (`clientes/ficha/llamada.blade.php`), que envía a `POST /cartera/clientes/{client}/llamadas` (`cartera.llamadas.store`, `CallController` y `StoreCallRequest`, con `cartera.manage`). Pide fecha y hora (ahora, en hora de Bogotá), canal, número (los teléfonos de los contactos, el principal primero, u «Otro número» escrito al lado), con quién hablaste (los contactos u «Otra persona», opcional), resultado, notas, acuerdo, el día para volver a llamar y las facturas abiertas que ya cargó la ficha, como casillas. Lo escrito gana sobre lo elegido en la lista, así que el formulario no necesita JavaScript. «Compromiso de pago» solo aparece para quien puede registrarlo; un acuerdo que el usuario no puede hacer es un 403 y un cliente fuera de alcance, un 404. Los campos van anidados en `llamada[...]` y los errores de `LogCall` vuelven bajo el campo que corresponde (`llamada.ocurrio`, `llamada.seguimiento`, `llamada.acuerdo`, `llamada.facturas`). El diálogo se abre otra vez con lo escrito si el envío falla, y al llegar con `?registrar-llamada=1` (el enlace del aviso del seguimiento). La llamada simple de antes (`tipo=llamada` en `cartera.gestiones.store`) ya no existe; las entradas viejas de la bitácora conservan su resultado.

- **Tablas.** `cartera_calls`: empresa, cliente (`client_id` de Core, sin llave foránea), quién llamó (`user_id`), cuándo (`occurred_at`), canal, teléfono (hasta 255 caracteres, como el teléfono de un contacto en Core, donde SAP suele poner varios números; `2026_10_03_000003` lo amplió desde 40), contacto (opcional), resultado, notas, acuerdo, `commitment_id` y `activity_id` (ids de Core, sin llave foránea) y el seguimiento (`follow_up_on`, `follow_up_notified_at`, `follow_up_closed_at`). `cartera_call_invoices`: las facturas abiertas de la llamada, copiadas ese día (número, tipo, vencimiento y saldo), porque el id de factura de Core cambia con cada carga.
- **Valores** (`Domain/Calls`, textos en `lang/es/llamadas.php`). Canal: «Llamada», «Llamada de WhatsApp», «Mensaje de WhatsApp». Resultado: «Contestó», «No contestó», «Buzón de voz», «Número equivocado», «Pidió volver a llamar». Acuerdo: «Ninguno», «Compromiso de pago», «Envío de soporte».
- **Quién.** Solo `cartera.manage` (gerente y analista), sobre cualquier cliente de su alcance; uno fuera de alcance es `RecordNotFound`. «Compromiso de pago» solo si el usuario escribe los compromisos de la empresa del cliente (`CommitmentWriting` y `cartera.commitments.write`: la analista en SDC); si no, `AuthorizationException`. La llamada no guarda valor ni fecha del compromiso.
- **Reglas** (`ValidationException` con los campos `ocurrio`, `seguimiento`, `acuerdo` y `facturas`). La fecha es ahora si no llega; no puede ser futura ni de hace más de 30 días (desde el inicio de hoy en Bogotá). «Pidió volver a llamar» exige el día del seguimiento, que debe ser hoy o después. Un acuerdo distinto de «Ninguno» exige «Contestó». Cada factura debe estar entre las abiertas del cliente.
- **Bitácora.** Una entrada `ActivityType::Call` con resumen «Llamada de WhatsApp a Juan Pérez (3001234567)» (sin contacto, «Llamada al 3001234567»), detalle con las notas y una línea por factura («Factura FV-123, vence 15/10/2026, saldo $ 1.234.567»), resultado «Contestó · Compromiso de pago» (sin acuerdo, solo el resultado) y `metadata` `call_id`, `channel`, `outcome` y `agreement`. La llamada guarda el id de la entrada en `activity_id`.
- **Seguimiento.** Una llamada nueva cierra los seguimientos abiertos de las llamadas del mismo cliente que ocurrieron antes que ella o a la misma hora (`follow_up_closed_at`); una llamada registrada tarde deja abiertos los de llamadas posteriores. Si no queda ningún seguimiento abierto del cliente, resuelve los avisos de la campana con la `key` `cartera.call.follow_up:{clientId}`.
- **Aviso del seguimiento.** `cartera:avisos-seguimiento` (`Console/SendFollowUpNoticesCommand`, `Application/Calls/SendFollowUpNotices`) avisa en la campana a quien registró la llamada el día del seguimiento: cada seguimiento abierto con `follow_up_on` de hoy o antes (calendario de Bogotá) y sin `follow_up_notified_at`. Un día que ya pasó (p. ej. el servidor estuvo caído) también avisa, una sola vez. El aviso (`FollowUpNotice`) es de tipo `cartera.call.follow_up`, con la `key` de arriba, empresa de la llamada, sujeto `client`, título «Agro Arango SAS: hoy toca volver a llamar», cuerpo «Lo acordaste en la llamada del 10/11/2026 con Juan Pérez.» (sin contacto, sin «con …») y `data` `call_id`. Cada seguimiento se reclama primero con una actualización condicional de `follow_up_notified_at` y el aviso sale en la misma transacción: si el envío falla, el reclamo se deshace y la próxima corrida lo intenta otra vez; dos corridas a la vez nunca avisan dos veces. Se omite, con una línea `info` en el log y sin reclamarlo, si quien llamó ya no es un usuario activo con `cartera.manage` o si el cliente ya no existe (leído como sistema, `CarteraRepository::asSystem()`); se revisa en cada corrida, así que avisa en cuanto el usuario vuelve a estar habilitado o el cliente vuelve, y deja de revisarse cuando una llamada nueva cierra el seguimiento. Sin `AvisoService`, registra un error y no reclama nada. El proveedor lo programa cada hora de 07:00 a 18:00, hora de Bogotá, con `withoutOverlapping(10)`, para que un seguimiento de hoy suene ese mismo día; correrlo a mano es seguro. Imprime cuántos avisó y omitió, y falla si alguno no se pudo enviar. Enlace: `bootModule()` registra `AvisoService::linkUsing('cartera.call.follow_up', …)` (`Application/Calls/FollowUpLink`): con `cartera.view` y sujeto `client`, la ficha con `?registrar-llamada=1`; si no, `null`.
- Todo va en una transacción: si algo falla, no queda ni la llamada, ni sus facturas, ni el cierre de seguimientos.
- **Compromiso de pago.** Una llamada con ese acuerdo lleva a la ficha con «Nuevo compromiso de pago» abierto (`?nuevo-compromiso=1&valor={suma de los saldos de sus facturas}&llamada={id}`; sin facturas, sin `valor`) y el mensaje «Llamada registrada. Ahora registra el compromiso de pago.». La fecha queda vacía. Si `llamada` es una llamada de ese cliente con ese acuerdo y sin `commitment_id`, el formulario lleva `compromiso[llamada]` oculto (también tras un envío fallido) y la línea «Acordado en la llamada del 18/11/2026.». Al guardar, después de `CompromisoService::create()`, `Application/Calls/AttachCommitment` llena `commitment_id` con una actualización condicional (mismo cliente, ese acuerdo, todavía vacío): nunca reemplaza un compromiso ya enlazado. Si la llamada no cumple o el enlace falla, queda un `warning` en el log y el compromiso se guarda igual.

## Cómo se une al resto

Solo usa `Core/Contracts`: `CarteraRepository`, `CompromisoService`, `GestionLogger`, `ClientStatusService`, `ClientAssignmentService`, `CurrentCompany`, `AuditLogger`, `MailboxReader`, `IngestaSink` y `AvisoService`. Sus únicas tablas son las del registro de llamadas (`cartera_calls` y `cartera_call_invoices`, migraciones `2026_10_03_*`; ver «Registro de llamadas»).

Enlace del aviso de saldo: `bootModule()` registra con `AvisoService::linkUsing('commitment.remaining', …)` (`Application/Commitments/RemainingBalanceLink`) a dónde lleva el aviso que Core envía al abonar (`docs/arquitectura.md` §6.5). Con `cartera.view` y sujeto `client`, lleva a la ficha; si además el usuario escribe los compromisos de la empresa del cliente (`cartera.commitments.write` y SDC, `CommitmentWriting`), con `?nuevo-compromiso=1&valor={saldo}`. Si no, pasa (`null`). La ficha sigue a la empresa activa: un aviso de la otra empresa abre en 404 hasta que el usuario cambie a esa empresa o a la vista Grupo (el cambio de empresa de Core es un `POST` que vuelve atrás; un enlace no lo puede hacer).

`MailboxReader` lo implementa Cobro. Mientras no esté enlazado, la ficha muestra un aviso en vez de las respuestas (`Infrastructure/Mail/ClientReplies`).

## Configuración (`config/cartera.php`)

- `sin_gestion_dias` (env opcional `CARTERA_SIN_GESTION_DIAS`, 7): días sin gestión tras los que un cliente vencido cuenta como «sin gestión».
- `kanban_max_por_columna` (40): tarjetas por columna; los conteos y totales cubren todos los clientes.
- `por_pagina` (25).
- `assignment.max_bulk` (1000): clientes que «Asignar gestor» cambia de una vez (ver «Gestor del cliente»).

## Decisiones

- «Vencidos sin gestión» pasa de «nunca gestionado» (app Fase 1) a «sin gestión en N días», porque el contrato expone `ClientQuery::$inactiveSince`.
- Descuento ofrecido = entrada `ActivityType::DiscountOffered` con el valor en `metadata['discount_amount']`.
- La app Fase 1 no tenía exportación a Excel; las columnas son nuevas. Los valores van como números y los textos siempre como texto (OpenSpout convierte en fórmula todo texto que empiece por `=`).
- «Datos actualizados» del tablero es el `receivedAt` de la última carga de cartera (`IngestaSink::latest()`) en hora de Bogotá: «hoy a las 07:10» o «el 11/11/2026 a las 07:10». `IngestaSink` no filtra por usuario, así que `Application/Dashboard/DataFreshness` solo lee las empresas de `CurrentCompany::scope()`; en la vista Grupo manda la empresa con el corte más viejo (como el corte de los totales) y, con el mismo corte, la carga recibida primero. Sin carga no se muestra nada. Asesores tiene su propia copia de esta regla: los módulos no comparten clases.
- Los formularios de la ficha anidan sus campos (`llamada[...]`, `nota[...]`, `edicion_{id}[...]`) para que varios formularios convivan sin compartir errores ni valores antiguos.

## Pruebas

`tests/Modules/Cartera`. Usan `Tests\Fakes\ContractFakes`; para `ClientStatusService` y `ClientAssignmentService`, que no tienen fake, `Tests\Modules\Cartera\Support\RecordingClientStatusService` y `RecordingClientAssignmentService`.
