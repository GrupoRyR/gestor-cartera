# Cartera (Módulo 2)

Gestor de cartera para la analista (María Paula) y el gerente (Miguel). Spec: `docs/prompt-gestor-cartera.md`, Parte B, «Módulo 2».

## Pantallas

| URL | Ruta | Qué hace |
|---|---|---|
| `/cartera` | `cartera.tablero` | Total, vencido y % vencido, edades de la deuda, 10 clientes que más deben, compromisos de la semana, vencidos sin gestión, actividad reciente. En la vista Grupo compara SGX con SDC. |
| `/cartera/kanban` | `cartera.kanban` | Ocho columnas (una por estado). Arrastrar o botón «Mover» (teclado y celular). «Al día» y «Pago recibido» arrancan ocultas. |
| `/cartera/clientes` | `cartera.clientes.index` | Lista con búsqueda por nombre o NIT, filtros, orden por columna y paginación. |
| `/cartera/clientes/{id}` | `cartera.clientes.show` | Ficha: datos, cupo, facturas abiertas, contactos de SAP, compromisos, bitácora, respuestas de correo. |
| `/cartera/compromisos` | `cartera.compromisos.index` | Bandeja de compromisos con filtros por estado, asesor y fecha. |
| `/cartera/clientes/exportar`, `/cartera/clientes/{id}/exportar` | `cartera.clientes.exportar*` | Excel de la lista y de la ficha. |

## Permisos (`CarteraPermissionSeeder`)

- `cartera.view`: gerente y analista. Todas las pantallas.
- `cartera.manage`: gerente y analista. Registrar llamadas, notas y descuentos ofrecidos.
- `cartera.export`: gerente y analista. Exportar a Excel (queda en la auditoría).
- `cartera.commitments.write`: solo la analista. Crear, editar, cumplir y anular compromisos. Refleja la regla de Core (`CommitmentWriteRule`): en SDC escribe la analista; en SGX, el asesor del cliente desde Asesores. El `CompromisoService` sigue siendo la autoridad.

El cambio de estado usa el permiso de Core `core.clients.change_status`, y borrar una gestión, `core.activities.delete`.

## Cómo se une al resto

Solo usa `Core/Contracts`: `CarteraRepository`, `CompromisoService`, `GestionLogger`, `ClientStatusService`, `CurrentCompany`, `AuditLogger` y `MailboxReader`. No tiene tablas ni migraciones propias (el prefijo `2026_10_03_*` queda reservado).

`MailboxReader` lo implementa Cobro. Mientras no esté enlazado, la ficha muestra un aviso en vez de las respuestas (`Infrastructure/Mail/ClientReplies`).

## Configuración (`config/cartera.php`)

- `sin_gestion_dias` (env opcional `CARTERA_SIN_GESTION_DIAS`, 7): días sin gestión tras los que un cliente vencido cuenta como «sin gestión».
- `kanban_max_por_columna` (40): tarjetas por columna; los conteos y totales cubren todos los clientes.
- `por_pagina` (25).

## Decisiones

- «Vencidos sin gestión» pasa de «nunca gestionado» (app Fase 1) a «sin gestión en N días», porque el contrato expone `ClientQuery::$inactiveSince`.
- Descuento ofrecido = entrada `ActivityType::DiscountOffered` con el valor en `metadata['discount_amount']`.
- La app Fase 1 no tenía exportación a Excel; las columnas son nuevas. Los valores van como números y los textos siempre como texto (OpenSpout convierte en fórmula todo texto que empiece por `=`).
- Los formularios de la ficha anidan sus campos (`llamada[...]`, `nota[...]`, `edicion_{id}[...]`) para que varios formularios convivan sin compartir errores ni valores antiguos.

## Pruebas

`tests/Modules/Cartera`. Usan `Tests\Fakes\ContractFakes`; para `ClientStatusService`, que no tiene fake, `Tests\Modules\Cartera\Support\RecordingClientStatusService`.
