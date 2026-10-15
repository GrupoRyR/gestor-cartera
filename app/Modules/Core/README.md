# Módulo Core (núcleo)

Core es la base de la plataforma: los contratos que usan los demás módulos (`Contracts`, congelados), las entidades compartidas y las reglas de negocio comunes. Ningún otro módulo decide el estado de un cliente ni el ciclo de un compromiso: lo hace Core.

## Capas

| Carpeta | Contenido |
|---|---|
| `Contracts/` | Interfaces, objetos de datos, enums y excepciones públicos (ver `docs/arquitectura.md` §6). No cambian sin acordarlo. |
| `Domain/` | Reglas puras en PHP: sin Laravel, sin base de datos y sin reloj. Reciben fechas, saldos y parámetros ya resueltos. |
| `Application/` | Casos de uso: el ingreso con Microsoft (`Auth\`, ver «Ingreso con Microsoft»). |
| `Infrastructure/` | Eloquent (`Persistence\*Record`), el acceso por empresa (`Access\CompanyAccess`), el catálogo de empresas y la empresa activa (`Companies\`), la bitácora de auditoría (`Audit\`) y el adaptador de Microsoft sobre Socialite (`Auth\`). |
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

Core la expone como `$user->advisors()` con `User::resolveRelationUsing()`. La carga desde SAP llega con Ingesta y la pantalla de equipo vinculará asesores a mano; hoy solo el ingreso los vincula. `AdvisorRecordFactory` crea vendedores ficticios (estados `inactive()` y `linkedTo($user)`).

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

**Lista.** Cada pestaña es un enlace (`?estado=`):

| Pestaña | Usuarios |
|---|---|
| Activos (por defecto) | Activos y con rol. |
| Sin rol | Activos sin rol: quienes ingresaron con Microsoft y esperan acceso. |
| Inactivos | Desactivados, con o sin rol. |
| Eliminados | Con borrado lógico. |

- Cada pestaña muestra cuántos usuarios tiene con la búsqueda actual.
- `?buscar=` busca por parte del nombre o del correo dentro de la pestaña.
- La tabla se ordena por nombre o por correo (`?sort=name|email&direction=asc|desc`); un valor desconocido vuelve al nombre ascendente. Muestra 25 por página, y los enlaces de pestañas y páginas conservan la búsqueda y el orden.
- Columnas: nombre, correo, rol («Sin rol» si no tiene), empresas (chips; «Todas» para un administrador) y estado (Activo, Inactivo o Eliminado).

## Pendientes

- **Parte D, punto 11:** María Paula debe confirmar que los 15 días de SGX se cuentan después del vencimiento de la factura y no desde su emisión. Las reglas ya los cuentan después del vencimiento; si la respuesta cambia, se ajusta `ClientStatusRules::overdueForStatus()` y sus pruebas.
