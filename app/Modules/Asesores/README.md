# Módulo Asesores (visualizador del asesor)

Migra el portal Next.js de asesores (`legacy/portal-asesores`) al gestor de cartera: cada asesor ve **solo su cartera**, registra los compromisos de pago de sus clientes y lleva su bitácora; el gerente comercial ve a los asesores de su equipo. Es el Módulo 3 de `docs/prompt-gestor-cartera.md`.

El módulo **no tiene tablas, modelos ni migraciones**: los clientes, las facturas, los compromisos y la bitácora son de Core y se leen y escriben solo por sus contratos (`CarteraRepository`, `CompromisoService`, `GestionLogger`, `AuditLogger`, `CurrentCompany`).

## Pantallas

Todas piden sesión iniciada y el permiso `asesores.view` (asesor y gerente comercial). Las rutas se llaman `asesores.*` y viven bajo `/asesores`.

| Ruta | Nombre | Qué muestra | Quién |
|---|---|---|---|
| `GET /asesores` | `inicio` | Totales, edades de la deuda, clientes en atención prioritaria (más de 90 días) y tendencia de la mora. | Asesor, gerente |
| `GET /asesores/clientes` | `clientes.index` | Lista con búsqueda (nombre, NIT o código), estado, «solo vencidos», orden por columna y 25 por página. | Asesor, gerente |
| `GET /asesores/clientes/{client}` | `clientes.show` | Ficha: posición, cupo, edades, facturas abiertas, contactos, compromisos y bitácora. | Asesor, gerente |
| `GET /asesores/agenda` | `agenda` | Compromisos abiertos: incumplidos, de hoy, de los próximos 7 días y posteriores. | Asesor, gerente |
| `GET /asesores/historico` | `historico` | La cartera en cada corte del periodo (30 a 365 días): gráfico y tabla. | Asesor, gerente |
| `GET /asesores/equipo` | `equipo.index` | Cada asesor del equipo, el que más debe vencido primero. | Gerente (`asesores.team`) |
| `GET /asesores/compromisos/{commitment}` | `compromisos.show` | Un compromiso, con los formularios que su rol permite. | Asesor, gerente (lectura) |
| `POST /asesores/clientes/{client}/compromisos` | `compromisos.store` | Crea un compromiso. | Asesor (`asesores.manage`) |
| `PUT /asesores/compromisos/{commitment}` | `compromisos.update` | Cambia fecha, valor o notas de un pendiente. | Asesor |
| `POST /asesores/compromisos/{commitment}/cumplir` | `compromisos.fulfill` | Lo marca cumplido (un pago tardío también cumple). | Asesor |
| `POST /asesores/compromisos/{commitment}/anular` | `compromisos.cancel` | Lo anula, con motivo. | Asesor |
| `POST /asesores/clientes/{client}/notas` | `notas.store` | Agrega una nota a la bitácora del cliente. | Asesor |
| `GET /asesores/clientes/exportar` | `clientes.exportar` | Excel de la lista (mismos filtros y orden) con sus facturas. | Asesor, gerente |
| `GET /asesores/clientes/{client}/exportar` | `clientes.exportar_cliente` | Excel de un cliente. | Asesor, gerente |
| `GET /asesores/clientes/{client}/estado-de-cuenta` | `clientes.estado_cuenta` | PDF del estado de cuenta del cliente. | Asesor, gerente |

El botón de WhatsApp está en la ficha: abre `wa.me` con el estado de cuenta escrito; el asesor lo revisa y lo envía él.

`asesores.inicio` es la primera entrada del menú (`order: 10`), así que es la pantalla de inicio del asesor y del gerente comercial (Core manda a cada usuario a su primera entrada). La entrada **Agenda** lleva como insignia el número de compromisos incumplidos.

## Por qué un asesor no puede leer la cartera de otro

