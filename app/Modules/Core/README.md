# Módulo Core (núcleo)

Core es la base de la plataforma: los contratos que usan los demás módulos (`Contracts`, congelados), las entidades compartidas y las reglas de negocio comunes. Ningún otro módulo decide el estado de un cliente ni el ciclo de un compromiso: lo hace Core.

## Capas

| Carpeta | Contenido |
|---|---|
| `Contracts/` | Interfaces, objetos de datos, enums y excepciones públicos (ver `docs/arquitectura.md` §6). No cambian sin acordarlo. |
| `Domain/` | Reglas puras en PHP: sin Laravel, sin base de datos y sin reloj. Reciben fechas, saldos y parámetros ya resueltos. |
| `Application/` | Casos de uso: el ingreso con Microsoft (`Auth\`, ver «Ingreso con Microsoft»), la pantalla Equipo (`Team\`) y el recálculo de saldos y estados de los clientes (`Portfolio\`, ver «Importación del robot»). |
| `Infrastructure/` | Eloquent (`Persistence\*Record`), el acceso por empresa y el alcance de la cartera (`Access\`), el catálogo de empresas y la empresa activa (`Companies\`), la bitácora de auditoría (`Audit\`), el adaptador de Microsoft sobre Socialite (`Auth\`), la importación de los lotes del robot (`Ingest\`) y la lectura de la cartera (`Portfolio\`, ver «CarteraRepository»). |
| `Http/` | Controladores, FormRequests y middleware de las rutas web (ver «Ingreso con Microsoft» e «Inicio y acceso»). |
| `config/core.php` | Valores por defecto para sembrar los parámetros de cada empresa. |

## Estados del cliente

`Domain\Status\ClientStatusRules::resolve()` calcula el estado con las partidas abiertas de la última instantánea (`OpenItem`: saldo y días de vencida) y los parámetros de la empresa (`StatusParameters`).

| Tipo | Estados | Quién los cambia |
|---|---|---|
| Automático | Al día, Por vencer, Vencida, Pago recibido | Se recalculan con cada instantánea. |
| Manual | En gestión, Compromiso, Incumplido, Jurídico | Los usuarios o el flujo de compromisos. Sobreviven a nuevas instantáneas mientras el cliente deba. |

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

Los valores de la tabla son los de siembra (`config('core.companies')`). Los vigentes viven en la tabla `companies` (ver «Empresas y parámetros»). Las edades de la deuda (`AgingBuckets`) ignoran los días de gracia.

## Compromisos de pago

`Domain\Commitments\CommitmentRules` decide cuándo se incumple un compromiso y qué le pasa al estado del cliente. Las reglas devuelven un `StatusDecision`: conservar el estado (`keep`), cambiarlo (`set`, solo cuando el estado de verdad cambia) o recalcular el estado automático (`recompute`).

| Momento | Efecto en el cliente |
|---|---|
| Se crea | Pasa a «Compromiso», salvo si está en «Jurídico» o ya en «Compromiso». |
| Se incumple | Pasa a «Incumplido», salvo si tiene otro compromiso pendiente o está en «Jurídico», «Pago recibido» o ya en «Incumplido». |
| Se cumple o se anula | Si estaba en «Compromiso» o «Incumplido» y no tiene otro compromiso pendiente, se recalcula su estado automático con la última instantánea, ignorando el estado manual. Si no, conserva su estado. |

**Incumplimiento diario.** Una tarea diaria marca «Incumplido» todo compromiso «Pendiente» cuya fecha de pago sea anterior a hoy (`CommitmentRules::isBroken()`). Un compromiso que vence hoy no se incumple hasta mañana. Solo cuenta la fecha, no la hora.

## Empresas y parámetros

La tabla `companies` (llave `code`: `SGX` o `SDC`) guarda el nombre y los parámetros de cada empresa: `grace_days` y `due_soon_days`. La migración `2026_10_01_000001` crea las dos filas con el nombre de `CompanyCode::label()` y los valores de `config('core.companies')`; desde ahí, los vigentes son los de la tabla.

`Infrastructure\Companies\CompanyCatalog` lee la tabla una vez por petición (o por job) y entrega:

| Método | Devuelve |
|---|---|
| `all()` | Las empresas (`Contracts\Data\Company`) en el orden de `CompanyCode`. |
| `get($code)` | Una empresa con su nombre, días de gracia y paleta oficial. |
| `parameters($code)` | Los `StatusParameters` de la empresa para `ClientStatusRules`. |
| `refresh()` | Olvida lo leído; úsalo después de que un administrador edite una empresa. |

Si falta la fila de una empresa, usa los valores de `config('core.companies')` y la etiqueta del enum.

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

**Selector.** `POST /empresa` (`core.empresa.cambiar`, solo con sesión iniciada) recibe `empresa` = `SGX`, `SDC` o `grupo`. Si funciona, vuelve atrás con el flash `status` («Ahora trabajas en …»); si la empresa no está disponible, con el flash `error`. Un valor distinto falla la validación del campo «empresa». El layout `<x-layouts.app>` muestra el selector solo cuando el usuario tiene más de una empresa.

## Roles y permisos

Los roles son los de `Contracts\Enums\Role` (Spatie, guard `web`, sin equipos). `CorePermissionSeeder` siembra:

| Permiso | Roles | Qué permite |
|---|---|---|
| `core.access` | Los seis | Usar la plataforma. Un usuario sin rol no lo tiene. |
| `core.team.manage` | `admin` | Usar la pantalla Equipo (ver «Pantalla Equipo»). |

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
- **Clientes con borrado lógico.** Un cliente eliminado conserva su fila, su histórico y su código: sigue ocupando el índice único (`company`, `code`). La base impide borrar del todo un cliente con partidas; si se borra del todo uno sin partidas, sus contactos se borran con él.
- Si el gestor responsable se borra del todo (no el borrado lógico), el cliente queda sin gestor.
- Los montos son `decimal(18,2)` y los modelos los entregan como cadenas exactas (cast `decimal:2`), p. ej. `"1234567.89"`. `company` es el valor de `CompanyCode`, `status` el de `ClientStatus` y `source` el de `LoadSource`.
- Los días de vencida van del vencimiento al corte, igual que `Invoice::$daysOverdue`: positivo = vencida, 0 = vence en el corte, negativo = aún no vence.

**Factories.** `ClientRecordFactory` crea clientes ficticios de Segurex, al día y sin saldo, con un NIT válido (dígito de verificación de la DIAN, `ClientRecordFactory::checkDigit()`) y el código `C<NIT>`. `InvoiceRecordFactory` crea una partida que vence hoy, con su propia instantánea y su cliente salvo que la prueba los pase con `for()`. `PortfolioLoadRecordFactory` y `ContactRecordFactory` (estado `primary()`) completan el juego.

## Importación del robot

Ingesta guarda cada lote del robot (o de una carga manual) y despacha `BatchAccepted` una vez por lote aceptado (`docs/arquitectura.md` §6.9). Core lo escucha con dos listeners, registrados en `bootModule()`. Los dos van a la cola y solo se encolan para su lote (`shouldQueue()`): los demás lotes no crean trabajos.

| Lote | Listener (`Infrastructure\Ingest\`) | Qué hace |
|---|---|---|
| `cartera` | `ImportReceivablesSnapshot` | Guarda la instantánea con sus partidas, crea o actualiza los clientes y recalcula el saldo y el estado de todos los clientes de la empresa. |
| `asesores` | `ImportAdvisors` | Actualiza los asesores de SAP de la empresa y desactiva los que no llegan. |
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

Solo se escriben los clientes cuyos valores cambian. Cuando cambia el estado de un cliente que ya existía, se guarda `status_changed_at` y se audita `core.client.status_changed`: sujeto `client`, `changes.status` con `old` y `new`, y `context` con `load_id` y `cutoff_date` de la instantánea. No tiene usuario porque lo hace el sistema. Un cliente que crea la importación recibe su primer estado (y `status_changed_at` si no queda «Al día») sin entrada de auditoría: es su estado inicial, no un cambio; así la primera carga del robot no escribe una entrada por cliente (`forCompany()` recibe esos clientes en `$newClientIds`). La entrada en la bitácora de gestiones (`GestionLogger`) llega en un PR posterior. La tarea diaria de compromisos y la pantalla de parámetros reutilizarán este servicio.

**Asesores.** Por cada fila, por empresa y `slp_code`:

- `name` = `nombre`, `email` = `email` sin espacios y en minúsculas (vacío = `null`) y `active` = `activo` es `Y` (sin importar mayúsculas ni espacios).
- Los asesores de esa empresa que no llegan en el lote quedan inactivos; los de la otra empresa no cambian.
- Nunca toca `user_id`: el vínculo lo hacen el ingreso con Microsoft y la pantalla Equipo. Si alguien ingresó antes de que llegara su asesor, queda vinculado en su siguiente ingreso.
- Repetir el lote deja lo mismo.

**Cola en el hosting compartido.** Los listeners van a la cola `database`, que vacía la tarea de cada minuto (`docs/arquitectura.md` §10). La cartera se escribe por lotes (clientes y partidas de a 500, y el recálculo lee las partidas en una sola consulta), así que una instantánea de miles de filas tarda segundos. `ImportReceivablesSnapshot` tiene `$timeout = 300` y un solo intento (`$tries = 1`): si falla, la transacción se deshace y el trabajo queda en `failed_jobs`; `php artisan queue:retry` lo vuelve a correr sin duplicar nada. En pruebas la cola es `sync`: los listeners corren en el momento.

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

`totals()` y `history()` llegan en el siguiente cambio; mientras tanto lanzan `LogicException`.

## Ingreso con Microsoft

Solo se entra con Microsoft Entra ID, en el directorio de la empresa. No hay contraseñas propias.

| Ruta | Nombre | Quién | Hace |
|---|---|---|---|
| `GET /login` | `core.login` | Invitados | Página «Ingresa al gestor de cartera» con el botón «Ingresar con Microsoft» (`<x-layouts.guest>`). Muestra los mensajes flash `status` y `error`. |
| `GET /auth/microsoft` | `core.auth.redirect` | Invitados | Manda a la página de ingreso de Microsoft del tenant configurado. |
| `GET /auth/microsoft/callback` | `core.auth.callback` | Invitados | Recibe la respuesta de Microsoft. Debe coincidir con `MICROSOFT_REDIRECT_URI`. |
| `POST /logout` | `core.logout` | Con sesión | Cierra la sesión de la app (no la de Microsoft), la invalida, genera un token CSRF nuevo y vuelve a `/login` con «Cerraste sesión.». El layout y la pantalla sin acceso muestran el botón «Salir». |

Un usuario con sesión que abre una ruta de invitados vuelve a `/`.

**Cómo se lee la respuesta.** `Application\Auth\IdentityProvider` es el puerto; `Infrastructure\Auth\SocialiteMicrosoftIdentityProvider` lo implementa con el driver `microsoft` de Socialite, que revisa el `state`, cambia el código por los tokens, valida el id token con las llaves del tenant y lee el perfil de Graph (`/me`, permisos `openid profile User.Read`). De ahí sale `MicrosoftIdentity`: el object id (`id`, que se guarda en `users.microsoft_id`), el tenant (el claim `tid`), el correo (`mail`, o `userPrincipalName` si la cuenta no tiene buzón; sin espacios y en minúsculas) y el nombre (`displayName`, o el correo si no viene). Las pruebas cambian el puerto por `Tests\Modules\Core\Support\FakeIdentityProvider` y nunca llaman a Microsoft.

**Emparejamiento** (`Application\Auth\SignInWithMicrosoft`). Los pasos 2 a 4 van en una sola transacción.

1. **Tenant.** El claim `tid` debe ser igual a `MICROSOFT_TENANT_ID` (sin distinguir mayúsculas, porque es un GUID). Si falta el claim o no hay tenant configurado, nadie entra.
2. **Usuario por correo.** Se busca en `users`, también entre los borrados. Si no existe, se crea sin rol con `User::withTrashed()->restoreOrCreate()`, con el nombre y el object id de Microsoft. Si existe, se le guarda el object id la primera vez y se actualiza el nombre si cambió en Microsoft.
3. **Asesor por correo.** Se vinculan los asesores activos, sin usuario, cuyo `email` es el del usuario. Un asesor inactivo o ya vinculado a otra persona no cambia.
4. **Rol por asesor.** Si el usuario no tiene rol y tiene al menos un asesor activo vinculado (de este ingreso o de antes), recibe el rol `asesor` y las empresas de esos asesores en `company_user`, sin duplicar. Un usuario que ya tiene rol nunca cambia de rol ni de empresas. Los demás roles los asigna TI desde la pantalla de equipo.

Un usuario sin rol termina en `/sin-acceso`. El que tiene rol llega a la página que pidió antes de ingresar o a `/`.

> **Desvío de `docs/arquitectura.md` §12.2.** Allí el primer ingreso usa `restoreOrCreate()`, que restauraría a un usuario borrado. El ingreso no restaura: un usuario borrado queda afuera hasta que un administrador lo restaure desde la pantalla de equipo, porque borrarlo fue una decisión de TI. `restoreOrCreate()` sigue siendo el único camino para crear usuarios; aquí solo llega cuando el correo no existe, ni siquiera borrado.

**Rechazos.** Vuelven a `/login` con el flash `error`, sin sesión y sin más cambios en la base que la entrada de auditoría (la transacción se deshace antes de escribirla):

| Motivo (`RejectionReason`) | Cuándo | Mensaje |
|---|---|---|
| `tenant` | Falta el claim `tid`, no es el tenant configurado o no hay tenant configurado. | «Esa cuenta de Microsoft no es del Grupo RYR. Ingresa con la cuenta de tu empresa.» |
| `deleted` | El usuario del correo está borrado (borrado lógico). | «Tu cuenta fue retirada del gestor de cartera. Habla con TI si necesitas volver a entrar.» |
| `identity_mismatch` | El correo tiene guardado otro object id, o el object id ya pertenece a otro correo (p. ej. le cambiaron el correo en Microsoft). | «Tu cuenta de Microsoft no coincide con la registrada para tu correo. Habla con TI para revisarlo.» |
| `inactive` | El usuario está desactivado. | «Tu cuenta está desactivada. Habla con TI si crees que es un error.» |

Si falla Microsoft (`state` inválido, una petición rechazada, un id token inválido o un perfil sin object id o sin correo), la excepción se reporta al log y el usuario vuelve a `/login` con «No pudimos completar el ingreso con Microsoft. Inténtalo de nuevo.».

**Auditoría.** Nunca se guardan tokens.

| Acción | Cuándo | Datos |
|---|---|---|
| `core.auth.rejected` | Cada rechazo. | `context`: `reason` y `email`. |
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

Solo para administradores: el permiso `core.team.manage`, que `CorePermissionSeeder` da únicamente a `admin`. Aparece en el menú como «Equipo», en el grupo «Administración» (`order: 900`); como es la única entrada que ve un administrador, también es su inicio. Los demás roles reciben 403 en todas sus rutas y los invitados van a `/login`.

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
