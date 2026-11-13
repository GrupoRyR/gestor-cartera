# Cartera (Módulo 2)

Gestor de cartera para la analista (María Paula) y el gerente (Miguel). Spec: `docs/prompt-gestor-cartera.md`, Parte B, «Módulo 2».

## Pantallas

| URL | Ruta | Qué hace |
|---|---|---|
| `/cartera` | `cartera.tablero` | Total, vencido y % vencido, edades de la deuda, 10 clientes que más deben, compromisos de la semana, vencidos sin gestión, actividad reciente. En la vista Grupo compara SGX con SDC. Al final de la descripción del título, cuándo llegaron los datos («Datos actualizados hoy a las 07:10.»; ver «Decisiones»). |
| `/cartera/kanban` | `cartera.kanban` | Ocho columnas (una por estado). Arrastrar o botón «Mover» (teclado y celular). «Al día» y «Pago recibido» arrancan ocultas. Si el tablero no cabe, los botones «Columnas anteriores» y «Columnas siguientes» lo desplazan de a una vista (suave, salvo con movimiento reducido) y una línea dice qué columnas se ven; el tablero también se mueve con las flechas del teclado. |
| `/cartera/clientes` | `cartera.clientes.index` | Lista con búsqueda por nombre o NIT, filtros (también «Gestor»), orden por columna y paginación. |
| `/cartera/clientes/{id}` | `cartera.clientes.show` | Ficha: datos, cupo, facturas abiertas, contactos de SAP, compromisos, bitácora, respuestas de correo. |
| `/cartera/compromisos` | `cartera.compromisos.index` | Bandeja de compromisos con filtros por estado, asesor y fecha. |
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
- `cartera.manage`: gerente y analista. Registrar llamadas, notas y descuentos ofrecidos.
- `cartera.export`: gerente y analista. Exportar a Excel (queda en la auditoría).
- `cartera.commitments.write`: solo la analista. Crear, editar, cumplir y anular compromisos. Refleja la regla de Core (`CommitmentWriteRule`): en SDC escribe la analista; en SGX, el asesor del cliente desde Asesores. El `CompromisoService` sigue siendo la autoridad.
- `cartera.clients.start_on_mine`: solo la analista. La lista de clientes abre en «Mi cartera» en vez de «Toda la cartera». Es un valor por defecto, no una restricción.

El cambio de estado usa el permiso de Core `core.clients.change_status`; asignar el gestor, `core.clients.assign`, y borrar una gestión, `core.activities.delete`.

## Cómo se une al resto

Solo usa `Core/Contracts`: `CarteraRepository`, `CompromisoService`, `GestionLogger`, `ClientStatusService`, `ClientAssignmentService`, `CurrentCompany`, `AuditLogger`, `MailboxReader` e `IngestaSink`. No tiene tablas ni migraciones propias (el prefijo `2026_10_03_*` queda reservado).

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