La regla de alcance (§6.2 de `docs/arquitectura.md`) la implementa Core, una sola vez, en `PortfolioScope`: un `asesor` ve los clientes de los asesores de SAP vinculados a él (por **empresa y código**, porque el 12 de SGX y el 12 de SDC son personas distintas), un `gerente_comercial` los de su equipo, y quien no tiene rol, está inactivo o eliminado no ve nada. Asesores reutiliza ese alcance en lugar de repetirlo en consultas propias; así no hay dos implementaciones que puedan divergir. Capas, de afuera hacia adentro:

1. **Ruta.** Cada ruta pide `auth` y un permiso `asesores.*`; las que escriben piden `asesores.manage`. `RoutesTest` lo comprueba para todas las rutas del módulo.
2. **Datos.** Toda lectura pasa por `CarteraRepository` (y toda escritura por `CompromisoService` y `GestionLogger`), que aplican el alcance del usuario autenticado. Un filtro de la URL (`?asesor=`, `?buscar=`) solo **reduce** lo que ya se ve: pedir el asesor de otro devuelve una lista vacía, nunca la suya.
3. **Un cliente fuera del alcance no existe.** Un id ajeno responde 404, con el mismo cuerpo que un id que nadie tiene, así que la URL no revela si existe. Pasa en la ficha, los compromisos, las notas, los Excel y el PDF.
4. **Policies.** `ClientPolicy` (ver, escribir compromisos solo el asesor del cliente y solo en SGX, anotar solo el asesor del cliente) y `CommitmentPolicy` (editar solo un pendiente, cumplir o anular un pendiente o incumplido) deciden qué ofrece cada pantalla; Core repite las reglas al escribir y tiene la última palabra.
5. **Exportaciones.** Los dos Excel y el PDF leen por el mismo contrato que las pantallas: un archivo contiene los clientes que el usuario puede ver y nada más, diga lo que diga la URL. Cada descarga queda en la auditoría (`asesores.portfolio.exported`, `asesores.client.exported`, `asesores.client.statement_downloaded`).
6. **Arquitectura.** `tests/Modules/Asesores/Unit/ArchitectureTest.php` impide que el módulo use la base de datos, defina modelos, tenga migraciones o llame a `asSystem()` (la copia del repositorio sin alcance, solo para tareas programadas). Es lo que garantiza que ninguna pantalla ni exportación pueda saltarse el alcance de Core.

### Las pruebas que lo demuestran

`tests/Modules/Asesores/Feature/Access/IsolationTest.php` corre contra el repositorio y el alcance **reales** de Core (no contra fakes). A cada asesor vecino le crea un cliente «canario» (nombre, código, NIT, saldo, compromiso, nota y gestión que no aparecen en ningún otro lado) y comprueba que el canario no sale en **ninguna** pantalla ni archivo del otro asesor, con la URL manipulada de todas las formas (`?asesor=`, `?buscar=` con su nombre, su código o su NIT, otra empresa con el mismo código de SAP, otro periodo, otra página). También comprueba que toda URL con el id de un cliente o compromiso ajeno responde 404 y no escribe nada, que el gerente comercial no sale de su equipo, que un usuario sin asesor vinculado ve la cartera vacía y que un usuario desactivado queda fuera. Una prueba de control apaga el alcance y verifica que el canario sí aparece: así se sabe que las comprobaciones pueden fallar. Otra obliga a que toda ruta nueva del módulo quede cubierta por la suite.

## Compromisos de pago

Los crea, edita y marca el asesor del cliente, **en SGX**; en SDC los escribe la analista (Módulo 2). Esa regla es de Core (`CommitmentWriteRule`); el módulo la refleja en `config('asesores.commitment_companies')` solo para decidir qué botones ofrecer. Cada escritura deja su gestión en la bitácora y su entrada de auditoría (Core) y mueve el estado del cliente. El valor se escribe como en Colombia (`5.000.000`, `$ 5.000.000,50`); la fecha debe ser hoy o futura. Un doble clic sobre un compromiso ya cerrado muestra un mensaje claro en vez de un error.

## Exportaciones

