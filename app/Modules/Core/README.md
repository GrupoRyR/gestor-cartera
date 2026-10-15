# Módulo Core (núcleo)

Core es la base de la plataforma: los contratos que usan los demás módulos (`Contracts`, congelados), las entidades compartidas y las reglas de negocio comunes. Ningún otro módulo decide el estado de un cliente ni el ciclo de un compromiso: lo hace Core.

## Capas

| Carpeta | Contenido |
|---|---|
| `Contracts/` | Interfaces, objetos de datos, enums y excepciones públicos (ver `docs/arquitectura.md` §6). No cambian sin acordarlo. |
| `Domain/` | Reglas puras en PHP: sin Laravel, sin base de datos y sin reloj. Reciben fechas, saldos y parámetros ya resueltos. |
| `Application/` | Casos de uso (tareas siguientes). |
| `Infrastructure/` | Eloquent (`Persistence\*Record`), el acceso por empresa (`Access\CompanyAccess`), el catálogo de empresas y la empresa activa (`Companies\`). |
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

## Pendientes

- **Parte D, punto 11:** María Paula debe confirmar que los 15 días de SGX se cuentan después del vencimiento de la factura y no desde su emisión. Las reglas ya los cuentan después del vencimiento; si la respuesta cambia, se ajusta `ClientStatusRules::overdueForStatus()` y sus pruebas.
