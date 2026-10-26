# Módulo Core (núcleo)

Core es la base de la plataforma: los contratos que usan los demás módulos (`Contracts`, congelados), las entidades compartidas y las reglas de negocio comunes. Ningún otro módulo decide el estado de un cliente ni el ciclo de un compromiso: lo hace Core.

## Capas

| Carpeta | Contenido |
|---|---|
| `Contracts/` | Interfaces, objetos de datos, enums y excepciones públicos (ver `docs/arquitectura.md` §6). No cambian sin acordarlo. |
| `Domain/` | Reglas puras en PHP: sin Laravel, sin base de datos y sin reloj. Reciben fechas, saldos y parámetros ya resueltos. |
| `Application/` | Casos de uso: el ingreso con Microsoft (`Auth\`, ver «Ingreso con Microsoft»), la pantalla Equipo (`Team\`), el borrado de gestiones (`Activity\`, ver «Bitácora de gestiones»), el recálculo de saldos y estados de los clientes (`Portfolio\`, ver «Importación del robot»), los parámetros de las empresas (`Companies\`, ver «Empresas y parámetros») y la regla de escritura y los efectos de los compromisos (`Commitments\`, ver «Compromisos de pago»). |
| `Infrastructure/` | Eloquent (`Persistence\*Record`), el acceso por empresa y el alcance de la cartera (`Access\`), el catálogo de empresas y la empresa activa (`Companies\`), la bitácora de auditoría (`Audit\`), la bitácora de gestiones (`Activity\`), el adaptador de Microsoft sobre Socialite (`Auth\`), la importación de los lotes del robot (`Ingest\`), la lectura de la cartera (`Portfolio\`, ver «CarteraRepository») y los compromisos de pago (`Commitments\`, ver «Compromisos de pago»). |
| `Http/` | Controladores, FormRequests y middleware de las rutas web (ver «Ingreso con Microsoft», «Inicio y acceso», «Bitácora de gestiones», «Pantalla Equipo» y «Empresas y parámetros»). |
| `Console/` | Comandos de Artisan (`core:<acción>`), registrados en `bootModule()`: la tarea diaria de compromisos (ver «Tarea diaria de incumplimiento»). |
| `config/core.php` | Valores por defecto para sembrar los parámetros de cada empresa. |

## Estados del cliente

`Domain\Status\ClientStatusRules::resolve()` calcula el estado con las partidas abiertas de la última instantánea (`OpenItem`: saldo y días de vencida) y los parámetros de la empresa (`StatusParameters`).

| Tipo | Estados | Quién los cambia |
|---|---|---|
| Automático | Al día, Por vencer, Vencida, Pago recibido | Se recalculan con cada instantánea. |
| Manual | En gestión, Compromiso, Incumplido, Jurídico | Los usuarios (ver «Cambio de estado manual») o el flujo de compromisos. Sobreviven a nuevas instantáneas mientras el cliente deba. |

### Orden de evaluación

1. **Saldo cero.** Si el saldo total (con notas crédito, que restan) es menor o igual a la tolerancia, el cliente queda en «Pago recibido». Si estaba «Al día», sigue «Al día». La regla aplica también a los estados manuales, incluso «Jurídico». Sin partidas, el saldo es cero.
2. **Estado manual.** Si el cliente tiene saldo y está en un estado manual, lo conserva.
3. **Vencida.** Alguna partida con saldo supera los días de gracia después de su vencimiento.
4. **Por vencer.** Alguna partida con saldo vence en los próximos días de la ventana, vence hoy o ya venció pero sigue dentro de los días de gracia.
5. **Al día.** En cualquier otro caso.

Solo cuentan para los pasos 3 y 4 las partidas con saldo mayor a la tolerancia: una nota crédito o una factura saldada nunca vuelve vencido a un cliente.

### Parámetros

| Parámetro | SGX | SDC | Regla |
|---|---|---|---|
| Días de gracia (`grace_days`) | 15 | 5 | Se cuentan **después** del vencimiento. Con 15, una factura con 15 días de vencida aún no vuelve «Vencida» al cliente; con 16, sí. Con 5, el corte es entre 5 y 6 días. |
| Ventana de «Por vencer» (`due_soon_days`) | 5 | 5 | Una factura que vence en 5 días o menos vuelve «Por vencer» al cliente; una que vence en 6, no. |
| Tolerancia de saldo | 0,01 | 0,01 | `ClientStatusRules::BALANCE_TOLERANCE`: saldos de un centavo o menos cuentan como cero. |

Los días de vencida van del vencimiento a la fecha de corte, solo por fecha de calendario (sin horas): positivo = vencida, 0 = vence en el corte, negativo = aún no vence. `OpenItem::fromDates()` los calcula.

Los valores de la tabla son los de siembra (`config('core.companies')`). Los vigentes viven en la tabla `companies` y los editan un administrador o el gerente de cartera en la pantalla Parámetros (ver «Empresas y parámetros»). Las edades de la deuda (`AgingBuckets`) ignoran los días de gracia.

## Compromisos de pago

`Domain\Commitments\CommitmentRules` decide cuándo se incumple un compromiso y qué le pasa al estado del cliente. Las reglas devuelven un `StatusDecision`: conservar el estado (`keep`), cambiarlo (`set`, solo cuando el estado de verdad cambia) o recalcular el estado automático (`recompute`).

| Momento | Efecto en el cliente |
|---|---|
| Se crea | Pasa a «Compromiso», salvo si está en «Jurídico» o ya en «Compromiso». |
| Se incumple | Pasa a «Incumplido», salvo si tiene otro compromiso pendiente o está en «Jurídico», «Pago recibido» o ya en «Incumplido». |
| Se cumple o se anula | Si estaba en «Compromiso» o «Incumplido» y no tiene otro compromiso pendiente ni incumplido, se recalcula su estado automático con la última instantánea, ignorando el estado manual. Si no tiene pendientes pero sí otro incumplido, queda (o vuelve) en «Incumplido». En cualquier otro caso conserva su estado. |

**Incumplimiento diario.** Una tarea diaria marca «Incumplido» todo compromiso «Pendiente» cuya fecha de pago sea anterior a hoy (`CommitmentRules::isBroken()`). Un compromiso que vence hoy no se incumple hasta mañana. Solo cuenta la fecha, no la hora. Ver «Tarea diaria de incumplimiento».

### CompromisoService

`Infrastructure\Commitments\EloquentCompromisoService` implementa `CompromisoService` (`docs/arquitectura.md` §6.5) y Core la registra en el contenedor (una instancia por petición o por job). Guarda los compromisos en la tabla `commitments` (`Persistence\CommitmentRecord`, con `CommitmentRecordFactory` y sus estados `fulfilled()`, `broken()` y `cancelled()`). Los compromisos nunca se borran.

| Columna | Contenido |
|---|---|
| `company`, `client_id` | La empresa (copiada del cliente) y el cliente. Un cliente con compromisos nunca se borra del todo. |
| `advisor_code` | El asesor de SAP (`SlpCode`) que tenía el cliente al crear el compromiso. |
| `amount`, `due_date` | El valor prometido en pesos (`decimal(18,2)`) y la fecha de pago (solo la fecha, en America/Bogota). |
| `status` | El valor de `CommitmentStatus`: `pendiente`, `cumplido`, `incumplido` o `anulado`. |
| `notes` | Texto libre que acompaña el compromiso. |
| `created_by` | Quién lo registró. Un usuario con compromisos no se borra del todo; con el borrado lógico se sigue mostrando como autor. |
| `fulfilled_at`, `broken_at`, `cancelled_at`, `cancellation_reason` | Cuándo se cumplió, se incumplió o se anuló, y el motivo de la anulación. |

**Quién escribe** (crear, editar, cumplir y anular; `Application\Commitments\CommitmentWriteRule`):

| Empresa del cliente | Escribe |
|---|---|
| SGX | El asesor del cliente: un usuario con el rol `asesor` vinculado (`advisors.user_id`) al asesor de SAP que el cliente tiene hoy (`clients.advisor_code`, en SGX). |
| SDC | La analista de cartera (`analista_cartera`). |

- Cualquier otro usuario que ve al cliente recibe `AuthorizationException`: `gerente_cartera`, `admin`, `gerente_comercial`, la analista en SGX y el asesor en SDC leen, pero no escriben.
- Antes de la regla se revisa el alcance: un cliente (o el cliente de un compromiso) que no existe, está eliminado o está fuera del alcance del usuario en sus empresas (`Portfolio\ClientReach`, como en la bitácora) lanza `RecordNotFound`. Por eso un asesor que intenta escribir en un cliente de otro asesor recibe `RecordNotFound` y no `AuthorizationException`: para él ese cliente no existe (§6.2) y la respuesta no revela que el id existe. Sin sesión iniciada pasa lo mismo.
- El orden de las revisiones es: alcance (`RecordNotFound`), regla de la empresa (`AuthorizationException`), datos (`InvalidArgumentException`) y ciclo de vida (`DomainException`).

**Leer.**

- `find()` busca entre los clientes del alcance del usuario en todas sus empresas, igual que las escrituras: un compromiso de SDC se encuentra aunque el usuario trabaje en SGX.
- `paginate()` sigue la empresa activa o la vista de grupo, o las empresas de `CommitmentQuery::$companies` recortadas a las disponibles, como `CarteraRepository`. Los filtros se suman: `clientId`, `advisorCode` (el que guardó el compromiso), `statuses`, `dueFrom` y `dueTo` (las dos fechas incluidas, sin importar la hora). El orden es `due_date`, `amount` o `created_at`, ascendente o descendente, y los empates van por id ascendente. Lee la página de `?page=` y lanza `InvalidArgumentException` si `$perPage` es menor que 1.
- El cliente (con su nombre actual) y el autor de toda una página se leen con una consulta cada uno.
- Los compromisos de clientes eliminados no aparecen. Sin sesión iniciada, `find()` da `null` y `paginate()` una página vacía.

**Escribir.** Cada escritura va en una sola transacción, con el compromiso y el cliente bloqueados. Deja una gestión «Compromiso de pago» en la bitácora del cliente, escrita por el usuario (mueve su última actividad), y una entrada de auditoría con sujeto `commitment`:

| Método | Hace | Bitácora | Auditoría |
|---|---|---|---|
| `create()` | Crea el compromiso «Pendiente» con el asesor que el cliente tiene hoy. El valor se redondea a centavos; las notas se guardan sin espacios sobrantes (en blanco, ninguna). | «Compromiso de pago por $ 2.500.000 para el 22/10/2026», con las notas como detalle. | `core.commitment.created`, con `changes` `advisor_code`, `amount`, `due_date` y `notes`. |
| `update()` | Solo un «Pendiente»; si no, `DomainException`. Cambia los campos que no vienen en `null`. Unas notas en blanco tampoco cambian: no se pueden borrar. Si nada cambia, no escribe nada. | «Compromiso actualizado: $ 3.000.000 para el 25/10/2026», con las notas como detalle. | `core.commitment.updated`, solo con los campos que cambian (`old` y `new`). |
| `markFulfilled()` | Un «Pendiente» o un «Incumplido» (un pago tardío también cumple) pasa a «Cumplido» y guarda `fulfilled_at`. Un compromiso cumplido o anulado lanza `DomainException`. | «Compromiso cumplido: $ 2.500.000 para el 22/10/2026», con la nota como detalle. | `core.commitment.fulfilled`, con `changes.status` y `context.note` si hay nota. |
| `cancel()` | Un «Pendiente» o un «Incumplido» pasa a «Anulado» con su motivo y `cancelled_at`. Un motivo en blanco lanza `InvalidArgumentException`; un compromiso cumplido o anulado, `DomainException`. | «Compromiso anulado: <motivo>», cortado a 255 caracteres (el motivo completo queda en el compromiso y en la auditoría). | `core.commitment.cancelled`, con `changes.status` y `context.reason`. |

- El valor debe ser mayor que cero después de redondearlo a centavos, y caber en la columna; si no, `InvalidArgumentException`.
- Las gestiones de compromiso llevan `metadata` `commitment_id`, `amount` (en pesos) y `due_date` (`Y-m-d`). La auditoría lleva `context.client_id`.
- **Estado del cliente** (`Application\Commitments\CommitmentClientStatus`, con la tabla de arriba): al crear aplica `afterCreate()`, y al cumplir o anular, `afterClose()` según si el cliente tiene otro compromiso pendiente. Recalcular es tomar el estado automático de la última instantánea de la empresa (`RecomputeClientStatuses::automaticStatus()`) sin mirar el estado manual: «Vencida», «Por vencer» o «Al día» si el cliente debe, y «Pago recibido» si ya no debe (o no aparece en la instantánea). Editar un compromiso no cambia el estado.
- Cada cambio de estado guarda `status` y `status_changed_at`, deja una gestión «Cambio de estado» («Por el compromiso de pago: de «Vencida» a «Compromiso»», con `metadata` `from`, `to` y `commitment_id`) y audita `core.client.status_changed` con `changes.status` y `context` `{"source": "commitment", "commitment_id": …}`.

### Tarea diaria de incumplimiento

El comando `core:compromisos-incumplidos` (`Console\BreakOverdueCommitmentsCommand`, que llama a `Application\Commitments\BreakOverdueCommitments`) corre todos los días a las 00:15 de Bogotá. Lo programa `CoreServiceProvider::schedule()` con `withoutOverlapping(10)`: tarda segundos, así que el candado de un proceso que muera vence a los 10 minutos y no al día siguiente. Correrlo a mano es seguro.

1. Busca, de a 100 por consulta (`BreakOverdueCommitments::CHUNK`), los compromisos «Pendiente» cuya fecha de pago es anterior a hoy en el calendario de Bogotá: el que vence hoy espera a mañana, y a las 23:30 del 14 todavía es 14 aunque en UTC ya sea 15.
2. Cada uno va en su propia transacción, con el compromiso y el cliente bloqueados, y se revisa otra vez con `CommitmentRules::isBroken()`: si alguien lo cumplió o lo anuló entretanto, no lo toca.
3. Lo pasa a «Incumplido» y guarda `broken_at`; deja en la bitácora una gestión «Compromiso de pago» del sistema («Compromiso incumplido: vencía el 14/10/2026», con `metadata` `commitment_id`, `amount` y `due_date`); y audita `core.commitment.broken` con `changes.status` y `context` `client_id` y `due_date`.
4. Aplica `CommitmentRules::afterBreak()`: el cliente pasa a «Incumplido» salvo que tenga otro compromiso pendiente o esté en «Jurídico», «Pago recibido» o ya en «Incumplido». El cambio queda como en «CompromisoService»: `status_changed_at`, una gestión «Cambio de estado» del sistema y `core.client.status_changed` con `source: commitment`. Si un cliente tiene varios compromisos vencidos, pasa a «Incumplido» una sola vez, con el último.

- Corre como el sistema: la consola no tiene sesión, así que sus gestiones y su auditoría no tienen autor y no mueven la última actividad del cliente.
- Es idempotente: una segunda corrida no encuentra nada que marcar y no escribe nada.
- Si un compromiso falla, su transacción se deshace, el error se reporta al log y la tarea sigue con los demás. El comando termina con error (código 1) si alguno falló.
- Los compromisos de un cliente eliminado (borrado lógico) esperan: se incumplen en la primera corrida después de restaurarlo.
- Imprime un resumen: «Marcamos 2 compromisos como incumplidos.» (o «No hay compromisos vencidos para marcar como incumplidos.») y, si algo falló, «No pudimos marcar 1 compromiso; el error quedó en el log.».
- Un pago tardío sigue cumpliendo: `markFulfilled()` acepta un compromiso «Incumplido» y, si no queda otro pendiente, devuelve al cliente a su estado automático.

## Empresas y parámetros

La tabla `companies` (llave `code`: `SGX` o `SDC`) guarda el nombre y los parámetros de cada empresa: `grace_days` y `due_soon_days`. La migración `2026_10_01_000001` crea las dos filas con el nombre de `CompanyCode::label()` y los valores de `config('core.companies')`; desde ahí, los vigentes son los de la tabla.

`Infrastructure\Companies\CompanyCatalog` lee la tabla una vez por petición (o por job) y entrega:

| Método | Devuelve |
|---|---|
| `all()` | Las empresas (`Contracts\Data\Company`) en el orden de `CompanyCode`. |
| `get($code)` | Una empresa con su nombre, días de gracia y paleta oficial. |
| `parameters($code)` | Los `StatusParameters` de la empresa para `ClientStatusRules`. |
| `refresh()` | Olvida lo leído; úsalo después de editar una empresa. |

Si falta la fila de una empresa, usa los valores de `config('core.companies')` y la etiqueta del enum.

**Pantalla Parámetros.** Para administradores (TI, «Usuarios, parámetros y buzones» en A.5) y para el gerente de cartera, que ajusta las reglas de estado de la cartera: el permiso `core.companies.manage`, que `CorePermissionSeeder` da a `admin` y a `gerente_cartera`. Aparece en el menú como «Parámetros», en el grupo «Administración», después de «Equipo» (`order: 910`), así que el inicio de un administrador sigue siendo Equipo. El gerente de cartera ve «Parámetros» sin «Equipo»; mientras ningún otro módulo le registre una pantalla, `HomeController` lo lleva a Parámetros como su inicio. La pantalla muestra y edita las dos empresas, sin importar las empresas asignadas al usuario. Los demás roles (`analista_cartera`, `asesor`, `gerente_comercial`, `tesoreria`) reciben 403 en todas sus rutas y los invitados van a `/login`.

| Ruta | Nombre | Hace |
|---|---|---|
| `GET /empresas` | `core.empresas.index` | Una tabla con cada empresa («Segurex Latam (SGX)»), sus días de gracia, su ventana de «Por vencer» y el enlace «Editar». |
| `GET /empresas/{empresa}/editar` | `core.empresas.edit` | El formulario con los valores vigentes. |
| `PUT /empresas/{empresa}` | `core.empresas.update` | Guarda y vuelve a la tabla. |

- `{empresa}` es un valor de `CompanyCode` (`SGX` o `SDC`, en mayúsculas); cualquier otro da 404.
- «Días de gracia» (`grace_days`) es un entero de 0 a 120 y «Ventana de «Por vencer»» (`due_soon_days`), de 0 a 60 (`Http\Requests\Companies\UpdateCompanyParametersRequest`). Un valor vacío, negativo, mayor que el tope o con decimales falla la validación en español («El campo días de gracia no debe ser mayor que 120.»).
- `Application\Companies\UpdateCompanyParameters` guarda con la fila bloqueada (si falta, la crea con la etiqueta del enum) y, si algo cambió:
  1. audita `core.company.updated`: sujeto `company`, id el código y `changes` solo con los campos que cambian (`grace_days`, `due_soon_days`), con `old` y `new`;
  2. llama `CompanyCatalog::refresh()`, así que el resto de la petición (`CurrentCompany::of()`, `parameters()`) ya lee los valores nuevos;
  3. encola `Application\Companies\RecomputeCompanyStatuses`, que corre `RecomputeClientStatuses::forCompany()` con los parámetros nuevos sobre la última instantánea de la empresa (ver «Importación del robot»). Cada cambio de estado se audita y deja su gestión del sistema como en una importación, con `source: parameters` en el `context` de la auditoría y en la `metadata` de la gestión. Los clientes de la otra empresa no cambian.
- Vuelve a la tabla con el flash `status` «Guardamos los parámetros de Segurex Latam. Los estados de sus clientes se recalculan en unos minutos.». Si nada cambió, no escribe, no audita ni encola nada, y el flash dice «Los parámetros de Segurex Latam no cambiaron.».
- **Cola.** Como la importación, el trabajo tiene `$timeout = 300` y falla con la primera excepción (`$maxExceptions = 1`): queda en `failed_jobs` y `php artisan queue:retry` lo vuelve a correr sin duplicar nada (el recálculo solo escribe lo que cambia). Lee los parámetros cuando corre, así que dos guardados seguidos terminan con los últimos. En pruebas la cola es `sync`: el recálculo corre en la misma petición.
- **Un recálculo por empresa a la vez.** El recálculo y la importación del robot comparten un candado por empresa (`Application\Portfolio\CompanyStatusesLock`, llave `company-statuses:SGX` o `company-statuses:SDC`, con el `WithoutOverlapping` compartido de Laravel), así que un cambio de parámetros y una carga de la misma empresa nunca recalculan sus estados al mismo tiempo; las dos empresas sí corren en paralelo. Un trabajo que encuentra el candado ocupado vuelve a la cola y se reintenta cada 60 segundos durante 30 minutos (`retryUntil()`); esperar no cuenta como excepción. Una excepción real o pasarse del `$timeout` lo fallan de una vez (`$maxExceptions = 1`, `$failOnTimeout`), sin reintentos. El candado vence solo a los 15 minutos, por encima del `$timeout` de 300 segundos, así que un worker que el hosting mate no lo deja tomado para siempre.

La tabla `company_user` asigna empresas a los usuarios (`user_id`, `company`). Core la expone como `$user->companies()` con `User::resolveRelationUsing()`, sin editar `App\Models\User`.

## Empresa activa

`Infrastructure\Companies\SessionCurrentCompany` implementa `CurrentCompany` y Core la registra en el contenedor (una instancia por petición).

**Qué empresas ve cada usuario** (`Infrastructure\Access\CompanyAccess`):

| Usuario | Empresas |
|---|---|
| Invitado, sin guardar, inactivo o borrado | Ninguna. |
| Sin rol de Spatie | Ninguna, aunque tenga filas en `company_user`: ve la paleta neutra y la pantalla para solicitar acceso. |
| `admin` (TI) | Las dos, sin filas en `company_user` (A.5). |
| Cualquier otro rol | Las de sus filas en `company_user`. |

**Reglas de la empresa activa:**

- La elección vive en la sesión, en la llave `core.company`, con `SGX`, `SDC` o `group` (vista de grupo).
- Sin elección guardada, o si la guardada ya no está disponible (le quitaron el acceso), el usuario trabaja en la primera empresa que puede ver.
- La vista de grupo exige dos empresas. Si el usuario la tenía guardada y le queda una sola, vuelve a esa.
- `switchTo()` lanza `CompanyNotAvailable` si la empresa, o la vista de grupo, no está disponible. Sin sesión (consola, colas), la elección dura solo lo que dura el proceso.
- `palette()` es la de la empresa activa; la vista de grupo, los invitados y los usuarios sin rol ven la paleta neutra.
- `of($code)` devuelve cualquier empresa, sin importar el usuario (jobs, colas y plantillas de correo).

**Selector.** `POST /empresa` (`core.empresa.cambiar`, solo con sesión iniciada) recibe `empresa` = `SGX`, `SDC` o `grupo`. Si funciona, vuelve atrás con el flash `status` («Ahora trabajas en …»); si la empresa no está disponible, con el flash `error`. Cada cambio queda en la auditoría como `core.company.switched`, con `changes.company` = `old` y `new` (`SGX`, `SDC` o `grupo`), porque cambia los clientes que ve el usuario. Elegir la empresa que ya estaba activa no deja entrada. Un valor distinto falla la validación del campo «empresa». El layout `<x-layouts.app>` muestra el selector solo cuando el usuario tiene más de una empresa.

## Roles y permisos

Los roles son los de `Contracts\Enums\Role` (Spatie, guard `web`, sin equipos). `CorePermissionSeeder` siembra:

| Permiso | Roles | Qué permite |
|---|---|---|
| `core.access` | Los seis | Usar la plataforma. Un usuario sin rol no lo tiene. |
| `core.team.manage` | `admin` | Usar la pantalla Equipo (ver «Pantalla Equipo»). |
| `core.companies.manage` | `admin`, `gerente_cartera` | Editar los días de gracia y la ventana de «Por vencer» de cada empresa: pantalla Parámetros (ver «Empresas y parámetros»). |
| `core.activities.delete` | `gerente_cartera`, `analista_cartera` | Eliminar (borrado lógico) una gestión de la bitácora de un cliente (ver «Bitácora de gestiones»). |
| `core.clients.change_status` | `gerente_cartera`, `analista_cartera` | Cambiar a mano el estado de un cliente: Kanban y ficha (ver «Cambio de estado manual»). |
| `core.clients.delete` | `gerente_cartera` | Eliminar (borrado lógico) y restaurar clientes (ver «Borrado lógico de clientes»). Por confirmar (ver «Pendientes»). |

Sembrar `core.access` también garantiza que existan los seis roles, para que la pantalla de equipo pueda asignarlos. Correr el seeder otra vez no duplica nada.

## Asesores de SAP

La tabla `advisors` guarda los vendedores de SAP de cada empresa (`OSLP`) y el usuario al que están vinculados. `Infrastructure\Persistence\AdvisorRecord` la representa.

| Columna | Contenido |
|---|---|
| `company`, `code` | La empresa (`SGX` o `SDC`) y el `OSLP.SlpCode`. El código es único dentro de cada empresa. |
| `name` | El nombre del vendedor en SAP. |
| `email` | `OSLP.Email`, sin espacios y en minúsculas, igual que `users.email`. Uno vacío se guarda como `null`. |
| `active` | Si el vendedor está activo en SAP. Solo los activos se vinculan y dan rol al ingresar. |
| `user_id` | El usuario vinculado, o `null`. Si el usuario se borra del todo (no el borrado lógico), el asesor queda sin vincular. |

Core la expone como `$user->advisors()` con `User::resolveRelationUsing()`. La carga desde SAP la hace cada lote `asesores` del robot (ver «Importación del robot»). El ingreso con Microsoft vincula por correo (ver «Ingreso con Microsoft») y la pantalla Equipo vincula a mano. `AdvisorRecordFactory` crea vendedores ficticios (estados `inactive()` y `linkedTo($user)`).

**Vínculo a mano** (pantalla Equipo, campo «Asesor de SAP vinculado»):

- Un asesor tiene un solo usuario (`advisors.user_id`); un usuario puede tener varios asesores, por ejemplo uno en cada empresa.
- Un asesor vinculado a otra persona aparece deshabilitado, con «Vinculado a <nombre>». Para pasarlo, desvincúlalo primero en la página de esa persona: Equipo nunca se lo quita a nadie.
- Si llega igual (una petición alterada o dos administradores a la vez), `TeamMembers` bloquea las filas, lo rechaza con el error «Ese asesor ya está vinculado a otra persona.» en el campo y no guarda nada del formulario.
- Se pueden vincular asesores inactivos en SAP (aparecen con «(inactivo)»). El ingreso con Microsoft, en cambio, solo vincula activos.

**Equipo del gerente comercial.** La tabla `advisor_team` guarda los asesores de SAP cuya cartera ve cada gerente comercial (A.5: «Ve la cartera de los asesores de su equipo»).

| Columna | Contenido |
|---|---|
| `user_id` | El gerente comercial. Si el usuario se borra del todo, sus filas se borran en cascada; con el borrado lógico el equipo se conserva y vuelve con la restauración. |
| `advisor_id` | El asesor. Si el asesor se borra, sus filas se borran en cascada. |

La llave es (`user_id`, `advisor_id`): un asesor puede estar en el equipo de varios gerentes. Core la expone como `$user->teamAdvisors()`. Solo el rol `gerente_comercial` tiene equipo: con cualquier otro rol, Equipo lo guarda vacío, también cuando a un gerente le cambian el rol.

> **En código de `app/`** usa `AdvisorRecord::linkedTo($user)` y `AdvisorRecord::teamOf($user)`, no `$user->advisors()` ni `$user->teamAdvisors()`: Larastan no ve las relaciones registradas con `resolveRelationUsing()`.

**Alcance de la cartera** (`docs/arquitectura.md` §6.2). `CarteraRepository` lee estas dos tablas: un `asesor` ve los clientes de los asesores vinculados a él (`advisors.user_id`) y un `gerente_comercial`, los de los asesores de su equipo (`advisor_team`) y los de los vinculados a él. Ver «CarteraRepository».

## Entidades de cartera

Core es dueño de las entidades compartidas, así que sus tablas no llevan prefijo. `Infrastructure\Persistence` las representa con `PortfolioLoadRecord`, `ClientRecord`, `InvoiceRecord` y `ContactRecord`, cada uno con su factory.

| Tabla | Qué guarda |
|---|---|
| `portfolio_loads` | Una fila por instantánea de cartera (CargaCartera): la empresa, la fecha de corte (`cutoff_date`), la carga de Ingesta de la que salió (`load_id`, única), el origen (`robot` o `manual`), cuántas partidas trae (`invoice_count`) y los totales: `total` (suma de saldos) y `overdue` (saldo de las partidas vencidas al corte). |
| `clients` | Un cliente por empresa y código de SAP (`company` y `code` = `OCRD.CardCode`, únicos juntos): nombre, NIT (`tax_id`), grupo, municipio, condición de pago, cupo (`credit_limit`), asesor actual (`advisor_code` = `SlpCode`) y gestor responsable (`manager_user_id`). También guarda, de la última instantánea, el saldo (`balance`), lo vencido (`overdue`), los días de vencida de la partida con saldo más atrasada (`max_days_overdue`) y el estado (`status`, con `status_changed_at`), para que las pantallas ordenen y filtren sin leer partidas. |
| `invoices` | Las partidas abiertas de cada instantánea: una fila por cuota de factura (o nota crédito, recibo o asiento) con documento, tipo (`TransType` de SAP), cuota, emisión, vencimiento, valor (`amount`), saldo (`balance`, negativo en notas crédito), días de vencida al corte (`days_overdue`) y asesor. |
| `contacts` | Las personas de contacto de cada cliente: nombre, correo, teléfono, cargo, si es el principal (`is_primary`) y el origen (`source`: `sap` o la plataforma). La carga desde SAP (lote `contactos`) llega cuando Ingesta defina las columnas de ese lote en `etl/esquema.py`. |

**Reglas:**

- **Las instantáneas nunca se sobrescriben** (A.4). Cada carga crea su fila en `portfolio_loads` y sus propias partidas en `invoices`; las anteriores quedan como histórico. Si se borra una instantánea, sus partidas se borran en cascada.
- **Clientes con borrado lógico** (ver «Borrado lógico de clientes»). Un cliente eliminado conserva su fila, su histórico y su código: sigue ocupando el índice único (`company`, `code`). La base impide borrar del todo un cliente con partidas; si se borra del todo uno sin partidas, sus contactos se borran con él.
- Si el gestor responsable se borra del todo (no el borrado lógico), el cliente queda sin gestor.
- Los montos son `decimal(18,2)` y los modelos los entregan como cadenas exactas (cast `decimal:2`), p. ej. `"1234567.89"`. `company` es el valor de `CompanyCode`, `status` el de `ClientStatus` y `source` el de `LoadSource`.
- Los días de vencida van del vencimiento al corte, igual que `Invoice::$daysOverdue`: positivo = vencida, 0 = vence en el corte, negativo = aún no vence.

**Factories.** `ClientRecordFactory` crea clientes ficticios de Segurex, al día y sin saldo, con un NIT válido (dígito de verificación de la DIAN, `ClientRecordFactory::checkDigit()`) y el código `C<NIT>`. `InvoiceRecordFactory` crea una partida que vence hoy, con su propia instantánea y su cliente salvo que la prueba los pase con `for()`. `PortfolioLoadRecordFactory` y `ContactRecordFactory` (estado `primary()`) completan el juego.

## Importación del robot

Ingesta guarda cada lote del robot (o de una carga manual) y despacha `BatchAccepted` una vez por lote aceptado (`docs/arquitectura.md` §6.9). Core lo escucha con dos listeners, registrados en `bootModule()`. Los dos van a la cola y solo se encolan para su lote (`shouldQueue()`): los demás lotes no crean trabajos.

| Lote | Listener (`Infrastructure\Ingest\`) | Qué hace |
|---|---|---|
| `cartera` | `ImportReceivablesSnapshot` | Guarda la instantánea con sus partidas, crea o actualiza los clientes y recalcula el saldo y el estado de todos los clientes de la empresa. |
| `asesores` | `ImportAdvisors` | Actualiza los asesores de SAP de la empresa y desactiva los que no llegan. Un lote sin ningún asesor válido se ignora: es una extracción fallida, no una empresa sin vendedores. |
| `contactos` | — | Pendiente: llega cuando Ingesta defina las columnas del lote. |

**Cartera, paso a paso.** Todo va en una sola transacción: si algo falla, no queda nada a medias.

1. **Instantánea.** Una fila en `portfolio_loads` con la empresa, el corte, el `load_id` de Ingesta, el origen, el número de partidas, `total` (suma de `saldo`) y `overdue` (suma de `saldo` de las partidas vencidas al corte).
2. **Clientes**, por empresa y `codigo_sn`: `name` = `nombre_sn`, `tax_id` = `nit`, `group` = `grupo`, `city` = `municipio`, `payment_terms` = `condicion_pago`, `credit_limit` = `limite_credito` y `advisor_code` = `slp_code`. El texto vacío se guarda como `null` y el `slp_code` -1 de SAP («ningún empleado de ventas») como cliente sin asesor.
   - Los clientes eliminados (borrado lógico) también se actualizan y reciben sus partidas, pero siguen eliminados: borrarlos fue decisión de un usuario.
   - Una instantánea con un corte anterior al de la última (p. ej. una carga manual atrasada) queda en el histórico y solo crea los clientes nuevos: no cambia los datos de los demás ni su estado.
3. **Partidas**, de a 500 por consulta: `document_number` = `documento`, `document_type` = `tipo_doc`, `installment` = `no_plazo`, `issued_at` = `fecha_documento` (o `fecha_contabilizacion`, o el vencimiento), `due_at` = `fecha_vencimiento` (sin ella, el corte menos `dias`), `amount` = `importe_original`, `balance` = `saldo`, `days_overdue` = días del vencimiento al corte (`OpenItem::fromDates()`) y `advisor_code` = `slp_code`. Los montos se guardan exactos: se suman en centavos enteros (`Application\Portfolio\Cents`), nunca con `float`.
4. **Recálculo** de todos los clientes de la empresa (ver abajo).

**Idempotencia.** Si ya existe una instantánea con ese `load_id`, el listener no hace nada: un `BatchAccepted` repetido o un reintento no duplican partidas. Las instantáneas anteriores nunca se modifican.

**Recálculo de saldos y estados.** `Application\Portfolio\RecomputeClientStatuses::forCompany($company, $portfolioLoadId = null)` usa la instantánea indicada o, sin ella, la última de la empresa (corte más reciente; en empate, la última importada). Sin instantáneas no hace nada. Recalcula cada cliente de la empresa, también los eliminados:

| Campo | Valor |
|---|---|
| `balance` | Suma del saldo de sus partidas, notas crédito incluidas. Un cliente que no está en la instantánea queda en 0. |
| `overdue` | Suma del saldo de sus partidas con saldo positivo y días de vencida mayores que 0. |
| `max_days_overdue` | Días de vencida de la partida más atrasada entre las que deben más de la tolerancia (0,01); 0 si ninguna debe. Es negativo si ninguna está vencida todavía. |
| `status` | `ClientStatusRules::resolve()` con el estado actual y los parámetros vigentes de la empresa (`CompanyCatalog::parameters()`, tabla `companies`). Ver «Estados del cliente»: un cliente que ya no debe pasa a «Pago recibido» (o sigue «Al día») y un estado manual se conserva mientras deba. |

Solo se escriben los clientes cuyos valores cambian. Cuando cambia el estado de un cliente que ya existía, se guarda `status_changed_at` y se audita `core.client.status_changed`: sujeto `client`, `changes.status` con `old` y `new`, y `context` con `load_id` y `cutoff_date` de la instantánea (más `source` cuando el recálculo no viene de una importación: `parameters` desde la pantalla Parámetros). No tiene usuario porque lo hace el sistema. Cada cambio deja también una gestión del sistema en la bitácora del cliente (ver «Bitácora de gestiones»). Un cliente que crea la importación recibe su primer estado (y `status_changed_at` si no queda «Al día») sin entrada de auditoría ni de bitácora: es su estado inicial, no un cambio; así la primera carga del robot no escribe una entrada por cliente (`forCompany()` recibe esos clientes en `$newClientIds`). La pantalla Parámetros lo reutiliza con `source: parameters` (ver «Empresas y parámetros»); el flujo de compromisos lee el estado automático de un solo cliente con `automaticStatus()` (ver «Compromisos de pago»).

**Asesores.** Por cada fila, por empresa y `slp_code`:

- `name` = `nombre`, `email` = `email` sin espacios y en minúsculas (vacío = `null`) y `active` = `activo` es `Y` (sin importar mayúsculas ni espacios).
- Los asesores de esa empresa que no llegan en el lote quedan inactivos; los de la otra empresa no cambian.
- Nunca toca `user_id`: el vínculo lo hacen el ingreso con Microsoft y la pantalla Equipo. Si alguien ingresó antes de que llegara su asesor, queda vinculado en su siguiente ingreso.
- Repetir el lote deja lo mismo.

**Cola en el hosting compartido.** Los listeners van a la cola `database`, que vacía la tarea de cada minuto (`docs/arquitectura.md` §10). La cartera se escribe por lotes (clientes y partidas de a 500, y el recálculo lee las partidas en una sola consulta), así que una instantánea de miles de filas tarda segundos. `ImportReceivablesSnapshot` tiene `$timeout = 300` y falla con la primera excepción (`$maxExceptions = 1`): la transacción se deshace y el trabajo queda en `failed_jobs`; `php artisan queue:retry` lo vuelve a correr sin duplicar nada. En pruebas la cola es `sync`: los listeners corren en el momento.

**Un recálculo por empresa a la vez.** La importación comparte con `RecomputeCompanyStatuses` (pantalla Parámetros) un candado por empresa: `Application\Portfolio\CompanyStatusesLock`, llave `company-statuses:SGX` o `company-statuses:SDC`. Así una carga y un cambio de parámetros de la misma empresa nunca recalculan sus estados al mismo tiempo, mientras las dos empresas corren en paralelo. Si el candado está ocupado, la importación vuelve a la cola y se reintenta cada 60 segundos durante 30 minutos (`retryUntil()`); como es idempotente por `load_id`, reintentarla es seguro. Ver «Empresas y parámetros».

## CarteraRepository

`Infrastructure\Portfolio\EloquentCarteraRepository` implementa `CarteraRepository` (`docs/arquitectura.md` §6.4) y Core la registra en el contenedor (una instancia por petición o por job). En cada llamada lee el usuario con sesión iniciada y `CurrentCompany`, así que nunca guarda un alcance viejo. Solo usa SQL portable (SQLite en local, MySQL 8 en CI y producción).

**Alcance** (`Infrastructure\Access\PortfolioScope`, §6.2). Las empresas son las de `CurrentCompany::scope()` (la activa, o todas en la vista de grupo); si la consulta trae `ClientQuery::$companies`, esas, recortadas a `CurrentCompany::available()`. Dentro de ellas:

| Usuario | Clientes y asesores que ve |
|---|---|
| `admin`, `gerente_cartera`, `analista_cartera` | Todos. |
| `asesor` | Los clientes de los asesores de SAP vinculados a él (`advisors.user_id`), y esos asesores. |
| `gerente_comercial` | Los de los asesores de su equipo (`advisor_team`) y de los vinculados a él, y esos asesores. |
| `tesoreria` o sin rol | Ninguno. |
| Invitado, inactivo o eliminado | Ninguno: no tiene empresas (`CompanyAccess`). |

- Un cliente es de un asesor por empresa y código (`clients.company` y `clients.advisor_code`): el 12 de SGX y el 12 de SDC son asesores distintos.
- Un usuario con varios roles ve la suma de lo que ve cada uno.
- Lo que está fuera del alcance no existe: listas vacías, `client()` en `null` y `openInvoices()` vacío. Los clientes eliminados (borrado lógico) nunca aparecen.
- `client()`, `openInvoices()` y `advisors()` usan las empresas de `CurrentCompany::scope()`: un cliente de SDC no aparece mientras el usuario trabaja en SGX.
- **Copia del sistema.** `asSystem()` devuelve una copia sin usuario que ve todos los clientes de las empresas de la consulta, o de todas si no trae ninguna. Es solo para jobs, colas y listeners, nunca para controladores.

**Listas.** `paginateClients()` y `allClients()` (`Portfolio\ClientListing`) filtran y ordenan con las cifras que cada cliente guarda de la última instantánea de su empresa (ver «Entidades de cartera»). Los filtros se suman:

| Filtro de `ClientQuery` | Clientes que deja |
|---|---|
| `search` | Los que tienen ese texto en el nombre, el código de SAP o el NIT, sin importar mayúsculas. `%` y `_` se buscan tal cual; un texto en blanco no filtra. |
| `statuses` | Los que están en uno de esos estados. |
| `advisorCode` | Los de ese `SlpCode`. |
| `onlyOverdue` | Los que tienen `overdue` mayor que 0. |
| `inactiveSince` | Los que no tienen actividad (`last_activity_at` vacío) o cuya última actividad es anterior a ese momento. |

| Orden (`sort`) | Columna |
|---|---|
| `name` | El nombre, sin importar mayúsculas. |
| `balance`, `overdue` | `balance` y `overdue`. |
| `days_overdue` | `max_days_overdue`; los valores negativos cuentan como 0, igual que en `ClientSummary`. |
| `status` | El orden de `ClientStatus` (columnas del Kanban), con un `CASE`, no el orden alfabético de los valores. |
| `last_activity` | `last_activity_at`; en ascendente, los clientes sin actividad van primero. |

- Los empates se resuelven por nombre (sin importar mayúsculas) y luego por id, los dos ascendentes.
- `paginateClients()` lee la página de `?page=` y lanza `InvalidArgumentException` si `$perPage` es menor que 1.
- `allClients()` resuelve el alcance al llamarla y devuelve un generador que lee de a 500 clientes (`EloquentCarteraRepository::CHUNK`) en el mismo orden, para exportar sin cargar todo en memoria.
- El asesor de SAP (por empresa y código, con `userId`) y el gestor responsable de cada página o bloque se leen con una consulta cada uno. Un gestor eliminado (borrado lógico) se sigue mostrando: el cliente conserva su gestor hasta que alguien lo cambie.

**Ficha, facturas y asesores.**

- `client()` agrega a `ClientSummary` el cupo, el grupo, el municipio, la condición de pago y los contactos: los principales primero y luego en el orden en que se guardaron.
- `openInvoices()` devuelve las partidas del cliente en la **última instantánea** de su empresa (el corte más reciente y, en empate, la última importada) cuyo saldo, en valor absoluto, supera la tolerancia de 0,01 (`ClientStatusRules::BALANCE_TOLERANCE`): las notas crédito también salen. Van por vencimiento y luego por id.
- `advisors()` devuelve los asesores cuyos clientes ve el usuario (todos los de sus empresas para los roles con acceso total), por nombre, empresa (orden de `CompanyCode`) y código, con su usuario vinculado (`userId`).

### Totales e histórico

`totals()` y `history()` suman las **partidas** de las instantáneas (no los saldos guardados en `clients`), solo de los clientes del alcance que cumplen los filtros de la consulta; el orden de la consulta no importa. `Portfolio\SnapshotTotals` lo hace en una sola consulta agrupada (primero por instantánea y cliente, después por instantánea), en centavos enteros (`ROUND(balance * 100)`), así que las sumas son exactas en MySQL y en SQLite; se pasan a pesos (`float`) al final.

| Cifra de `PortfolioTotals` | Valor |
|---|---|
| `total` | Suma del saldo de las partidas, notas crédito incluidas. |
| `overdue` | Suma del saldo de las partidas con `days_overdue` mayor que 0, notas crédito vencidas incluidas. |
| `clientCount` | Clientes con al menos una partida en esas instantáneas. |
| `overdueClientCount` | Los de ellos cuya suma vencida es mayor que 0: una nota crédito vencida puede compensar una factura vencida. |
| `aging` | Edades por `days_overdue`, sin días de gracia: sin vencer (0 o menos), 1 a 30, 31 a 60, 61 a 90 y más de 90. |

- **`totals()`** usa la última instantánea de cada empresa del alcance (el corte más reciente; en empate, la última importada). En la vista de grupo combina las de las dos empresas y `cutoffDate` es el corte más antiguo de ellas.
- Sin instantáneas en las empresas del alcance, o si el usuario no ve ningún cliente (invitado, `tesoreria`, sin rol), devuelve `PortfolioTotals::empty()`. Si hay instantánea pero ningún cliente cumple la consulta, devuelve ceros con su fecha de corte.
- **`history($from, $to)`** devuelve un total por fecha de corte con instantánea en el rango, incluidos los dos extremos (se comparan solo las fechas, sin la hora), de la más antigua a la más reciente. Para cada empresa y fecha usa la última carga importada ese día, y combina las empresas que comparten la fecha. Lanza `InvalidArgumentException` si `$from` es posterior a `$to`.
- Los filtros de la consulta se aplican con los datos actuales de cada cliente (estado, asesor, saldo guardado), también en las fechas pasadas del histórico.

## Bitácora de gestiones

`Infrastructure\Activity\DatabaseGestionLogger` implementa `GestionLogger` (`docs/arquitectura.md` §6.6) y Core la registra en el contenedor (una instancia por petición o por job). Guarda cada gestión en la tabla `activities` (`Persistence\ActivityRecord`, con `ActivityRecordFactory`):

| Columna | Contenido |
|---|---|
| `company`, `client_id` | La empresa (copiada del cliente) y el cliente. Un cliente con bitácora nunca se borra del todo. |
| `type` | El valor de `ActivityType`: llamada, nota, compromiso, descuento, correo enviado o recibido, cambio de estado o sistema. |
| `summary`, `details`, `result` | La línea que muestra la bitácora (hasta 255 caracteres), el texto largo y el resultado de una llamada (hasta 150). |
| `user_id` | El autor, o `null` si la escribió la plataforma. Si el usuario se borra del todo, queda en `null`. |
| `occurred_at` | Cuándo pasó; por defecto, al guardarla. |
| `metadata` | Los datos de `NewActivity::$metadata` en JSON (`array<string, scalar\|null>`), p. ej. `commitment_id` o `internet_message_id`. Vacío se guarda como `null`. |
| `deleted_at` | Borrado lógico. |

**Escribir** (`log()`):

- Con sesión iniciada, escribe como ese usuario y solo en clientes de su **alcance en todas sus empresas**, no solo en la activa (`Portfolio\ClientReach`): una acción que empezó antes de cambiar de empresa sigue encontrando a su cliente. Un cliente fuera de ese alcance lanza `RecordNotFound`.
- Sin sesión (jobs, colas, consola), escribe como el sistema (`Activity::$user = null`) en cualquier cliente.
- Un cliente que no existe o está eliminado (borrado lógico) lanza `RecordNotFound` en los dos casos.
- Devuelve la `Activity` guardada, con el nombre del cliente y el autor (`UserRef`).

**Última actividad.** Una gestión escrita por una persona (con autor y de un tipo distinto de «Sistema») mueve `clients.last_activity_at` a su `occurred_at` solo si es posterior: una gestión con fecha atrasada no la devuelve. Las de la plataforma no la mueven, porque `ClientSummary::$lastActivityAt` es la última gestión que no es del sistema: un correo automático o un cambio de estado automático no esconden a un cliente que nadie ha gestionado (filtro `inactiveSince`, «vencidos sin gestión»). Al eliminar una gestión, la última actividad vuelve a la gestión más reciente que queda, o a `null` si no queda ninguna (`Activity\LastActivity`).

**Leer.** Las dos listas van de la más reciente a la más antigua (`occurred_at` y luego id), con el cliente y el autor de toda la lista leídos en una consulta cada uno, y nunca muestran gestiones eliminadas ni clientes eliminados. Un límite menor que 1 lanza `InvalidArgumentException`.

| Método | Clientes |
|---|---|
| `forClient($clientId, $limit = 50)` | El cliente, si está en el alcance del usuario en cualquiera de sus empresas (igual que `log()`); si no, `[]`. |
| `recent($limit = 20)` | Los del alcance en la empresa activa o la vista de grupo (`CurrentCompany::scope()`), como `CarteraRepository`. |

Sin sesión iniciada las dos devuelven `[]` (§6.2).

**Cambios de estado automáticos.** Cuando `RecomputeClientStatuses` cambia el estado de un cliente que ya existía (ver «Importación del robot»), además de la auditoría agrega una gestión del sistema de tipo «Cambio de estado»: «Estado automático: de «Vencida» a «Por vencer»», con `metadata` `from`, `to` (valores de `ClientStatus`) y `load_id`, más `source` (`parameters`) cuando lo pide la pantalla Parámetros. Se insertan de a 500 por consulta, sin una consulta por cliente.

**Eliminar una gestión.** El contrato solo agrega y lee; el borrado lógico es de Core:

| Ruta | Nombre | Permiso |
|---|---|---|
| `DELETE /gestiones/{activity}` | `core.gestiones.destroy` | `core.activities.delete` (`gerente_cartera` y `analista_cartera`) |

- Los demás roles reciben 403 y los invitados van a `/login`.
- `{activity}` solo encuentra gestiones no eliminadas. Una gestión de un cliente eliminado, o fuera del alcance del usuario en sus empresas, da 404.
- `Application\Activity\DeleteActivity` hace el borrado lógico, recalcula la última actividad y audita `core.activity.deleted`: sujeto `activity`, `changes` con `type`, `summary` y `client` (el nombre) y `context` con `client_id`.
- Vuelve a la página anterior con el flash `status`: «Eliminamos la gestión «Llamada a tesorería».».

## Cambio de estado manual

`Contracts\ClientStatusService` es un contrato nuevo de Core, agregado después de la Fase 0a para el Kanban (arrastrar una tarjeta a otra columna) y el cambio de estado de la ficha del cliente (Parte B, módulo 2, puntos 3 y 5). Los contratos existentes no cambian. Lo implementa `Infrastructure\Portfolio\EloquentClientStatusService`, registrado en el contenedor (una instancia por petición o por job). `tests/Fakes` todavía no tiene un fake suyo.

`change($clientId, $status, $note = null): ClientSummary` actúa como el usuario con sesión iniciada:

1. Sin el permiso `core.clients.change_status` (`gerente_cartera` y `analista_cartera`) lanza `AuthorizationException`, también para invitados. Se revisa primero, así que la respuesta es la misma para cualquier id.
2. Un cliente que no existe, está eliminado o está fuera del alcance del usuario en sus empresas (`Portfolio\ClientReach`, como en la bitácora) lanza `RecordNotFound`.
3. Si el estado es el mismo, no escribe nada y devuelve el cliente.
4. Si no, en una sola transacción y con la fila del cliente bloqueada: guarda `status` y `status_changed_at`; escribe en la bitácora, como el usuario, una gestión «Cambio de estado» («De «Vencida» a «Jurídico»», la nota sin espacios sobrantes como detalle, o ninguno si viene en blanco, y `metadata` `from` y `to`), que mueve la última actividad del cliente; y audita `core.client.status_changed` con `changes.status` (`old` y `new`) y `context` `{"source": "manual"}`.
5. Devuelve el `ClientSummary` ya cambiado.

Se puede elegir cualquier estado, y las reglas de «Estados del cliente» le siguen aplicando en la siguiente instantánea: un estado automático elegido a mano (Al día, Por vencer, Vencida, Pago recibido) se recalcula con ella; uno manual (En gestión, Compromiso, Incumplido, Jurídico) sobrevive mientras el cliente deba y pasa a «Pago recibido» cuando ya no debe.

## Borrado lógico de clientes

Core da las rutas; las pantallas de Cartera las llaman (el botón vive en sus vistas):

| Ruta | Nombre | Hace |
|---|---|---|
| `DELETE /clientes/{client}` | `core.clientes.destroy` | Borrado lógico. |
| `POST /clientes/{client}/restaurar` | `core.clientes.restore` | Restaura a un cliente eliminado. |

- Las dos piden el permiso `core.clients.delete`, que por ahora solo tiene `gerente_cartera` (ver «Pendientes»). Los demás roles reciben 403 y los invitados van a `/login`.
- `{client}` solo encuentra clientes no eliminados, salvo al restaurar. Un cliente fuera del alcance del usuario en sus empresas da 404; al restaurar, el alcance se evalúa sin tener en cuenta el borrado.
- `Application\Portfolio\ClientDeletion` borra o restaura y audita `core.client.deleted` o `core.client.restored`: sujeto `client` y `changes` con `code`, `name` y `company`.
- Vuelven a la página anterior con el flash `status`: «Eliminamos el cliente Agro Arango.», «Restauramos el cliente Agro Arango.» o, si no estaba eliminado, «El cliente Agro Arango no estaba eliminado.» (sin auditoría).
- Un cliente eliminado conserva sus instantáneas, su bitácora y su código (ver «Entidades de cartera»). Desaparece de `CarteraRepository`, de la bitácora y del cambio de estado, y vuelve igual al restaurarlo. Las cargas del robot lo siguen actualizando, pero nunca lo restauran.

## Ingreso con Microsoft

Solo se entra con Microsoft Entra ID, en el directorio de la empresa. No hay contraseñas propias.

| Ruta | Nombre | Quién | Hace |
|---|---|---|---|
| `GET /login` | `core.login` | Invitados | Página «Ingresa al gestor de cartera» con el botón «Ingresar con Microsoft» (`<x-layouts.guest>`). Muestra los mensajes flash `status` y `error`. |
| `GET /auth/microsoft` | `core.auth.redirect` | Invitados | Manda a la página de ingreso de Microsoft del tenant configurado. |
| `GET /auth/microsoft/callback` | `core.auth.callback` | Invitados | Recibe la respuesta de Microsoft. Debe coincidir con `MICROSOFT_REDIRECT_URI`. |
| `POST /logout` | `core.logout` | Con sesión | Cierra la sesión de la app (no la de Microsoft), la invalida, genera un token CSRF nuevo y vuelve a `/login` con «Cerraste sesión.». El layout y la pantalla sin acceso muestran el botón «Salir». |

Un usuario con sesión que abre una ruta de invitados vuelve a `/`.

**Límite de intentos** (auditoría S-09). Las tres rutas de invitados pasan por el limitador `core-auth`: 20 peticiones por minuto por IP y por ruta. La petición 21 responde 429 con la página de error de la base. Va por ruta porque un ingreso normal usa las tres, y detrás de un proxy todos los usuarios podrían compartir la misma IP.

**Cómo se lee la respuesta.** `Application\Auth\IdentityProvider` es el puerto; `Infrastructure\Auth\SocialiteMicrosoftIdentityProvider` lo implementa con el driver `microsoft` de Socialite, que revisa el `state`, cambia el código por los tokens, valida el id token con las llaves del tenant y lee el perfil de Graph (`/me`, permisos `openid profile User.Read`). De ahí sale `MicrosoftIdentity`: el object id (`id`, que se guarda en `users.microsoft_id`), el tenant (el claim `tid`), el correo (`mail`, o `userPrincipalName` si la cuenta no tiene buzón; sin espacios y en minúsculas) y el nombre (`displayName`, o el correo si no viene). Las pruebas cambian el puerto por `Tests\Modules\Core\Support\FakeIdentityProvider` y nunca llaman a Microsoft.

**Emparejamiento** (`Application\Auth\SignInWithMicrosoft`). Los pasos 2 a 4 van en una sola transacción.

1. **Tenant.** El claim `tid` debe ser igual a `MICROSOFT_TENANT_ID` (sin distinguir mayúsculas, porque es un GUID). Si falta el claim o no hay tenant configurado, nadie entra.
2. **Usuario por correo.** Se busca en `users`, también entre los borrados. Si no existe, se crea sin rol con `User::withTrashed()->restoreOrCreate()`, con el nombre y el object id de Microsoft. Si existe, se le guarda el object id la primera vez y se actualiza el nombre si cambió en Microsoft.
3. **Asesor por correo.** Se vinculan los asesores activos, sin usuario, cuyo `email` es el del usuario. Un asesor inactivo o ya vinculado a otra persona no cambia.
4. **Rol por asesor.** Si el usuario no tiene rol y **este ingreso** le vinculó al menos un asesor, recibe el rol `asesor` y las empresas de esos asesores en `company_user`, sin duplicar. Los asesores vinculados antes no devuelven un rol: si TI le quitó todos los roles para revocar el acceso, sigue sin rol. Un usuario que ya tiene rol nunca cambia de rol ni de empresas. Los demás roles los asigna TI desde la pantalla de equipo.

Un usuario sin rol termina en `/sin-acceso`. El que tiene rol llega a la página que pidió antes de ingresar o a `/`.

> **Desvío de `docs/arquitectura.md` §12.2.** Allí el primer ingreso usa `restoreOrCreate()`, que restauraría a un usuario borrado. El ingreso no restaura: un usuario borrado queda afuera hasta que un administrador lo restaure desde la pantalla de equipo, porque borrarlo fue una decisión de TI. `restoreOrCreate()` sigue siendo el único camino para crear usuarios; aquí solo llega cuando el correo no existe, ni siquiera borrado.

**Rechazos.** Vuelven a `/login` con el flash `error`, sin sesión y sin más cambios en la base que la entrada de auditoría (la transacción se deshace antes de escribirla):

| Motivo (`RejectionReason`) | Cuándo | Mensaje |
|---|---|---|
| `tenant` | Falta el claim `tid`, no es el tenant configurado o no hay tenant configurado. | «Esa cuenta de Microsoft no es del Grupo RYR. Ingresa con la cuenta de tu empresa.» |
| `deleted` | El usuario del correo está borrado (borrado lógico). | «Tu cuenta fue retirada del gestor de cartera. Habla con TI si necesitas volver a entrar.» |
| `identity_mismatch` | El correo tiene guardado otro object id, o el object id ya pertenece a otro correo (p. ej. le cambiaron el correo en Microsoft). | «Tu cuenta de Microsoft no coincide con la registrada para tu correo. Habla con TI para revisarlo.» |
| `inactive` | El usuario está desactivado. | «Tu cuenta está desactivada. Habla con TI si crees que es un error.» |

Si falla Microsoft (`state` inválido, una petición rechazada, un id token inválido o un perfil sin object id o sin correo), queda `core.auth.failed` en la auditoría y el usuario vuelve a `/login` con «No pudimos completar el ingreso con Microsoft. Inténtalo de nuevo.». La excepción también va al log, salvo un `state` inválido: es un callback vencido o repetido, que cualquiera puede enviar, y su traza llenaría el log. La entrada de auditoría sí se escribe siempre, así que callbacks falsos desde muchas IP hacen crecer `audit_logs`. El límite de 20 por minuto por IP acota ese crecimiento; la retención de la auditoría sigue pendiente (auditoría S-29).

**Auditoría.** Nunca se guardan tokens.

| Acción | Cuándo | Datos |
|---|---|---|
| `core.auth.rejected` | Cada rechazo. | `context`: `reason` y `email`. |
| `core.auth.failed` | Falló Microsoft (sin sesión: el actor es nulo, pero quedan la IP y el navegador). | `context.error`: la clase de la excepción, p. ej. `InvalidStateException`. Nunca el mensaje, que puede repetir el código o el `state`. |
| `core.user.created` | Primer ingreso de un correo nuevo. | `changes`: nombre, correo y object id; `context.source = microsoft`. |
| `core.user.updated` | Se guardó el object id o cambió el nombre. | `changes` con `old` y `new`; `context.source = microsoft`. |
| `core.advisor.linked` | Por cada asesor vinculado. | `changes.user_id`; `context`: `company` y `code`. |
| `core.user.role_assigned` | El usuario recibió el rol `asesor`. | `changes`: `role` y `companies`; `context.source = microsoft`. |
| `core.auth.signed_in` | Ingresó (ya con sesión, así que el actor es el usuario). | — |
| `core.auth.signed_out` | Cerró sesión. | — |

**Variables de `.env`** (`config('services.microsoft')`):

| Variable | Valor |
|---|---|
| `MICROSOFT_TENANT_ID` | El id del directorio (GUID) de Entra ID, no un dominio: se compara con el claim `tid`. Nunca `common`. Sin ella, nadie ingresa. |
| `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET` | El registro de la app en Entra ID. El secreto vive solo en `.env`. |
| `MICROSOFT_REDIRECT_URI` | La URL completa del callback: `https://<dominio>/auth/microsoft/callback`, igual a la registrada en Entra ID. |

Después de editar `.env` en producción, corre `php artisan config:cache`.

## Inicio y acceso

**Inicio según el rol.** `GET /` (`core.inicio`, solo con sesión iniciada) no tiene pantalla propia: Core no conoce las rutas de los demás módulos.

| Usuario | Llega a |
|---|---|
| Sin `core.access` (sin rol) | `/sin-acceso`. |
| Con acceso | La URL de la primera entrada del menú que puede ver: `NavRegistry::visibleTo()`, por `order` y luego etiqueta, sin las entradas cuya ruta no existe o cuyo permiso le niega el Gate. Se salta una entrada que apunte a `core.inicio`, para no dar vueltas. |
| Con acceso, sin ninguna entrada visible | Una página de bienvenida (`core::inicio`) que lo saluda por su nombre y le explica que sus pantallas aparecerán a medida que se habiliten los módulos. |

> **Para los módulos:** ordena tu pantalla principal primero (`order`) para los roles que la usan. Por ejemplo, el tablero gerencial con `order: 10` y el permiso que solo tiene la gerencia, y el tablero de cartera con `order: 20`: la gerencia llega al primero y los asesores, al segundo.

**Sin acceso.** `GET /sin-acceso` (`core.sin-acceso`, solo con sesión iniciada) es una pantalla neutra (`<x-layouts.guest>`, sin marca ni menú): «Todavía no tienes acceso». Le dice al usuario que su cuenta de Microsoft ya está registrada pero no tiene rol, que le pida uno a TI y con qué correo ingresó, y le ofrece el botón «Salir» (`POST /logout`). Un usuario que sí tiene acceso y abre `/sin-acceso` vuelve a `/`.

**Cuentas desactivadas.** `Http\Middleware\EnsureUserIsActive` corre en todas las páginas del grupo `web`, tengan o no el middleware `auth` (Core lo agrega al grupo desde `bootModule()`, a través del kernel HTTP). Si el usuario con sesión tiene `is_active` en falso:

1. Cierra su sesión, la invalida y genera un token CSRF nuevo.
2. Lo manda a `/login` con el flash `error` «Tu cuenta está desactivada. Habla con TI si crees que es un error.». Si la petición espera JSON, responde 401 con ese mensaje en `message`.

Los invitados y los usuarios activos pasan sin cambios. Un usuario cargado sin la columna `is_active` cuenta como activo (el valor por defecto de la columna), en vez de fallar con los modelos estrictos. Un usuario borrado (borrado lógico) ni siquiera llega al middleware: la sesión no lo encuentra y queda como invitado.

## Bitácora de auditoría

`Infrastructure\Audit\DatabaseAuditLogger` implementa `AuditLogger` y Core la registra en el contenedor (una instancia por petición o por job). Cada llamada a `record()` agrega una fila a `audit_logs`:

| Columna | Contenido |
|---|---|
| `action` | La acción, con el formato `<módulo>.<entidad>.<verbo>`: tres segmentos o más en minúsculas y `snake_case`, p. ej. `cobro.exclusion.created`. Otro formato lanza `InvalidArgumentException` y no guarda nada. |
| `subject_type`, `subject_id` | El registro afectado, p. ej. `client` y `C-0042`. El id se guarda como texto, sea número o cadena. |
| `changes`, `context` | Los cambios (`campo => ['old' => …, 'new' => …]`) y los datos extra, en JSON. Si vienen vacíos, quedan en `null`. Los valores deben poder convertirse a JSON. |
| `user_id`, `actor` | El usuario con sesión iniciada y su correo en ese momento. Las acciones del sistema (jobs, tareas programadas, consola) no tienen usuario: ambos quedan en `null`. |
| `ip_address`, `user_agent` | La IP y el navegador de la petición HTTP, si la hay; el navegador se corta a 255 caracteres. Fuera de una petición quedan en `null`. |
| `created_at` | La hora de la acción. No hay `updated_at`. |

**Inmutable.** `AuditLogRecord` lanza `LogicException` si intentas guardar cambios en una fila existente o borrarla, también con `saveQuietly()` y `deleteQuietly()`. Las escrituras del constructor de consultas no pasan por el modelo: nunca uses `update()` ni `delete()` masivos sobre `AuditLogRecord::query()` o `DB::table('audit_logs')`, ni `increment()` o `decrement()`.

**Red de seguridad.** El contrato prohíbe guardar secretos. Aun así, en `changes` y `context`, a cualquier profundidad, el valor de toda llave que contenga `password`, `secret` o `token` (sin importar mayúsculas) se guarda como `[redacted]`.

## Pantalla Equipo

Solo para administradores: el permiso `core.team.manage`, que `CorePermissionSeeder` da únicamente a `admin`. Aparece en el menú como «Equipo», en el grupo «Administración» (`order: 900`); como es la primera entrada que ve un administrador (antes de «Parámetros»), también es su inicio. Los demás roles reciben 403 en todas sus rutas y los invitados van a `/login`.

| Ruta | Nombre | Hace |
|---|---|---|
| `GET /equipo` | `core.equipo.index` | La lista, por pestañas, con búsqueda, orden y paginación. |
| `GET /equipo/nuevo`, `POST /equipo` | `core.equipo.create`, `core.equipo.store` | Registra a una persona antes de su primer ingreso. |
| `GET /equipo/{user}/editar`, `PUT /equipo/{user}` | `core.equipo.edit`, `core.equipo.update` | Edita el nombre, el rol, las empresas, los asesores de SAP, el equipo y si la cuenta está activa. |
| `DELETE /equipo/{user}` | `core.equipo.destroy` | Borrado lógico, confirmado en un modal de la página de edición. |
| `POST /equipo/{user}/restaurar` | `core.equipo.restore` | Restaura a un usuario eliminado. |

`{user}` solo encuentra usuarios no eliminados (un eliminado da 404), salvo al restaurar.

**Lista.** Cada pestaña es un enlace (`?estado=`):

| Pestaña | Usuarios |
|---|---|
| Activos (por defecto) | Activos y con rol. |
| Sin rol | Activos sin rol: quienes ingresaron con Microsoft y esperan acceso. |
| Inactivos | Desactivados, con o sin rol. |
| Eliminados | Con borrado lógico. Cada fila tiene el botón «Restaurar». |

- Cada pestaña muestra cuántos usuarios tiene con la búsqueda actual.
- `?buscar=` busca por parte del nombre o del correo dentro de la pestaña.
- La tabla se ordena por nombre o por correo (`?sort=name|email&direction=asc|desc`); un valor desconocido vuelve al nombre ascendente. Muestra 25 por página, y los enlaces de pestañas y páginas conservan la búsqueda y el orden.
- Columnas: nombre, correo, rol («Sin rol» si no tiene), empresas (chips; «Todas» para un administrador), asesor SAP (un chip por asesor vinculado, p. ej. «SGX 12 · Carlos Pérez», por empresa y luego por nombre, leídos en una sola consulta para toda la página), estado (Activo, Inactivo o Eliminado) y la acción.

**Qué se edita.** El nombre, un solo rol o ninguno (`syncRoles()` de Spatie), las empresas (`company_user`), los asesores de SAP vinculados (`advisors.user_id`), el equipo del gerente comercial (`advisor_team`) y «Cuenta activa». El correo no se edita: es la identidad con la que la persona ingresa con Microsoft.

- Todo rol salvo `admin` exige al menos una empresa («Elige al menos una empresa para este rol.»).
- Las empresas de un administrador se ignoran y se guardan vacías: ve las dos (`CompanyAccess`).
- Una cuenta desactivada sale de la plataforma en su siguiente página (ver «Cuentas desactivadas»).
- «Asesor de SAP vinculado» (`asesores[]`) y «Equipo del gerente comercial» (`equipo[]`) listan todos los asesores, agrupados por empresa (en el orden de `CompanyCode`) y por nombre: «<código> · <nombre>», con el correo de SAP como ayuda si lo tiene y «(inactivo)» si está inactivo en SAP. Las reglas del vínculo y del equipo están en «Asesores de SAP».
- Un id de asesor que no existe falla la validación («El valor elegido en asesor no es válido.»).

**Registro previo y restauración.** «Nuevo usuario» pide nombre, correo, rol, empresas, asesores de SAP y equipo; los asesores vinculados a otra persona también aparecen deshabilitados. Crea la cuenta activa y sin `microsoft_id`; el primer ingreso con Microsoft la vincula por el correo, que se guarda sin espacios y en minúsculas.

- Si el correo es de un usuario no eliminado, el formulario falla en «correo»: «Ya existe un usuario con ese correo.».
- Si es de un usuario eliminado, restaura esa misma fila (`restoreOrCreate()`, ver `docs/arquitectura.md` §12.2) y le aplica el formulario, incluida la activación.

**Eliminar y restaurar.** Eliminar es un borrado lógico: la fila, su rol y sus empresas se conservan para la bitácora. Desde ese momento la sesión no encuentra al usuario (queda como invitado en su siguiente página) y el ingreso con Microsoft lo rechaza. «Restaurar» lo devuelve como estaba, con su rol, sus empresas y su activación.

**Protección contra el bloqueo.** Un administrador no puede desactivar su propia cuenta, quitarse el rol `admin` ni eliminarse: la pantalla vuelve con el flash `error` y no guarda nada. Su propia página de edición no muestra «Eliminar usuario».

**Bitácora.** `Application\Team\TeamMembers` hace las escrituras, la bitácora y las protecciones; los controladores solo la llaman. Cada entrada lleva el contexto `{"source": "equipo"}` y al administrador como actor:

| Acción | Cuándo | `changes` |
|---|---|---|
| `core.user.created` | Registro de una persona nueva. | `name`, `email`, `role` (valor o `null`), `companies` (códigos ordenados) y `team` (asesores del equipo como `<EMPRESA>-<código>`, ordenados; vacío salvo para un gerente comercial). |
| `core.user.updated` | Edición, o restauración desde «Nuevo usuario». | Solo los campos que cambian (`name`, `role`, `companies`, `active`, `team`), como `campo => ['old' => …, 'new' => …]`, en una sola entrada por guardado. Sin cambios, no hay entrada. |
| `core.user.deleted` | Borrado lógico. | `name` y `email`. |
| `core.user.restored` | Restauración, con el botón o desde «Nuevo usuario». | `name` y `email`. |
| `core.advisor.linked`, `core.advisor.unlinked` | Por cada asesor que el formulario vincula o desvincula (sujeto `advisor`). | `user_id` con `old` y `new`; el `context` agrega `company` y `code`. |

## Pendientes

- **Parte D, punto 11:** María Paula debe confirmar que los 15 días de SGX se cuentan después del vencimiento de la factura y no desde su emisión. Las reglas ya los cuentan después del vencimiento; si la respuesta cambia, se ajusta `ClientStatusRules::overdueForStatus()` y sus pruebas.
- **Borrado de clientes:** Miguel debe confirmar que solo `gerente_cartera` elimina y restaura clientes (`core.clients.delete`). Si la analista también debe hacerlo, basta con agregar `Role::PortfolioAnalyst` a ese permiso en `CorePermissionSeeder` y ajustar sus pruebas.