- **Excel (`.xlsx`).** OpenSpout, por flujo. Dos hojas: *Clientes* y *Documentos*. Valores y fechas son números y fechas de Excel (se pueden sumar y ordenar). El de la lista respeta los filtros y el orden de la pantalla. Por consultar las facturas cliente por cliente, pasados `export.max_clients_with_invoices` clientes (1.500) la hoja de documentos se corta y lo dice en su última fila.
- **PDF.** dompdf. Es un estado de cuenta **para el cliente**: no lleva compromisos ni bitácora, y toma el nombre y los colores de la empresa del cliente (la paleta sale de `CurrentCompany::of()`, no de una constante en la vista). Las vistas de `documentos/` son la única excepción a «sin `<style>` en las vistas»: dompdf no puede usar los componentes de la app.
- **WhatsApp.** El número sale de los contactos que trae SAP: el primero con celular colombiano (10 dígitos que empiezan por 3), con o sin indicativo, o un celular de otro país con su indicativo. Sin celular, el enlace abre WhatsApp para que el asesor elija el contacto. El mensaje habla de «usted» al cliente.

## Qué cambió frente al portal anterior

| Portal Next.js | Aquí | Por qué |
|---|---|---|
| Rangos de edad de 8 tramos, con «0-30» incluyendo lo que vence hoy. | 5 tramos de Core: sin vencer (incluye lo que vence hoy), 1-30, 31-60, 61-90 y más de 90. | Core es la fuente de verdad de edades y de «vencido» (días de mora > 0). |
| «Excel» era un CSV con 17 columnas, que ignoraba filtros y búsqueda. | Un `.xlsx` real que sí respeta los filtros. Sin las columnas «antes de impuestos» (`base_gravable`, `saldo_base`). | El contrato de facturas de Core no las trae. |
| Agenda con bloques de facturas (vencen hoy, en 7 días, vencidas hace menos de 30 días). | Agenda de **compromisos**. | `CarteraRepository` no lista facturas de toda la cartera, y pedirlas cliente por cliente en cada visita no escala. Es un candidato a seguimiento: un contrato nuevo en Core. |
| Búsqueda por número de factura. | Nombre, NIT y código. | Es lo que filtra `ClientQuery`. |
| WhatsApp sin número (`wa.me/?text=`). | Con el celular del contacto cuando existe. | Core ya trae los contactos de SAP. |
| Tendencia de 60 días en una línea, con puntos equidistantes. | Línea con el eje en el calendario real, tabla y página de histórico con periodo a elección. | El portal perdía los días sin carga. |
| Gestiones `nota` y `compromiso`; «incumplido» derivado en el navegador (con fecha UTC). | Notas y compromisos de Core; «incumplido» lo fija la tarea diaria de Core con la fecha de Bogotá. | El portal marcaba incumplido desde las 7 p. m. |
| Sin «equipo» más que una lista de códigos en el usuario. | `advisor_team` de Core. | Es el modelo de Core (A.5). |

## Pruebas y calidad

```bash
php artisan test tests/Modules/Asesores   # el módulo
composer lint                              # Pint y Larastan
```

- `Unit/`: reglas puras (`Domain/`), filtros de la URL, arquitectura y reglas de diseño de las vistas.
- `Feature/`: pantallas, escrituras, exportaciones y la suite de aislamiento, todo con Core de verdad. `Support/AdvisorWorld.php` arma el reparto de asesores, equipos y carteras que usan las pruebas (reutiliza los ayudantes de `tests/Modules/Core/Support`).
- Sin `.env` en el equipo, las pruebas piden una llave de aplicación: `APP_KEY=base64:… php artisan test`.

## Pendientes

- Un contrato nuevo de Core para listar las facturas abiertas de toda la cartera permitiría devolver los bloques «vencen hoy / esta semana» a la agenda y la mora promedio ponderada.
- `ClientSummary` no trae el cupo de crédito: el filtro «sobre el cupo» del portal solo existe en la ficha.
