# Arquitectura del gestor de cartera

Referencia técnica de lo que dejó la Fase 0a: cómo se carga un módulo, qué contratos unen a los módulos, qué componentes Blade existen y con qué props, cómo se prueba y cómo se despliega. Describe el código tal como está; si algo de aquí no coincide con el código, manda el código y se corrige este documento.

El plan (alcance, módulos y sesiones) está en [`docs/prompt-gestor-cartera.md`](prompt-gestor-cartera.md); este documento no lo repite, lo enlaza por sección (A.3, C.2, C.4…).

## Ruta rápida según tu sesión

| Si eres… | Lee primero |
|---|---|
| Diseño (Fase 0b) | §1, §5, §8, §9 y §14 |
| Núcleo (Fase 0b) | §1, §3, §5, §6, §7, §11 y §12 |
| Un módulo de la Fase 1 o 2 | §1 a §4, §6, §7, §8 y §10 a §14 |
| Despliegue (Fase 3) | §10, §15 y §16 |

---

## 1. Qué está congelado

Estas piezas las usan todas las sesiones a la vez. **No se cambian dentro del PR de un módulo**: un cambio va en un PR aparte, coordinado con el usuario.

| Pieza | Dónde | Regla |
|---|---|---|
| Contratos entre módulos | `app/Modules/Core/Contracts/**` (interfaces, `Data`, `Enums`, `Events`, `Exceptions`) | Congelados (C.2). Núcleo puede **agregar** un contrato nuevo y avisarlo en su PR; nadie cambia los existentes. |
| Props de los componentes | `resources/views/components/**` (C.5 más `<x-ui.icon>` y `<x-ui.pagination>`) | Diseño cambia el marcado y el estilo, nunca las props ni los contratos de §8.2. |
| Ganchos del cargador de módulos | `app/Support/Modules/ModuleServiceProvider.php` | `register()` y `boot()` son `final`; los módulos solo usan los ganchos de §3.3. |
| Fakes de los contratos | `tests/Fakes/**` | Cambian solo junto con su contrato. |

Si necesitas algo de esta tabla, detente y pídelo al usuario (regla de `CLAUDE.md`).

---

## 2. Mapa del repositorio y dueños

```
app/
  Models/User.php            ← identidad compartida (base)
  Modules/<Modulo>/          ← un directorio por módulo (§3)
  Providers/                 ← AppServiceProvider y ModulesServiceProvider (base)
  Support/
    Formatting/              ← Money, DateFormat, FieldName (base)
    Modules/                 ← cargador de módulos y PermissionSeeder (base)
    Navigation/              ← NavItem y NavRegistry (base)
bootstrap/  config/  routes/ ← base
database/                    ← migraciones, factory y seeder de la base
lang/es/                     ← traducciones del framework (base)
resources/
  css/  js/                  ← Diseño
  views/components/          ← layouts y componentes (Diseño)
  views/design/              ← galería /dev/componentes (Diseño)
  views/errors/              ← páginas 403, 404, 419, 429, 500 y 503 (base)
tests/
  Architecture/              ← reglas de fronteras (base)
  Fakes/                     ← fakes de los contratos (base)
  Fixtures/Modules/Demo/     ← módulo de ejemplo de las pruebas (base)
  Modules/<Modulo>/          ← pruebas de cada módulo
deploy/deploy.sh             ← despliegue en cPanel (base)
etl/  scripts/               ← robot de SAP (Ingesta)
legacy/                      ← solo lectura
```

**Dueños.** Las filas de la tabla C.3 del plan dicen qué archivos tiene cada sesión. Todo lo que no aparece en ninguna fila es de la **base** (Fase 0a): `bootstrap/`, `config/`, `routes/`, `app/Providers/`, `app/Support/`, `app/Models/User.php`, `database/` (raíz), `lang/es/`, `resources/views/errors/`, `tests/TestCase.php`, `tests/Pest.php`, `tests/Architecture/`, `tests/Fakes/`, `tests/Fixtures/`, `tests/Unit/`, `tests/Feature/` (salvo `Design/`), `composer.json`, `package.json` y sus lockfiles, `phpunit.xml`, `phpstan.neon`, `pint.json`, `.github/`, `deploy/`, `.env.example`, `.gitignore` y este documento. Ninguna sesión posterior los edita sin pedirlo.

> La fila de Diseño en C.3 nombra `resources/views/layouts/**`, pero los layouts viven en `resources/views/components/layouts/**` (son componentes `<x-layouts.*>`); ya quedan cubiertos por `resources/views/components/**`.

---

## 3. Anatomía de un módulo

### 3.1 Carpetas

Los ocho módulos ya tienen su esqueleto. Cada uno sigue esta forma:

```
app/Modules/<Modulo>/
  Providers/<Modulo>ServiceProvider.php   ← obligatorio, extiende ModuleServiceProvider
  Domain/                ← entidades y reglas, sin Laravel (convención; ninguna prueba lo verifica)
  Application/           ← casos de uso
  Infrastructure/        ← Eloquent, Graph, lectores de archivos
  Http/Controllers/  Http/Requests/
  routes/web.php  routes/api.php
  database/migrations/  database/seeders/  database/factories/
  resources/views/       ← vistas "<clave>::…"
  resources/lang/es/     ← traducciones "<clave>::…"
  config/<clave>.php     ← p. ej. config/cobro.php
tests/Modules/<Modulo>/Unit/
tests/Modules/<Modulo>/Feature/
```

- El nombre del directorio es el nombre del módulo en PascalCase (`/^[A-Z][A-Za-z0-9]*$/`). La **clave** es ese nombre en minúsculas (`Cobro` → `cobro`) y nombra rutas, vistas, traducciones y configuración.
- El espacio de nombres raíz es `App\Modules\<Modulo>`. Las sesiones de la Fase 2 que viven dentro de otro módulo usan subcarpetas propias: `app/Modules/Cobro/Circularizacion/` y `app/Modules/Bancos/Conciliacion/` (C.3).

### 3.2 Qué carga la base sin que hagas nada

`App\Providers\ModulesServiceProvider` (registrado en `bootstrap/providers.php`) descubre los módulos con `App\Support\Modules\ModuleRegistry` —**Core primero**, el resto en orden alfabético— y registra `App\Modules\<Modulo>\Providers\<Modulo>ServiceProvider` de cada uno. Un proveedor con otro nombre o en otra carpeta nunca se registra.

Cada proveedor extiende `App\Support\Modules\ModuleServiceProvider`, que deduce el módulo de la carpeta donde vive el proveedor y carga, si existen:

| Qué | Cómo queda |
|---|---|
| `config/<clave>.php` | Se fusiona bajo `config('<clave>.*')` en `register()`. Solo se carga el archivo con el nombre de la clave. |
| `database/migrations/` | Lo corre `php artisan migrate` junto con las demás. |
| `resources/views/` | `view('<clave>::carpeta.vista')`; componentes anónimos en `resources/views/components/` como `<x-<clave>::nombre>`. |
| `resources/lang/` | `__('<clave>::archivo.llave')`. |
| `routes/web.php` | Middleware `web`, prefijo `/<webPrefix()>`, nombres `<clave>.*`. |
| `routes/api.php` | Middleware `api`, prefijo `/api/<apiPrefix()>`, nombres `<clave>.api.*`. |
| `schedule()` | Se llama cuando el contenedor resuelve `Schedule`. |
| `navigation()` | Se llama cuando el contenedor resuelve `NavRegistry`. |

Las rutas no se vuelven a cargar cuando están cacheadas (`php artisan optimize` en el despliegue las cachea con todo lo demás). Usa controladores en `Http/Controllers`.

### 3.3 Ganchos

`register()` y `boot()` son `final`. El módulo solo sobrescribe estos métodos `protected`:

| Gancho | Cuándo corre | Para qué |
|---|---|---|
| `registerModule(): void` | Dentro de `register()`, después de fusionar la configuración | Bindings del contenedor, p. ej. la implementación de un contrato de Core. |
| `bootModule(): void` | Al final de `boot()` | Policies, listeners, comandos de Artisan, `User::resolveRelationUsing()` (solo Core). |
| `schedule(Schedule $schedule): void` | Al resolver `Schedule` | Tareas programadas (§10). |
| `navigation(NavRegistry $navigation): void` | Al resolver `NavRegistry` | Entradas del menú (§9). |
| `webPrefix(): string` | Al cargar rutas | Prefijo web sin barras; por defecto la clave. `''` sirve el módulo en la raíz. |
| `apiPrefix(): string` | Al cargar rutas | Prefijo después de `api/`, sin barras; por defecto la clave. `''` lo sirve directo en `/api`. |
| `module(): Module` | Siempre | El módulo: `name`, `path`, `namespace`, `key()`, `path('…')`, `providerClass()`, `seederClass('…')`. |

Ejemplo (nombres de clases ilustrativos):

```php
namespace App\Modules\Cobro\Providers;

use App\Modules\Cobro\Console\SendWeeklyNotices;
use App\Modules\Cobro\Http\Policies\ExclusionPolicy;
use App\Modules\Cobro\Infrastructure\Exclusion;
use App\Modules\Cobro\Infrastructure\GraphMailSender;
use App\Modules\Cobro\Infrastructure\SyncedMailboxReader;
use App\Modules\Core\Contracts\MailboxReader;
use App\Modules\Core\Contracts\MailSender;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\NavRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;

final class CobroServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->singleton(MailSender::class, GraphMailSender::class);
        $this->app->singleton(MailboxReader::class, SyncedMailboxReader::class);
    }

    protected function bootModule(): void
    {
        Gate::policy(Exclusion::class, ExclusionPolicy::class);
        $this->commands([SendWeeklyNotices::class]);
    }

    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('cobro:aviso-semanal')->weeklyOn(1, '08:00')->withoutOverlapping();
    }

    protected function navigation(NavRegistry $navigation): void
    {
        $navigation->add(new NavItem(
            key: 'cobro.lotes',
            label: 'Cobro',
            route: 'cobro.lotes.index',
            permission: 'cobro.view',
            group: 'Cobro',
            activePattern: 'cobro.*',
        ));
    }
}
```

**Qué se registra en `bootModule()`:**

- **Policies:** `Gate::policy(Modelo::class, Policy::class)`, o el atributo `#[UsePolicy(Policy::class)]` en el modelo. No dependas del descubrimiento automático: los modelos de un módulo no viven en `App\Models`.
- **Listeners:** `Event::listen(BatchAccepted::class, Listener::class)`. El descubrimiento automático de Laravel solo mira `app/Listeners`.
- **Comandos:** `$this->commands([...])`, con nombre `<clave>:<accion>` (p. ej. `cobro:aviso-semanal`).
- **Blade:** nada que registrar. Las vistas y los componentes anónimos del módulo ya cargan con su clave (`@include('cartera::partials.ficha')`, `<x-cartera::tarjeta-kanban>`).

### 3.4 Los ocho módulos

| Módulo | Clave | URL web | Nombres web | URL API | Nombres API | Vistas y textos | Configuración | Migraciones |
|---|---|---|---|---|---|---|---|---|
| Core | `core` | `/` (raíz) | `core.*` | `/api/core/*` | `core.api.*` | `core::` | `config('core.*')` | `2026_10_01_*` |
| Ingesta | `ingesta` | `/ingesta/*` | `ingesta.*` | `/api/ingesta/*` | `ingesta.api.*` | `ingesta::` | `config('ingesta.*')` | `2026_10_02_*` |
| Cartera | `cartera` | `/cartera/*` | `cartera.*` | `/api/cartera/*` | `cartera.api.*` | `cartera::` | `config('cartera.*')` | `2026_10_03_*` |
| Asesores | `asesores` | `/asesores/*` | `asesores.*` | `/api/asesores/*` | `asesores.api.*` | `asesores::` | `config('asesores.*')` | `2026_10_04_*` |
| Cobro | `cobro` | `/cobro/*` | `cobro.*` | `/api/cobro/*` | `cobro.api.*` | `cobro::` | `config('cobro.*')` | `2026_10_05_*` |
| Bancos | `bancos` | `/bancos/*` | `bancos.*` | `/api/*` (sin segmento) | `bancos.api.*` | `bancos::` | `config('bancos.*')` | `2026_10_06_*` |
| Gerencia | `gerencia` | `/gerencia/*` | `gerencia.*` | `/api/gerencia/*` | `gerencia.api.*` | `gerencia::` | `config('gerencia.*')` | `2026_10_07_*` |
| Comisiones | `comisiones` | `/comisiones/*` | `comisiones.*` | `/api/comisiones/*` | `comisiones.api.*` | `comisiones::` | `config('comisiones.*')` | `2026_10_08_*` |

- **Core** sobrescribe `webPrefix()` con `''`: sirve `/`, `/login`, `/equipo`… Sus rutas no pueden empezar con la clave de otro módulo (`/cartera`, `/cobro`…).
- **Bancos** sobrescribe `apiPrefix()` con `''` porque Tesorería consulta `GET /api/recaudo` (Módulo 5, punto 9): `Route::get('recaudo', …)->name('recaudo')` queda como `GET /api/recaudo`, nombre `bancos.api.recaudo`. Bancos no puede usar en su API rutas que empiecen con la clave de otro módulo.
- El endpoint del robot, `POST /api/ingesta/{lote}`, cae solo en el prefijo de Ingesta.
- Los errores de `/api/*` responden JSON aunque el cliente no envíe `Accept: application/json` (`bootstrap/app.php`): 422 con `errors` y 401 sin redirigir a `/login`.
- Cada `routes/*.php` del esqueleto trae en un comentario cómo queda una ruta de ejemplo.

---

## 4. Reglas de C.2 aplicadas

| Punto de choque | Cómo se resolvió | Archivo |
|---|---|---|
| `bootstrap/providers.php` | Lista fija con `AppServiceProvider` y `ModulesServiceProvider`; este descubre los proveedores de `app/Modules/*`. Nadie la edita. | `app/Providers/ModulesServiceProvider.php`, `app/Support/Modules/ModuleRegistry.php` |
| `routes/web.php` y `routes/api.php` | Cada módulo carga sus rutas con prefijo y nombre propios. `routes/web.php` solo tiene `/dev/componentes`. | `app/Support/Modules/ModuleServiceProvider.php` |
| Menú de navegación | `NavRegistry` (singleton) se llena desde el gancho `navigation()`. | `app/Support/Navigation/*`, `app/Providers/AppServiceProvider.php` |
| Tareas programadas | Gancho `schedule()` con `callAfterResolving(Schedule::class, …)`. `routes/console.php` solo tiene la cola de la base. | `ModuleServiceProvider::boot()`, `routes/console.php` |
| Migraciones | Cada módulo carga las suyas; los prefijos viven en `config/modules.php` y una prueba de arquitectura falla si una migración no lo usa. | `config/modules.php`, `tests/Architecture/ModulesTest.php` |
| `composer.json`, `package.json` y lockfiles | Solo la Fase 0a. Ya traen las dependencias de A.3, las fuentes y Alpine. | — |
| CSS, JS, layouts, componentes y `public/` | Solo Diseño. Tailwind escanea las vistas de los módulos con `@source`. | `resources/css/app.css` |
| `public/build` | Ignorado en `.gitignore`; el despliegue lo agrega con `git add -f` en su propio PR. | `.gitignore`, `deploy/deploy.sh` |
| Permisos y roles | Cada módulo tiene su `<Modulo>PermissionSeeder`; `DatabaseSeeder` los descubre. | `app/Support/Modules/PermissionSeeder.php`, `database/seeders/DatabaseSeeder.php` |
| Traducciones | Namespace por módulo (`__('cobro::mensajes.enviado')`). | `ModuleServiceProvider::boot()` |
| `.env.example` | Trae todas las variables previstas (§15). | `.env.example` |
| Contratos entre módulos | Viven en `Core/Contracts`; pruebas de arquitectura vigilan que se usen solo ellos. | `tests/Architecture/*` |
| Configuración por módulo *(adición)* | `config/<clave>.php` dentro del módulo, fusionado bajo su clave. | `ModuleServiceProvider::register()` |
| Seeders y factories por módulo *(adición)* | Entradas PSR-4 explícitas por módulo en `composer.json` (§12.4). | `composer.json` |

---

## 5. Interfaces entre las sesiones de la Fase 0b

Diseño y Núcleo trabajan en paralelo. El layout `<x-layouts.app>` ya está escrito contra lo que Núcleo debe entregar, y se degrada sin errores mientras Núcleo no esté en `main`.

### 5.1 Lo que Núcleo debe proveer

| Qué | Detalle | Estado hoy |
|---|---|---|
| `core.login` | `GET /login`: página de ingreso con Microsoft, con `<x-layouts.guest>`. `bootstrap/app.php` ya manda a los invitados a `/login` (`redirectGuestsTo('/login')`). | No existe: un invitado que pide una ruta con `auth` termina en 404. |
| Callback de Microsoft | `GET /auth/microsoft/callback` (nombre sugerido `core.auth.callback`); debe coincidir con `MICROSOFT_REDIRECT_URI`. El driver `microsoft` de Socialite ya está registrado en `AppServiceProvider` y configurado en `config/services.php` (`microsoft`); sin `MICROSOFT_TENANT_ID`, construirlo lanza `RuntimeException` (nunca cae en `common`). Además, el callback verifica que el claim `tid` del usuario que ingresó sea igual al tenant configurado y rechaza el ingreso si no lo es. | No existe. |
| Inicio | `GET /` (nombre sugerido `core.inicio`): inicio según el rol. `bootstrap/app.php` manda ahí a los usuarios con sesión que abren una página solo para invitados (`redirectUsersTo('/')`); el logo del layout enlaza a `url('/')`. | No existe. |
| `core.logout` | `POST /logout`. El layout muestra «Salir» solo si `Route::has('core.logout')`. | No existe: el botón no aparece. |
| `core.empresa.cambiar` | `POST /empresa` con el campo `empresa` = `SGX`, `SDC` o `grupo`. `grupo` equivale a `switchTo(null)`. Ante `CompanyNotAvailable`, redirige atrás con el flash `error`. El layout muestra el selector solo si la ruta existe y `count(available()) > 1`. | No existe: el selector no aparece. |
| Binding de `CurrentCompany` | `registerModule()` de Core enlaza `CurrentCompany` y el resto de sus contratos (§6). El layout lee `palette()`, `available()` y `active()`. | Sin binding: el layout usa `BrandPalette::neutral()`. |
| Sin rol | Pantalla neutra que pide solicitar acceso (A.5). | — |

### 5.2 Lo que Diseño consume y respeta

- La marca entra por `resources/views/components/layouts/partials/head.blade.php`: `BrandPalette::toCssVariables()` en `:root` y `toCssVariables(dark: true)` bajo `prefers-color-scheme: dark`. Los componentes leen solo `--brand-*`.
- Para probar SGX, SDC y el grupo, Diseño usa `FakeCurrentCompany` (§7) e inicia sesión: el layout solo consulta `CurrentCompany` cuando hay usuario.
- Los nombres de íconos que Diseño dibuje se convierten en el vocabulario de `NavItem::$icon` y de la prop `icon` (§8.2).

---

## 6. Contratos (C.4)

Todos viven en `App\Modules\Core\Contracts`. Son PHP sin base de datos, sin HTTP y sin facades (§14.4).

### 6.1 Resumen

| Contrato | Lo implementa | Fase | Fake | ¿Filtra por usuario? |
|---|---|---|---|---|
| `CurrentCompany` | Core | 0b | `FakeCurrentCompany` | Es la sesión del usuario |
| `CarteraRepository` | Core | 0b | `FakeCarteraRepository` | Sí |
| `CompromisoService` | Core | 0b | `FakeCompromisoService` | Sí |
| `GestionLogger` | Core | 0b | `FakeGestionLogger` | Sí |
| `AuditLogger` | Core | 0b | `FakeAuditLogger` | No; registra al actor |
| `IngestaSink` | Ingesta | 1 | `FakeIngestaSink` | No; autoriza quien llama |
| `MailSender` | Cobro | 1 | `FakeMailSender` | No |
| `MailboxReader` | Cobro | 1 | `FakeMailboxReader` | Sí |
| `RecaudoProvider` | Bancos | 1 | `FakeRecaudoProvider` | No; autoriza quien llama |

Mientras el dueño de un contrato no esté en `main`, la interfaz no tiene binding: pruébala con su fake. El código de `app/` nunca importa `Tests\Fakes` (en producción ese namespace no existe: `composer install --no-dev`).

### 6.2 Regla de alcance

La definen `CarteraRepository` y la siguen `CompromisoService`, `GestionLogger` y `MailboxReader`:

- **Empresas:** las de `CurrentCompany::scope()`; si la consulta trae `companies`, esas, recortadas a `CurrentCompany::available()`.
- **Clientes:** `asesor` ve los suyos (su asesor de SAP está vinculado a él); `gerente_comercial`, los de los asesores de su equipo; `gerente_cartera`, `analista_cartera` y `admin`, todos.
- Sin usuario autenticado el resultado es vacío, salvo con `CarteraRepository::asSystem()`, que es solo para tareas programadas, colas y listeners, **nunca para controladores**.
- Un registro fuera del alcance se comporta como si no existiera: `null`, lista vacía o `RecordNotFound`.

### 6.3 `CurrentCompany`

Empresa activa del usuario: SGX, SDC o la vista de grupo (todas las que puede ver). Vive en la sesión; la marca sale de aquí, nunca del dominio del correo. Las empresas se listan en el orden de `CompanyCode`.

| Método | Qué hace |
|---|---|
| `active(): ?Company` | Empresa activa; `null` en la vista de grupo y para invitados. |
| `isGroup(): bool` | Si está en la vista de grupo; siempre `false` para invitados. |
| `scope(): array` | `list<Company>` que cubre la vista: la activa, o todas las disponibles en grupo; vacía para invitados. |
| `available(): array` | `list<Company>` que el usuario puede elegir; vacía para invitados. |
| `switchTo(?CompanyCode $code): void` | Cambia la empresa por el resto de la sesión; `null` es la vista de grupo (exige dos empresas disponibles). Lanza `CompanyNotAvailable`. |
| `palette(): BrandPalette` | Paleta de la activa; neutra en grupo y para invitados. |
| `of(CompanyCode $code): Company` | Cualquier empresa con sus parámetros, sin importar el usuario; para jobs, colas y plantillas de correo. |

### 6.4 `CarteraRepository`

Lectura de clientes, facturas e instantáneas, con la regla de §6.2. Salvo que se diga otra cosa, las cifras salen de la última instantánea de cada empresa.

| Método | Qué hace |
|---|---|
| `asSystem(): CarteraRepository` | Copia sin alcance de usuario (todas las empresas si `companies` está vacío). Solo jobs, colas y listeners. |
| `paginateClients(ClientQuery $query, int $perPage = 25): LengthAwarePaginator` | Una página de `ClientSummary`; la página sale de `?page=`. `InvalidArgumentException` si `$perPage < 1`. |
| `allClients(ClientQuery $query): iterable` | Todos los `ClientSummary` que coinciden, en orden y de forma perezosa, para exportar. |
| `client(int $clientId): ?ClientDetail` | La ficha; `null` si no existe o está fuera del alcance. |
| `openInvoices(int $clientId): array` | `list<Invoice>` abiertas en la última instantánea, vencimiento más antiguo primero. |
| `totals(?ClientQuery $query = null): PortfolioTotals` | Totales y edades; `null` = todo el alcance. En grupo combina la última instantánea de cada empresa y `cutoffDate` es la más antigua. `PortfolioTotals::empty()` sin instantánea. |
| `history(CarbonImmutable $from, CarbonImmutable $to, ?ClientQuery $query = null): array` | `list<PortfolioTotals>`, uno por fecha de corte en el rango (inclusivo), ascendente. Ignora el orden de `$query`. `InvalidArgumentException` si `$from` es posterior a `$to`. |
| `advisors(): array` | `list<Advisor>` de las empresas del alcance que el usuario puede ver (un asesor se ve solo a sí mismo), por nombre. |

### 6.5 `CompromisoService`

Compromisos de pago. Actúa como el usuario autenticado y sigue la regla de §6.2. **Escritura por empresa:** en SGX crea y edita el asesor del cliente; en SDC, la analista de cartera. La gerente y la analista leen todos. Cada escritura queda también en la bitácora del cliente (`ActivityType::Commitment`) y en la auditoría. Montos en pesos y mayores que cero.

Ciclo de vida: `create()` deja el compromiso `Pending`; la tarea diaria de Core pasa a `Broken` el `Pending` vencido sin pago; `Fulfilled` y `Cancelled` son finales.

| Método | Qué hace | Lanza |
|---|---|---|
| `create(NewCommitment $commitment): Commitment` | Registra un compromiso `Pending`. | `RecordNotFound`, `AuthorizationException`, `InvalidArgumentException` (monto) |
| `update(int $commitmentId, CommitmentChanges $changes): Commitment` | Cambia fecha, monto o notas de un `Pending`. | `RecordNotFound`, `AuthorizationException`, `InvalidArgumentException`, `DomainException` (no está `Pending`) |
| `markFulfilled(int $commitmentId, ?string $note = null): Commitment` | Marca `Fulfilled` un `Pending` o `Broken` (un pago tardío también cumple) y fija `fulfilledAt`. | `RecordNotFound`, `AuthorizationException`, `DomainException` (ya final) |
| `cancel(int $commitmentId, string $reason): Commitment` | Anula un `Pending` o `Broken` con motivo obligatorio. | `RecordNotFound`, `AuthorizationException`, `InvalidArgumentException` (motivo vacío), `DomainException` |
| `find(int $commitmentId): ?Commitment` | El compromiso; `null` si no existe o está fuera del alcance. | — |
| `paginate(CommitmentQuery $query, int $perPage = 25): LengthAwarePaginator` | Una página de `Commitment` (`?page=`). | `InvalidArgumentException` (`$perPage < 1`) |

### 6.6 `GestionLogger`

Bitácora de cada cliente (gestiones): llamadas, notas, compromisos, descuentos, correos y cambios de estado. Sigue §6.2. Solo agrega y lee; el borrado lógico lo hacen las pantallas de Core.

| Método | Qué hace | Lanza |
|---|---|---|
| `log(NewActivity $activity): Activity` | Agrega una entrada del usuario autenticado, o del sistema (`Activity::$user = null`) si no hay nadie, p. ej. en un job. | `RecordNotFound` |
| `forClient(int $clientId, int $limit = 50): array` | `list<Activity>` del cliente, más reciente primero; vacía si no existe o está fuera del alcance. | `InvalidArgumentException` (`$limit < 1`) |
| `recent(int $limit = 20): array` | `list<Activity>` de todos los clientes del alcance, más reciente primero. | `InvalidArgumentException` |

### 6.7 `AuditLogger`

Bitácora de auditoría inmutable de toda la plataforma: nunca se actualiza ni se borra. La implementación agrega el actor (usuario o sistema), la IP, el navegador y la hora.

```php
public function record(
    string $action,                     // "<module-key>.<entity>.<verb>", p. ej. "cobro.exclusion.created"
    ?string $subjectType = null,        // p. ej. "client" o "cobro_exclusion"
    int|string|null $subjectId = null,
    array $changes = [],                // campo => ['old' => …, 'new' => …]; al crear o borrar, campo => valor
    array $context = [],                // datos extra, p. ej. el lote o el motivo
): void;
```

Lanza `InvalidArgumentException` si `$action` no sigue el patrón (minúsculas, `snake_case`, tres segmentos o más). Nunca incluyas secretos ni tokens en `$changes` o `$context`.

### 6.8 `IngestaSink`

Recibe los lotes del robot (y las cargas manuales) y guarda el historial. No filtra por usuario: quien llama autoriza.

| Método | Qué hace | Lanza |
|---|---|---|
| `receive(IncomingBatch $batch): LoadRecord` | Valida, guarda una instantánea con fecha (nunca sobrescribe), registra la carga `Accepted` y despacha `BatchAccepted` una vez. Idempotente por `batchId`: un lote repetido devuelve la carga original y no despacha nada. Un lote rechazado queda en el historial como `Rejected` con su error, no guarda nada más y su `batchId` se puede reenviar. | `InvalidBatch` |
| `rows(int $loadId): iterable` | Filas de la carga en el orden recibido, de forma perezosa; vacío si fue rechazada. | `RecordNotFound` |
| `latest(Dataset $dataset, CompanyCode $company): ?LoadRecord` | Instantánea vigente: la carga `Accepted` con el corte más reciente (en empate, la última recibida). | — |
| `history(?Dataset $dataset = null, ?CompanyCode $company = null, int $limit = 30): array` | `list<LoadRecord>` aceptadas y rechazadas, más reciente primero; `null` no filtra. | `InvalidArgumentException` |

**Formato de las filas.** Un arreglo asociativo por fila, con las columnas `snake_case` de `etl/esquema.py` (los nombres «pg»), en el orden que envió el robot:

- `cartera` (`Dataset::Receivables`), una fila por cuota de factura: `slp_code`, `email_asesor`, `codigo_sn`, `nombre_sn`, `nit`, `grupo`, `municipio`, `cuenta_visible`, `tipo_doc`, `documento`, `documento_base`, `referencia_1`, `no_referencia`, `asesor`, `total_plazos`, `no_plazo`, `condicion_pago`, `fecha_contabilizacion`, `fecha_vencimiento`, `fecha_documento`, `dias`, `limite_credito`, `importe_original`, `base_gravable`, `saldo_base`, `saldo_vencido`, `valor_abonado`, `sin_vencer`, `valor_cheques`, `d_0_30`, `d_31_60`, `d_61_90`, `d_91_120`, `d_121_150`, `d_151_180`, `d_mas_180`, `saldo`, `rango`.
- `asesores` (`Dataset::Advisors`): `slp_code`, `nombre`, `email`, `activo`, `clientes`.
- `contactos`, `ventas`, `recaudos`, `cruces`, `maestro_clientes` y `maestro_vendedores`: las columnas que Ingesta agregue a `etl/esquema.py` junto con sus consultas, con las mismas reglas.

**Valores:** columnas enteras como `int`; numéricas como cadena decimal con punto y sin separador de miles (`"1234567.89"`), para que los consumidores conserven montos exactos; fechas como `"Y-m-d"`; texto como `string`; un valor ausente es `null`.

### 6.9 Flujo de `BatchAccepted`

Los consumidores nunca llaman `receive()`: escuchan el evento y leen las filas.

```
Robot ──POST /api/ingesta/{lote} (HMAC)──▶ Ingesta arma IncomingBatch
        └─ IngestaSink::receive() valida, guarda y registra LoadRecord (Accepted)
            └─ event(new BatchAccepted($load))          ← una vez por lote aceptado
                ├─ Core: cartera, asesores, contactos
                └─ Comisiones: ventas, recaudos, cruces, maestro_clientes, maestro_vendedores
                    └─ app(IngestaSink::class)->rows($event->load->id)
```

```php
// En bootModule() de Core:
Event::listen(BatchAccepted::class, ImportReceivablesSnapshot::class);

// El listener filtra por dataset y va a la cola si el trabajo es pesado:
final class ImportReceivablesSnapshot implements ShouldQueue
{
    public function handle(BatchAccepted $event): void
    {
        if ($event->load->dataset !== Dataset::Receivables) {
            return;
        }

        foreach (app(IngestaSink::class)->rows($event->load->id) as $row) {
            // ...
        }
    }
}
```

`BatchAccepted` tiene una sola propiedad: `public readonly LoadRecord $load`. No se despacha para un `batchId` repetido ni para un lote rechazado.

### 6.10 `MailSender`

Envía correo por Microsoft Graph (`sendMail`) desde el buzón de cada empresa: `segurex.info@segurex.com` para SGX y `derivados.info@derivadoscol.com` para SDC. La app solo tiene permisos de Graph sobre esos dos buzones.

| Método | Qué hace | Lanza |
|---|---|---|
| `send(OutgoingMail $mail): SentMail` | Envía en el momento; para envíos masivos, pon en cola el job que llama. Con `OutgoingMail::$clientId` guarda los ids para unir respuestas y rebotes. **No** escribe la bitácora: quien manda correo a un cliente registra `ActivityType::EmailSent`. | `MailNotSent` (no se envió nada) |

### 6.11 `MailboxReader`

Respuestas y rebotes de los buzones, unidos al cliente por conversación, Message-ID enviado o remitente. Lee lo que Cobro ya sincronizó: nunca llama a Graph al pintar una página. Sigue §6.2.

| Método | Qué hace | Lanza |
|---|---|---|
| `forClient(int $clientId, int $limit = 20): array` | `list<InboundMail>` del cliente, más reciente primero; vacía si no existe o está fuera del alcance. | `InvalidArgumentException` |
| `unreadCount(?CompanyCode $company = null): int` | Mensajes sin leer (respuestas y rebotes, unidos o no) de una empresa, o de las de `CurrentCompany::scope()` con `null`. | — |

### 6.12 `RecaudoProvider`

Recaudo: suma de créditos bancarios por fecha de movimiento. Los movimientos llegan al día siguiente (día vencido), así que el día actual normalmente falta. No filtra por usuario. Fechas por día calendario en America/Bogota; rangos inclusivos; montos en pesos.

| Método | Qué hace | Lanza |
|---|---|---|
| `daily(CarbonImmutable $from, CarbonImmutable $to, ?CompanyCode $company = null): array` | `list<DailyCollection>`, una por día y empresa con al menos un crédito, por fecha y luego empresa. | `InvalidArgumentException` |
| `weekly(CarbonImmutable $from, CarbonImmutable $to, ?CompanyCode $company = null): array` | `list<WeeklyCollection>` por semana ISO (lunes a domingo) y empresa. Solo suma días del rango (la primera y la última semana pueden ser parciales); omite semanas sin créditos. | `InvalidArgumentException` |

### 6.13 Objetos de datos (`Contracts\Data`)

Todos son `final readonly`. Los campos van en el orden del constructor; los que tienen valor por defecto lo muestran.

**Empresa, marca y usuarios**

| Clase | Campos | Métodos |
|---|---|---|
| `Company` | `code: CompanyCode`, `name: string`, `graceDays: int`, `palette: BrandPalette` | — |
| `BrandPalette` | `primary`, `accent`, `dark`, `button`, `tint`, `ink`, `darkTint`, `darkInk` (todos `string` hex `#rgb`, `#rrggbb` o `#rrggbbaa`; si no, `InvalidArgumentException`) | `neutral()`, `official(CompanyCode)`, `toCssVariables(bool $dark = false): array` → `--brand-primary`, `--brand-accent`, `--brand-dark`, `--brand-button`, `--brand-tint`, `--brand-ink` |
| `UserRef` | `id: int`, `name: string`, `email: string` | — |

`graceDays` son los días después del vencimiento antes de que la factura cuente como vencida para el estado del cliente (SGX 15 y SDC 5 por defecto; Core los guarda como parámetro). Las edades de la deuda los ignoran.

**Cartera**

| Clase | Campos | Métodos |
|---|---|---|
| `ClientSummary` | `id: int`, `company: CompanyCode`, `code: string` (OCRD.CardCode), `name: string`, `taxId: string` (NIT), `status: ClientStatus`, `balance: float`, `overdue: float`, `maxDaysOverdue: int`, `advisor: ?Advisor = null`, `manager: ?UserRef = null` (gestor responsable), `lastActivityAt: ?CarbonImmutable = null` | — |
| `ClientDetail` | `summary: ClientSummary`, `creditLimit: ?float = null` (cupo), `group: ?string = null`, `city: ?string = null`, `paymentTerms: ?string = null`, `contacts: list<Contact> = []` | `emails(): list<string>` (únicos, principales primero) |
| `Contact` | `name: ?string = null`, `email: ?string = null`, `phone: ?string = null`, `position: ?string = null`, `isPrimary: bool = false` | — |
| `Invoice` | `id: int`, `company: CompanyCode`, `clientId: int`, `documentNumber: string`, `documentType: string`, `issuedAt: CarbonImmutable`, `dueAt: CarbonImmutable`, `amount: float`, `balance: float`, `daysOverdue: int`, `advisorCode: ?int = null` | — |
| `Advisor` | `company: CompanyCode`, `code: int` (OSLP.SlpCode), `name: string`, `email: ?string = null`, `active: bool = true`, `userId: ?int = null` | — |
| `ClientQuery` | `search: ?string = null`, `statuses: list<ClientStatus> = []`, `advisorCode: ?int = null`, `companies: list<CompanyCode> = []`, `onlyOverdue: bool = false`, `inactiveSince: ?CarbonImmutable = null`, `sort: string = 'name'`, `direction: string = 'asc'` | `SORTS` = `name`, `balance`, `overdue`, `days_overdue`, `status`, `last_activity`; `DIRECTIONS` = `asc`, `desc` |
| `PortfolioTotals` | `cutoffDate: ?CarbonImmutable`, `total: float`, `overdue: float`, `clientCount: int`, `overdueClientCount: int`, `aging: AgingBuckets` | `empty()`, `overduePercent(): float` (0 a 100) |
| `AgingBuckets` | `notDue`, `days1To30`, `days31To60`, `days61To90`, `over90` (todos `float = 0`) | `total()`, `overdue()` |

`daysOverdue` va del vencimiento al corte: positivo = vencida, 0 = vence en el corte, negativo = aún no vence. `ClientQuery` y `CommitmentQuery` lanzan `InvalidArgumentException` con un `sort` o `direction` no permitido; los empates se resuelven por nombre y luego id (clientes) o por id (compromisos).

**Compromisos y bitácora**

| Clase | Campos |
|---|---|
| `Commitment` | `id: int`, `company: CompanyCode`, `clientId: int`, `clientName: string`, `advisorCode: ?int`, `amount: float`, `dueDate: CarbonImmutable`, `status: CommitmentStatus`, `notes: ?string`, `createdBy: UserRef`, `createdAt: CarbonImmutable`, `fulfilledAt: ?CarbonImmutable = null` |
| `NewCommitment` | `clientId: int`, `dueDate: CarbonImmutable`, `amount: float`, `notes: ?string = null` |
| `CommitmentChanges` | `dueDate: ?CarbonImmutable = null`, `amount: ?float = null`, `notes: ?string = null` (`null` deja el campo igual; las notas no se pueden borrar así) |
| `CommitmentQuery` | `clientId: ?int = null`, `advisorCode: ?int = null`, `statuses: list<CommitmentStatus> = []`, `dueFrom: ?CarbonImmutable = null`, `dueTo: ?CarbonImmutable = null`, `companies: list<CompanyCode> = []`, `sort: string = 'due_date'`, `direction: string = 'asc'`; `SORTS` = `due_date`, `amount`, `created_at` |
| `Activity` | `id: int`, `clientId: int`, `clientName: string`, `type: ActivityType`, `summary: string`, `details: ?string`, `result: ?string`, `user: ?UserRef`, `occurredAt: CarbonImmutable`, `metadata: array<string, scalar\|null> = []` |
| `NewActivity` | `clientId: int`, `type: ActivityType`, `summary: string`, `details: ?string = null`, `result: ?string = null`, `occurredAt: ?CarbonImmutable = null` (null = ahora), `metadata: array<string, scalar\|null> = []` (p. ej. `['commitment_id' => 12]`) |

**Ingesta, correo y recaudo**

| Clase | Campos |
|---|---|
| `IncomingBatch` | `dataset: Dataset`, `company: CompanyCode`, `cutoffDate: CarbonImmutable`, `source: LoadSource`, `batchId: string` (único entre datasets y empresas), `rows: list<array<string, scalar\|null>>`, `receivedBy: ?int = null` (usuario de una carga manual) |
| `LoadRecord` | `id: int`, `dataset: Dataset`, `company: CompanyCode`, `cutoffDate: CarbonImmutable`, `source: LoadSource`, `status: LoadStatus`, `rowCount: int`, `total: ?float`, `overdue: ?float` (solo cartera: suma de `saldo` y de `saldo` con `dias > 0`), `receivedAt: CarbonImmutable`, `receivedBy: ?UserRef = null`, `error: ?string = null` |
| `OutgoingMail` | `company: CompanyCode`, `to: list<string>`, `subject: string`, `html: string`, `cc: list<string> = []`, `replyTo: ?string = null`, `attachments: list<MailAttachment> = []`, `clientId: ?int = null`, `category: string = 'general'` (p. ej. `cobro_semanal`, `circularizacion`, `prueba`, `alerta`). Lanza `InvalidArgumentException` sin destinatarios o con uno vacío. |
| `MailAttachment` | `filename: string`, `contentType: string`, `content: string` (bytes crudos, sin base64) |
| `SentMail` | `mailbox: string`, `internetMessageId: string` (con `< >`), `conversationId: ?string`, `sentAt: CarbonImmutable` |
| `InboundMail` | `id: string`, `company: CompanyCode`, `clientId: ?int`, `from: string`, `subject: string`, `preview: string`, `receivedAt: CarbonImmutable`, `isBounce: bool = false`, `bounceReason: ?string = null`, `conversationId: ?string = null`, `read: bool = false`, `url: ?string = null` |
| `DailyCollection` | `date: CarbonImmutable`, `company: CompanyCode`, `total: float`, `byBank: array<string, float>` (llave = valor de `Bank`), `movementCount: int` |
| `WeeklyCollection` | `weekStart: CarbonImmutable` (lunes), `weekEnd: CarbonImmutable` (domingo), `company: CompanyCode`, `total: float`, `byBank: array<string, float>` |

### 6.14 Enums (`Contracts\Enums`)

Todos son `string` y usan el trait `HasOptions`: `label()` da el texto en español y `Enum::options()` devuelve `valor => etiqueta` para `<x-ui.select>` y reglas de validación.

| Enum | Caso → valor («etiqueta») |
|---|---|
| `CompanyCode` | `SGX` → `SGX` («Segurex Latam»), `SDC` → `SDC` («Soluciones y Derivados»). La vista de grupo no es un caso: los contratos la representan con `null`. |
| `ClientStatus` (orden = columnas del Kanban) | `Current` → `al_dia` («Al día»), `DueSoon` → `por_vencer` («Por vencer»), `Overdue` → `vencida` («Vencida»), `InProgress` → `en_gestion` («En gestión»), `Committed` → `compromiso` («Compromiso»), `Defaulted` → `incumplido` («Incumplido»), `PaymentReceived` → `pago_recibido` («Pago recibido»), `Legal` → `juridico` («Jurídico») |
| `CommitmentStatus` | `Pending` → `pendiente` («Pendiente»), `Fulfilled` → `cumplido` («Cumplido»), `Broken` → `incumplido` («Incumplido»), `Cancelled` → `anulado` («Anulado») |
| `ActivityType` | `Call` → `llamada` («Llamada»), `Note` → `nota` («Nota»), `Commitment` → `compromiso` («Compromiso de pago»), `DiscountOffered` → `descuento` («Descuento ofrecido»), `EmailSent` → `correo_enviado` («Correo enviado»), `EmailReceived` → `correo_recibido` («Correo recibido»), `StatusChange` → `cambio_estado` («Cambio de estado»), `System` → `sistema` («Sistema») |
| `Dataset` (valor = segmento `{lote}`) | `Receivables` → `cartera` («Cartera»), `Advisors` → `asesores` («Asesores»), `Contacts` → `contactos` («Contactos»), `Sales` → `ventas` («Ventas»), `Collections` → `recaudos` («Recaudos»), `Offsets` → `cruces` («Cruces»), `CustomerMaster` → `maestro_clientes` («Maestro de clientes»), `SalesRepMaster` → `maestro_vendedores` («Maestro de vendedores») |
| `LoadSource` | `Robot` → `robot` («Robot»), `Manual` → `manual` («Carga manual») |
| `LoadStatus` | `Accepted` → `aceptada` («Aceptada»), `Rejected` → `rechazada` («Rechazada») |
| `Bank` | `Bogota` → `bogota` («Banco de Bogotá»), `Davivienda` → `davivienda` («Davivienda»), `Bancolombia` → `bancolombia` («Bancolombia»), `Miami` → `miami` («Miami») |
| `Role` (valor = rol de Spatie) | `Admin` → `admin` («Administrador»), `PortfolioManager` → `gerente_cartera` («Gerente de cartera»), `PortfolioAnalyst` → `analista_cartera` («Analista de cartera»), `SalesAdvisor` → `asesor` («Asesor comercial»), `SalesManager` → `gerente_comercial` («Gerente comercial»), `Treasury` → `tesoreria` («Tesorería») |

En `ClientStatus`, los estados automáticos (`Current`, `DueSoon`, `Overdue`, `PaymentReceived`) se recalculan con cada instantánea y los días de gracia; los manuales (`InProgress`, `Committed`, `Defaulted`, `Legal`) sobreviven a nuevas instantáneas mientras el cliente tenga saldo. Las reglas son de Core.

### 6.15 Excepciones (`Contracts\Exceptions`)

Todas extienden `ContractException` (abstracta, `RuntimeException`), así que se pueden atrapar como familia. Sus mensajes son para logs (inglés); el módulo muestra su propio texto en español.

| Excepción | Cuándo | Datos |
|---|---|---|
| `RecordNotFound` | Id inexistente o fuera del alcance (se ven igual a propósito). Un controlador responde 404 (`abort(404)`). | `$type`, `$id`; `RecordNotFound::for(string $type, int\|string $id)` |
| `CompanyNotAvailable` | `switchTo()` con una empresa o vista de grupo no disponible. | `$company: ?CompanyCode`; `forCompany()`, `groupView()` |
| `InvalidBatch` | `IngestaSink::receive()` rechazó el lote. | `errors(): array<string, list<string>>` con llaves tipo `rows.3.saldo` |
| `MailNotSent` | Graph no aceptó el correo; la causa va en `getPrevious()`. Nunca trae credenciales. | — |

Los contratos también lanzan `InvalidArgumentException`, `DomainException` e `Illuminate\Auth\Access\AuthorizationException` (Laravel responde 403).

### 6.16 Convenciones

- **Montos:** `float` en pesos colombianos, para mostrar y sumar. Las filas de ingesta traen cadenas decimales exactas; Comisiones calcula con aritmética exacta sobre ellas, no con `float`.
- **Fechas:** `CarbonImmutable`; las de solo fecha están al inicio del día en America/Bogota.
- **DTO:** `final readonly`; para «cambiar» uno, crea otro.
- **Listas:** `list<…>` en orden documentado; las páginas son `Illuminate\Contracts\Pagination\LengthAwarePaginator` que leen `?page=`. `<x-ui.pagination>` conserva en sus enlaces la búsqueda, los filtros y el orden de la URL actual.
- **Ids:** los de cliente, compromiso, factura y carga son ids de la plataforma, no de SAP (`ClientSummary::$code` es el código de SAP).

---

## 7. Fakes para pruebas

`tests/Fakes` tiene un fake en memoria por contrato. Úsalos mientras el módulo real no esté en `main` y siempre que una prueba no deba depender de otro módulo.

### 7.1 Instalar

```php
use Tests\Fakes\ContractFakes;

$fakes = ContractFakes::install($this->app);
```

Enlaza los nueve fakes como instancias del contenedor y devuelve un `ContractFakes` con las propiedades `currentCompany`, `cartera`, `compromisos`, `gestiones`, `audit`, `ingesta`, `mail`, `mailbox` y `recaudo`. Los fakes vienen conectados: cartera, compromisos y buzón siguen a `currentCompany`; compromisos y bitácora conocen los clientes de `cartera` y respetan su alcance.

> **Ojo:** `currentCompany` arranca con SGX activa y SGX y SDC disponibles. Un cliente SDC no aparece hasta que cambies de empresa: `$fakes->currentCompany->switchTo(CompanyCode::SDC)` o `switchTo(null)` para la vista de grupo.

### 7.2 Constructores `Make::…`

`Tests\Fakes\Make` arma datos de contrato con valores colombianos de ejemplo. Todos los argumentos son opcionales y con nombre. Los ids salen de una secuencia compartida (no afirmes su valor exacto) y las fechas son relativas a `now()` (usa `travelTo()` para fijarlas).

| Método | Devuelve | Valores por defecto útiles |
|---|---|---|
| `Make::company()` | `Company` | SGX, gracia 15 (SDC: 5), paleta oficial |
| `Make::userRef()` | `UserRef` | «María Paula Rojas» |
| `Make::advisor()` | `Advisor` | SGX, «Carlos Pérez», activo |
| `Make::contact()` | `Contact` | «Laura Martínez», `tesoreria@cliente.example`, no principal |
| `Make::client()` | `ClientSummary` | SGX, «Ferretería El Tornillo SAS», `Current`, saldos en 0 |
| `Make::clientDetail()` | `ClientDetail` | Un cliente nuevo, cupo 50.000.000 y un contacto principal |
| `Make::invoice()` | `Invoice` | `clientId: 1`, 1.000.000, vence hoy menos `daysOverdue` |
| `Make::commitment()` | `Commitment` | `Pending`, 2.500.000, vence en 7 días |
| `Make::activity()` | `Activity` | Llamada; sin usuario si es `System` o `bySystem: true` |
| `Make::loadRecord()` | `LoadRecord` | Cartera SGX, robot, aceptada, corte hoy |
| `Make::inboundMail()` | `InboundMail` | Respuesta de `tesoreria@cliente.example` al cliente 1 |
| `Make::dailyCollection()` | `DailyCollection` | Ayer, SGX, 12.500.000 en Bancolombia |
| `Make::nextId()` | `int` | Siguiente id de la secuencia |

### 7.3 Ayudas de cada fake

| Fake | Preparar | Afirmar o inspeccionar |
|---|---|---|
| `FakeCurrentCompany` | `new FakeCurrentCompany(?CompanyCode $active = SGX, array $available = [SGX, SDC], array $graceDays = ['SGX' => 15, 'SDC' => 5])`, `::group()`, `::guest()`, `switchTo()`. Como el contrato, lanza `CompanyNotAvailable` con una activa que no está disponible o con `$active = null` y una sola empresa (la vista de grupo exige dos; sin empresas es un invitado). | Métodos del contrato |
| `FakeCarteraRepository` | `addClient(ClientDetail $client, Invoice ...$invoices)`, `addAdvisor(Advisor ...)`, `addHistory(PortfolioTotals ...)`, `restrictToAdvisor(?int $code)`, propiedad `cutoffDate` | Métodos del contrato |
| `FakeCompromisoService` | `actingAs(UserRef)`, `knowClient(int $id, string $name, CompanyCode $company = SGX, ?int $advisorCode = null)`, `add(Commitment ...)`, `denyAuthorization(bool $deny = true)` | `all()`, `cancellationReason(int)`, `assertCreated(?callable $filter = null)`, `assertNothingCreated()` |
| `FakeGestionLogger` | `actingAs(?UserRef)` (`null` = sistema), `knowClient(int, string)`, `add(Activity ...)` | `logged()`, `assertLogged(ActivityType $type, ?int $clientId = null)`, `assertNothingLogged()` |
| `FakeAuditLogger` | — (valida el patrón de `$action`) | `entries()` (`list<AuditEntry>`), `assertRecorded(string $action, ?callable $filter = null)`, `assertNothingRecorded()` |
| `FakeIngestaSink` | `failNextWith(InvalidBatch)` | `received()`, `assertReceived(Dataset $dataset, ?CompanyCode $company = null)`, `assertNothingReceived()` |
| `FakeMailSender` | `failNextWith(MailNotSent)`; constante `MAILBOXES` | `sent()`, `receipts()`, `assertSent(?callable $filter = null, ?int $times = null)`, `assertSentTo(string $email)`, `assertNothingSent()` |
| `FakeMailboxReader` | `add(InboundMail ...)` | Métodos del contrato |
| `FakeRecaudoProvider` | `addDaily(DailyCollection ...)` (`weekly()` se calcula de ahí) | Métodos del contrato |

Diferencias con las implementaciones reales que conviene saber:

- `FakeCarteraRepository` calcula `totals()` con las **facturas** agregadas, no con `balance` ni `overdue` del `ClientSummary`; `history()` ignora la consulta. Sin usuario autenticado no vacía resultados: se comporta como una analista que ve todo. `addClient()` lanza `InvalidArgumentException` si una factura es de otro cliente o empresa: créala con `clientId:` y `company:`.
- `FakeCompromisoService` no escribe en `gestiones` ni en `audit`: afirma esas escrituras en tu propio código, no como efecto del servicio. Su usuario por defecto es `UserRef(1, 'Usuario de prueba', 'prueba@example.com')`.
- `FakeIngestaSink` despacha `BatchAccepted` con `event()`: los listeners registrados corren de verdad. Para solo afirmar el despacho, usa `Event::fake([BatchAccepted::class])`.
- `FakeMailboxReader::forClient()` no emula el alcance por cliente.
- `FakeMailSender` numera los envíos: el N-ésimo devuelve `<fake-N@gestor-cartera.test>` y `fake-conversation-N`.

### 7.4 Emular el alcance de un asesor

`restrictToAdvisor($code)` deja visibles solo los clientes cuyo `advisor->code` es ese; con `null` se levanta. `asSystem()` devuelve una copia sin alcance que comparte los datos del fake original. Así se escriben las pruebas de aislamiento antes de que exista Core:

```php
use App\Modules\Core\Contracts\Data\ClientQuery;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\Make;

it('muestra al asesor solo sus clientes', function () {
    $fakes = ContractFakes::install($this->app);

    $fakes->cartera
        ->addClient(Make::clientDetail(Make::client(name: 'Cliente propio', advisor: Make::advisor(code: 10))))
        ->addClient(Make::clientDetail(Make::client(name: 'Cliente ajeno', advisor: Make::advisor(code: 20))))
        ->restrictToAdvisor(10);

    $names = array_map(fn ($client) => $client->name, [...$fakes->cartera->allClients(new ClientQuery)]);

    expect($names)->toBe(['Cliente propio'])
        ->and($fakes->cartera->asSystem()->totals()->clientCount)->toBe(2);
});
```

---

## 8. Componentes Blade

Los crea la Fase 0a como stubs funcionales sin estilo; Diseño les da el aspecto sin cambiar sus props. La galería `GET /dev/componentes` (nombre `dev.componentes`, vista `design.gallery`) los muestra con sus estados **solo en local**; en cualquier otro entorno responde 404.

### 8.1 Props

En Blade, una prop `camelCase` se pasa en `kebab-case`: `:icon-only="true"`, `caption-hidden`, `label-hidden`, `sort-by`, `sort-direction`, `empty-message`, `trend-label`. «Obligatoria» significa sin valor por defecto.

| Componente | Prop | Tipo | Por defecto | Notas |
|---|---|---|---|---|
| `<x-layouts.app>` | `title` | `?string` | `null` | `<title>`: «título · nombre de la app». Slot: contenido. Atributos extra van a `<body>`. |
| `<x-layouts.guest>` | `title` | `?string` | `null` | Paleta neutra siempre y sin navegación. Slot: contenido. |
| `<x-ui.page-header>` | `title` | `string` | obligatoria | El único `<h1>` de la página. Slot `actions`. |
| | `context` | `?string` | `null` | Línea corta sobre el título. |
| | `description` | `?string` | `null` | |
| `<x-ui.card>` | `title` | `?string` | `null` | Con título es `<section>` nombrada por su encabezado (id `<id>-title`); sin título, `<div>`. |
| | `description` | `?string` | `null` | |
| | `level` | `int` | `2` | Nivel del encabezado: 2, 3 o 4. Slots: por defecto (cuerpo), `actions`, `footer`. |
| `<x-ui.stat>` | `label` | `string` | obligatoria | |
| | `value` | `?string` | `null` | Ya formateado; vacío muestra «—». Un slot no vacío lo reemplaza (p. ej. `<x-ui.money>`). |
| | `hint` | `?string` | `null` | |
| | `trend` | `?string` | `null` | `up`, `down`, `flat` o `null`. |
| | `trendLabel` | `?string` | `null` | Texto para lectores de pantalla; por defecto «Sube», «Baja» o «Sin cambios». |
| | `tone` | `string` | `'neutral'` | `neutral`, `success`, `warning`, `danger`. |
| | `href` | `?string` | `null` | Con enlace, todo el bloque es `<a>`. |
| `<x-ui.chip>` | `tone` | `string` | `'neutral'` | `neutral`, `success`, `warning`, `danger`, `info`. Slot: texto obligatorio. |
| | `dot` | `bool` | `true` | Punto de color decorativo. |
| `<x-ui.button>` | `variant` | `string` | `'primary'` | `primary`, `secondary`, `ghost`, `danger`. |
| | `type` | `string` | `'button'` | `button`, `submit`, `reset`. |
| | `icon` | `?string` | `null` | Nombre de ícono. |
| | `iconOnly` | `bool` | `false` | Exige `icon`; el texto del slot queda oculto a la vista pero nombra el botón. Slot: texto obligatorio. |
| `<x-ui.link-button>` | `href` | `string` | obligatoria | |
| | `variant` | `string` | `'primary'` | Igual que el botón. |
| | `icon` | `?string` | `null` | |
| | `iconOnly` | `bool` | `false` | Exige `icon`. Slot: texto obligatorio. |
| | `external` | `bool` | `false` | Abre en otra pestaña (`rel="noopener noreferrer"`) y lo anuncia. |
| `<x-ui.table>` | `caption` | `string` | obligatoria | También nombra la región con scroll. |
| | `captionHidden` | `bool` | `false` | Oculta el caption a la vista. |
| | `columns` | `array` | `[]` | Cada una: `['key' => string, 'label' => string, 'sortable' => bool, 'align' => 'start'\|'center'\|'end']`. |
| | `sortBy` | `?string` | `null` | `key` de la columna activa. |
| | `sortDirection` | `string` | `'asc'` | `asc` o `desc`. |
| | `emptyMessage` | `string` | `'No hay registros para mostrar.'` | Se muestra si el slot está vacío. Slots: por defecto (filas `<tr>`), `footer` (filas de `<tfoot>`). Atributos extra van a `<table>`. |
| `<x-ui.empty>` | `title` | `string` | obligatoria | |
| | `description` | `?string` | `null` | |
| | `icon` | `?string` | `null` | Slot: acciones (qué hacer). |
| `<x-ui.alert>` | `tone` | `string` | `'info'` | `info`, `success`, `warning`, `danger`. Los dos primeros usan `role="status"`; los otros, `role="alert"`. |
| | `title` | `?string` | `null` | |
| | `dismissible` | `bool` | `false` | Se cierra con Alpine. Slot: cuerpo. |
| `<x-ui.input>` | `name` | `string` | obligatoria | Admite `clientes[0][email]`. |
| | `label` | `string` | obligatoria | |
| | `type` | `string` | `'text'` | Con `password` nunca repinta el valor. |
| | `value` | `mixed` | `null` | `old()` gana después de un envío fallido. |
| | `hint` | `?string` | `null` | |
| | `required` | `bool` | `false` | |
| | `labelHidden` | `bool` | `false` | |
| | `prefix` | `?string` | `null` | |
| | `suffix` | `?string` | `null` | |
| | `id` | `?string` | `null` | Por defecto, derivado de `name`. Atributos extra van a `<input>`. |
| `<x-ui.select>` | `name` | `string` | obligatoria | |
| | `label` | `string` | obligatoria | |
| | `options` | `array` | obligatoria | `valor => etiqueta`, p. ej. `ClientStatus::options()`. |
| | `value` | `mixed` | `null` | Escalar, enum o lista (para `multiple`); `old()` gana. |
| | `placeholder` | `?string` | `null` | Opción vacía al inicio. |
| | `hint` | `?string` | `null` | |
| | `required` | `bool` | `false` | |
| | `labelHidden` | `bool` | `false` | |
| | `id` | `?string` | `null` | Atributos extra van a `<select>`. |
| `<x-ui.checkbox>` | `name` | `string` | obligatoria | Admite listas (`clientes[]`). |
| | `label` | `string` | obligatoria | |
| | `value` | `string\|int\|BackedEnum` | `'1'` | |
| | `checked` | `?bool` | `null` | Después de un envío fallido (hay old input) manda `old()`, aun sobre `checked`: una casilla ausente queda sin marcar. Sin old input, `checked` (`null` = sin marcar). |
| | `hint` | `?string` | `null` | |
| | `id` | `?string` | `null` | Por defecto, de `name` y `value`. Atributos extra van a `<input>`. |
| `<x-ui.textarea>` | `name` | `string` | obligatoria | |
| | `label` | `string` | obligatoria | |
| | `value` | `?string` | `null` | `old()` gana. |
| | `rows` | `int` | `4` | |
| | `hint` | `?string` | `null` | |
| | `required` | `bool` | `false` | |
| | `labelHidden` | `bool` | `false` | |
| | `id` | `?string` | `null` | Atributos extra van a `<textarea>`. |
| `<x-ui.modal>` | `id` | `string` | obligatoria | |
| | `title` | `string` | obligatoria | |
| | `open` | `bool` | `false` | Abre al cargar la página (p. ej. tras un error de validación). Slots: por defecto (cuerpo), `footer`. |
| `<x-ui.tabs>` | `label` | `string` | obligatoria | `aria-label` del `<nav>`. |
| | `items` | `array` | obligatoria | Cada uno: `['key' => string, 'label' => string, 'href' => string, 'count' => ?int]`. |
| | `current` | `?string` | `null` | `key` de la pestaña activa (`aria-current="page"`). |
| `<x-ui.badge-state>` | `state` | `ClientStatus\|string` | obligatoria | Un `string` debe ser un valor de `ClientStatus`. |
| `<x-ui.money>` | `value` | `int\|float\|string\|null` | obligatoria | Cadena numérica permitida (`"1234567.89"`). |
| | `currency` | `string` | `'COP'` | `COP` o `USD`. |
| | `decimals` | `?int` | `null` | `null` = 0 en COP y 2 en USD. |
| | `fallback` | `string` | `'—'` | Para `null` o `''`. |
| `<x-ui.date>` | `value` | `DateTimeInterface\|string\|null` | obligatoria | |
| | `time` | `bool` | `false` | Agrega `HH:MM`. |
| | `fallback` | `string` | `'—'` | Para `null` o texto vacío (sin `<time>`). |
| `<x-ui.icon>` *(adición)* | `name` | `string` | obligatoria | Se pinta como `data-icon="<name>"`. |
| | `label` | `?string` | `null` | Sin etiqueta es decorativo (`aria-hidden`); con ella, `role="img"`. |
| `<x-ui.pagination>` *(adición)* | `paginator` | `Illuminate\Contracts\Pagination\Paginator` | obligatoria | No pinta nada si todo cabe en una página; con `LengthAwarePaginator` muestra «Página X de Y». Sus enlaces conservan la query de la URL actual (búsqueda, filtros, orden), salvo el parámetro de página. |
| | `label` | `string` | `'Paginación'` | `aria-label` del `<nav>`. |

### 8.2 Reglas comunes

- **Atributos:** los que no son props pasan al elemento principal (`class`, `id`, `x-*`, `data-*`, `disabled`, `form`…). En los campos de formulario van al `<input>`, `<select>` o `<textarea>`, no al contenedor.
- **Ganchos para Diseño:** el marcado expone `data-part`, `data-tone`, `data-variant`, `data-state`, `data-icon`, `data-icon-only`, `data-trend`, `data-negative`, `data-invalid`, `data-align` y `data-disabled`. Diseño estiliza con ellos y no cambia lo que significan.
- **Valores inválidos:** los componentes fallan en voz alta. `alert`, `button`, `card`, `chip`, `link-button`, `stat`, `table` y `pagination` lanzan `InvalidArgumentException` con un tono, variante, tipo, nivel, tendencia, dirección, alineación o paginador no válido, o sin el texto obligatorio; `money` y `date` también, con un monto o una fecha que no se pueden leer; `badge-state` lanza `ValueError` (`ClientStatus::from()`).
- **Texto solo para lectores de pantalla:** el atributo `data-visually-hidden` (regla en `resources/css/app.css`). Lo usan los botones de solo ícono, las pistas de orden de la tabla, los captions ocultos y las etiquetas con `labelHidden`.
- **Íconos que los componentes ya piden:** `close` (alerta descartable y modal), `sort`, `sort-asc`, `sort-desc` (tabla), `trend-up`, `trend-down`, `trend-flat` (stat). Diseño debe dibujarlos.
- **Modal:** cualquier elemento con `data-modal-open="<id>"` abre el `<dialog>` con ese id; uno con `data-modal-close` cierra su diálogo (`resources/js/app.js`). `:open="true"` lo abre al cargar (`data-modal-autoopen`).

  ```blade
  <x-ui.button data-modal-open="nuevo-compromiso">Nuevo compromiso</x-ui.button>
  <x-ui.modal id="nuevo-compromiso" title="Nuevo compromiso" :open="$errors->any()">…</x-ui.modal>
  ```

- **Llamadas desde Alpine:** usa `window.apiFetch(url, options)` (`resources/js/app.js`), no `fetch()` suelto. Agrega `Accept: application/json`, `X-Requested-With: XMLHttpRequest` y `X-CSRF-TOKEN` (de `<meta name="csrf-token">`, que pintan los layouts), envía la cookie de sesión (`credentials: 'same-origin'`) y convierte a JSON un `body` que sea un objeto o un arreglo (con `Content-Type: application/json`); `FormData` y los textos van tal cual. Los encabezados que pases ganan. Devuelve el `Response` de `fetch()`: revisa `response.ok` (un 422 trae `errors`).

  ```blade
  <div x-data="{
      guardando: false,
      error: null,
      async excluir() {
          this.guardando = true;
          const response = await apiFetch('{{ route('cobro.exclusiones.store') }}', {
              method: 'POST',
              body: { cliente_id: 12, motivo: 'Acuerdo de pago vigente' },
          });
          this.error = response.ok ? null : (await response.json()).message;
          this.guardando = false;
      },
  }">
      <x-ui.button x-on:click="excluir" x-bind:disabled="guardando">Excluir del cobro</x-ui.button>
      <p x-show="error" x-text="error"></p>
  </div>
  ```

- **Pestañas:** son enlaces; no hay paneles del lado del cliente. Cada pestaña es una URL.
- **Orden de tablas:** el encabezado ordenable enlaza a la URL actual con `?sort=<key>&direction=asc|desc` y quita `?page`. Son los mismos nombres de `ClientQuery` y `CommitmentQuery`; usa como `key` un valor de su `SORTS`.
- **Paginación:** siempre `<x-ui.pagination :paginator="$clientes" />`, nunca `->links()`.
- **Mensajes flash:** los layouts pintan `session('status')` como alerta `success` y `session('error')` como alerta `danger`. Redirige con `->with('status', 'Compromiso guardado.')`.
- **Formatos** (`App\Support\Formatting`):
  - `Money::format($value, $currency = 'COP', $decimals = null, $fallback = '—')`: `$ 1.234.567` (espacio sin corte U+00A0), `-$ 1.234.567`, `US$ 1.234,57`.
  - `DateFormat::date()` → `15/10/2026`, `DateFormat::dateTime()` → `15/10/2026 14:05`, `DateFormat::iso()` para `datetime`; todo convertido a America/Bogota.
  - `FieldName::toKey('clientes[0][email]')` → `clientes.0.email`; `FieldName::toId()` → `clientes-0-email`. Con valor, `toId('clientes[]', 'C-001')` → `clientes-C-001`; si el valor trae caracteres que no sirven en un id, se limpia y se le agrega su `crc32b` para que `C900.123` y `C900-123` no compartan id.
  - Úsalos también en exportaciones, para que pantalla y archivo coincidan.
- **Estilos en módulos:** Tailwind escanea las vistas de los módulos (`@source` en `resources/css/app.css`). Nada de `<style>`, CSS propio ni colores fijos; los colores salen de `--brand-*` a través de los componentes. Si falta un componente, se pide.
- **Enums en formularios:** `<x-ui.select name="estado" label="Estado" :options="ClientStatus::options()" :value="$estado" />`.

---

## 9. Navegación

Cada módulo agrega sus entradas desde `navigation()`; el layout las lee por usuario.

**`NavItem`** (`App\Support\Navigation\NavItem`, `final readonly`):

| Campo | Tipo | Por defecto | Uso |
|---|---|---|---|
| `key` | `string` | obligatorio | Único, p. ej. `cartera.tablero`. |
| `label` | `string` | obligatorio | Texto en español, tipo oración. |
| `route` | `string` | obligatorio | Nombre de ruta. |
| `icon` | `?string` | `null` | Nombre del set de íconos de Diseño. |
| `permission` | `?string` | `null` | Habilidad del Gate (la responde Spatie). |
| `group` | `string` | `'General'` | Sección de la barra lateral. |
| `order` | `int` | `100` | Menor va primero; empates por etiqueta. |
| `activePattern` | `?string` | `null` | Patrón de `routeIs()`; por defecto, `route`. |
| `mobile` | `bool` | `false` | También en la barra inferior del celular. |
| `badge` | `?Closure(): ?int` | `null` | Contador junto a la etiqueta; `null` lo oculta. |

Métodos: `url()`, `isActive(?Request $request = null)` y `badgeCount()`.

**`NavRegistry`** (singleton):

- `add(NavItem ...$items)` es atómico: una llave repetida lanza `InvalidArgumentException` y no agrega ninguna.
- `has(string $key)`, `all()` (por `order` y luego `label`).
- `visibleTo(?Authenticatable $user)`: los invitados no ven nada; se omiten las entradas cuya ruta no existe y las que el Gate niega.
- `groupedFor($user)`: grupos en el orden de su primera entrada. `mobileFor($user)`: solo `mobile: true`.
- `menuFor($user)`: `['groups' => groupedFor(), 'mobile' => mobileFor()]` con una sola pasada de `visibleTo()`; el layout lo usa para revisar cada ruta y cada permiso una vez por página.

El layout ejecuta cada `badge` una vez por página: que sea una consulta barata y devuelva `null` cuando no hay nada que contar (un `0` sí se muestra).

```php
// use App\Modules\Core\Contracts\MailboxReader;

protected function navigation(NavRegistry $navigation): void
{
    $navigation->add(
        new NavItem(key: 'cartera.tablero', label: 'Tablero', route: 'cartera.tablero',
            permission: 'cartera.view', group: 'Cartera', order: 10, mobile: true),
        new NavItem(key: 'cartera.respuestas', label: 'Respuestas de clientes', route: 'cartera.respuestas',
            permission: 'cartera.view', group: 'Cartera', order: 20,
            badge: fn (): ?int => app(MailboxReader::class)->unreadCount() ?: null),
    );
}
```

---

## 10. Tareas programadas y colas

- **Gancho:** cada módulo programa sus tareas en `schedule(Schedule $schedule)` de su proveedor. Nunca en `routes/console.php`.
- **Base** (`routes/console.php`): `queue:work --stop-when-empty --tries=3 --max-time=50` cada minuto, `withoutOverlapping(5)` y `runInBackground()`; `queue:prune-failed --hours=720` diario. El candado vence a los 5 minutos (el worker sale a los 50 segundos), así que un worker que el hosting mate deja la cola parada minutos, no un día.
- **Cron de cPanel** (una sola línea, la configura el despliegue):

  ```
  * * * * * cd ~/<carpeta-app> && php artisan schedule:run >> /dev/null 2>&1
  ```

- **Zona horaria:** `America/Bogota` (`APP_TIMEZONE`), así que `dailyAt('07:00')` es hora de Colombia.
- **Colas:** driver `database`. El hosting no tiene supervisor: la cola se vacía con la tarea de cada minuto. Mantén cada job corto (segundos) y parte el trabajo largo en varios jobs; usa `withoutOverlapping()` en tareas que no deben solaparse, con minutos de vencimiento acordes a lo que dura la tarea (`withoutOverlapping(10)`): por defecto el candado dura 24 horas si el proceso muere sin soltarlo.
- En pruebas, `QUEUE_CONNECTION=sync`: los jobs corren en el momento.

---

## 11. Roles, permisos y seeders

- **Roles:** los valores de `Role` (§6.14), que siguen A.5. Úsalos por el enum, no como texto suelto.
- **Permisos:** nombre `<clave-del-módulo>.<acción>`, p. ej. `cartera.view`, `cobro.send`, `comisiones.approve`. Se usan con `can:` en rutas, `$this->authorize()`, `@can` y `NavItem::$permission`.
- **Spatie sin equipos** (`'teams' => false` en `config/permission.php`): un rol es global al usuario. El acceso por empresa (SGX, SDC o ambas) es responsabilidad de Core y llega a los módulos por `CurrentCompany` y la regla de alcance.
- No hay un `Gate::before` para `admin`: si un rol debe poder algo, recibe el permiso en el seeder.

**Seeder de permisos.** Cada módulo tiene `App\Modules\<Modulo>\Database\Seeders\<Modulo>PermissionSeeder`, que extiende `App\Support\Modules\PermissionSeeder` y devuelve `permiso => roles`:

```php
use App\Modules\Core\Contracts\Enums\Role;

public function permissions(): array
{
    return [
        'cobro.view' => [Role::PortfolioManager, Role::PortfolioAnalyst],
        'cobro.send' => [Role::PortfolioAnalyst],
    ];
}
```

- Crea permisos y roles solo si faltan (guard `web`): correrlo dos veces es seguro.
- Acepta nombres de rol como `string` o como enum.

**Descubrimiento.** `DatabaseSeeder` recorre los módulos (Core primero) y llama cada `<Modulo>PermissionSeeder` que exista; **solo con `APP_ENV=local`** llama después cada `<Modulo>DemoSeeder`. Los seeders de demostración usan factories y datos ficticios, nunca datos reales.

```bash
php artisan migrate --seed       # local
php artisan db:seed --force      # producción: solo permisos
```

`deploy/deploy.sh` corre `php artisan db:seed --force` después de las migraciones: los permisos nuevos llegan a producción junto con el código que los revisa. Por eso un `<Modulo>PermissionSeeder` debe seguir siendo idempotente y no sembrar nada distinto de permisos y roles.

---

## 12. Base de datos

### 12.1 Prefijos de migraciones

Son los de la última columna de §3.4 (C.6): de `2026_10_01_*` (Core) a `2026_10_08_*` (Comisiones). Están en `config/modules.php` (`migration_prefixes`) y los vigila `tests/Architecture/ModulesTest.php`. Ordena las tuyas con el sufijo: `2026_10_03_000001_create_…`, `2026_10_03_000002_…`. La base usa `0001_01_01_*`.

### 12.2 Tablas de la base

| Migración | Tablas |
|---|---|
| `0001_01_01_000000` | `users`, `password_reset_tokens`, `sessions` |
| `0001_01_01_000001` | `cache`, `cache_locks` |
| `0001_01_01_000002` | `jobs`, `job_batches`, `failed_jobs` |
| `0001_01_01_000003` | `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions` |

**`users`** es la tabla de identidad compartida y su dueña es la base:

| Columna | Tipo |
|---|---|
| `id` | id |
| `name` | string |
| `email` | string, único (sin espacios y en minúsculas) |
| `microsoft_id` | string, nullable, único (oid de Entra ID) |
| `is_active` | boolean, por defecto `true` |
| `email_verified_at` | timestamp nullable |
| `password` | string nullable (no hay contraseñas propias) |
| `remember_token` | string nullable |
| `created_at`, `updated_at` | timestamps |
| `deleted_at` | borrado lógico |

`App\Models\User` usa `HasFactory`, `HasRoles` (Spatie), `Notifiable` y `SoftDeletes`; `fillable`: `name`, `email`, `microsoft_id`, `is_active`. `UserFactory` crea usuarios sin contraseña y con `microsoft_id`; estados `inactive()` y `withoutMicrosoftId()`.

**Crear usuarios.** `email` y `microsoft_id` son únicos aun con borrado lógico: una persona es una sola fila y la auditoría sigue apuntando a ella. El modelo guarda el correo sin espacios y en minúsculas (`User::normalizeEmail()`), así que `Ana@Segurex.com` y `ana@segurex.com` son la misma persona. Por eso, todo camino que crea usuarios (el primer ingreso con Microsoft, la pantalla de equipo, un seeder) usa `restoreOrCreate()` (o `createOrRestore()`) con el correo normalizado, nunca `create()`: si la persona fue borrada, la restaura en la misma fila en vez de fallar por el índice único.

```php
$user = User::withTrashed()->restoreOrCreate(
    ['email' => User::normalizeEmail($email)],
    ['name' => $name, 'microsoft_id' => $oid],
);
```

### 12.3 Quién toca qué tabla

- **Core** puede agregar columnas a `users` con sus propias migraciones `2026_10_01_*`, y agrega relaciones con `User::resolveRelationUsing()` desde `bootModule()`, sin editar la clase.
- **Los demás módulos** no dependen de los detalles de `App\Models\User`: guardan el id (`foreignId('created_by')->constrained('users')`) y entre módulos se pasan `UserRef` o el id. Autorizar con el usuario autenticado (`$user->can('cobro.send')`) sí está bien.
- **Ningún módulo altera tablas de otro.** Si necesita un dato extra, crea una tabla propia con llave foránea.
- **Empresa:** guárdala como el valor de `CompanyCode` (`$table->string('company', 3)` con cast `'company' => CompanyCode::class`).
- **Recomendación:** nombra tus tablas con la clave del módulo (`cobro_envios`, `bancos_movimientos`) para que dos módulos no choquen. Core, dueño de las entidades compartidas, puede usar nombres sin prefijo.
- **Modelos estrictos** fuera de producción (`Model::shouldBeStrict()`): la carga diferida (N+1), los atributos descartados por asignación masiva y los atributos inexistentes lanzan excepción. Carga relaciones con `with()` y declara `fillable`.
- **Fechas inmutables:** `Date::use(CarbonImmutable::class)`, así que los casts de fecha y `now()` devuelven `CarbonImmutable`, como los contratos.
- En producción, `migrate:fresh`, `db:wipe` y similares están prohibidos (`DB::prohibitDestructiveCommands()`).

### 12.4 Seeders y factories de un módulo

| Carpeta | Namespace |
|---|---|
| `app/Modules/<Modulo>/database/seeders/` | `App\Modules\<Modulo>\Database\Seeders` |
| `app/Modules/<Modulo>/database/factories/` | `App\Modules\<Modulo>\Database\Factories` |

`composer.json` ya trae una entrada PSR-4 explícita para cada una de las 16 carpetas: el directorio es `database` en minúscula y en Linux (CI y el servidor) la regla general `App\` → `app/` no lo encontraría. Un modelo del módulo enlaza su factory con `#[UseFactory(ClienteFactory::class)]`, porque Laravel solo la adivina en `Database\Factories`.

---

## 13. Textos y traducciones

- **Namespace del módulo:** `resources/lang/es/<archivo>.php` se lee con `__('<clave>::<archivo>.<llave>')`, p. ej. `__('cobro::mensajes.enviado')`. Las vistas igual: `view('cobro::lotes.index')`.
- **`lang/es/`** (base) traduce el framework: `auth`, `pagination`, `passwords` y `validation`. `validation.php` ya trae nombres de atributos comunes (`email` → «correo electrónico», `amount` → «valor»…). Para tus campos usa `attributes()` en el FormRequest o tus propias traducciones; no edites `lang/es`.
- **Idioma:** `APP_LOCALE=es`, `APP_FALLBACK_LOCALE=es`, `APP_FAKER_LOCALE=es_CO`.
- **Reglas de texto:** interfaz en español, tuteando y en tipo oración, sin mayúsculas sostenidas. Código, identificadores y comentarios en inglés. Los enums traen su etiqueta en `label()`.
- Los mensajes de las excepciones de contrato son para logs; al usuario se le muestra un texto propio del módulo.

---

## 14. Pruebas y calidad

### 14.1 Dónde van

| Qué | Carpeta |
|---|---|
| Pruebas de un módulo | `tests/Modules/<Modulo>/Unit` y `tests/Modules/<Modulo>/Feature` |
| Diseño | `tests/Feature/Design` |
| Base | `tests/Unit`, `tests/Feature`, `tests/Architecture` |

Suites de `phpunit.xml`: `Unit`, `Feature`, `Modules` y `Architecture`. Todas extienden `Tests\TestCase` (`tests/Pest.php`).

### 14.2 Qué hace `Tests\TestCase`

- `LazilyRefreshDatabase`: migra la base en memoria solo cuando una prueba la toca y envuelve cada prueba en una transacción.
- `Http::preventStrayRequests()`: cualquier llamada HTTP sin `Http::fake()` falla. Ninguna prueba toca Graph, SAP ni bancos.
- `withoutVite()`: las vistas se pintan sin `public/build`.

`phpunit.xml` fija SQLite en memoria, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=array`, `MAIL_MAILER=array`, y fuerza vacíos `MICROSOFT_CLIENT_SECRET` y `GRAPH_CLIENT_SECRET`, aunque `.env` tenga credenciales. Para Graph usa `Http::fake()` con respuestas grabadas.

### 14.3 Comandos

| Comando | Hace |
|---|---|
| `composer format` | Pint corrige el estilo. |
| `composer lint` | `pint --test` y Larastan nivel 6 (`phpstan.neon`: `app/`, `bootstrap/app.php`, `config/`, `database/`, `routes/`, `tests/Fakes/`). |
| `composer test` | `config:clear` y `php artisan test` (todas las suites). |
| `php artisan test tests/Modules/Cobro` | Solo tu módulo, mientras trabajas. |

Larastan nivel 6 pide arreglos e iterables tipados (`list<Invoice>`, `array<string, float>`). Larastan rechaza `env()` fuera de `config/` y `app/Modules/*/config/` (`configDirectories` en `phpstan.neon`): el resto lee `config('<clave>.*')`, porque en producción la configuración queda cacheada.

### 14.4 Pruebas de arquitectura

`tests/Architecture/ContractsTest.php`:

- Los contratos no usan `Illuminate\Database`, `Illuminate\Http` ni `Illuminate\Support\Facades`, ni nada del resto de la app.
- Las clases de `Data` son `final` y `readonly`; los enums, de tipo `string`; las excepciones extienden `RuntimeException`.
- Los nueve contratos de C.4 son interfaces.

`tests/Architecture/ModulesTest.php`:

- Core no usa ningún otro módulo.
- Cada módulo usa a otro solo por `App\Modules\Core\Contracts`.
- Cada módulo tiene su proveedor y extiende `ModuleServiceProvider`.
- Las migraciones de cada módulo empiezan con su prefijo.
- `App\Support` no usa módulos, salvo los contratos de Core.
- En `App` no quedan `dd`, `dump`, `ray`, `var_dump` ni `die`.

### 14.5 CI (`.github/workflows/ci.yml`)

Corre en cada PR y en cada push a `main`; ningún PR se fusiona en rojo.

| Job | Qué hace |
|---|---|
| Lint | PHP 8.3, `composer install`, `composer lint`. |
| Tests | PHP 8.3 con MySQL 8.0 como servicio; copia `.env.example`, genera la llave y corre `composer test` con `DB_*` de MySQL. |
| Assets | Node 22, `npm ci`, `npm run build`. Solo comprueba que compila; nunca se sube. |
| Compilados (solo en PR) | Falla si el PR cambia algo en `public/build/` (`git diff` contra la rama destino) y su rama no empieza con `deploy/` (§16). |

En tu equipo las pruebas corren en SQLite en memoria; CI las corre en MySQL 8. Evita SQL propio de un motor y prueba en CI lo que dependa de él.

---

## 15. Configuración y variables de entorno

Los secretos viven solo en `.env` (en el servidor, con `chmod 600`). `.env.example` trae todas las variables con comentarios. En producción, después de editar `.env` corre `php artisan config:cache` (o el despliegue completo): la configuración queda cacheada.

| Variable | La usa | Nota |
|---|---|---|
| `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL` | Base | `APP_NAME` por defecto «Gestor de cartera». `APP_DEBUG=false` en producción. |
| `APP_TIMEZONE` | Base | `America/Bogota`. |
| `APP_LOCALE`, `APP_FALLBACK_LOCALE` | Base | `es`. |
| `APP_FAKER_LOCALE` | Factories | `es_CO`. |
| `LOG_CHANNEL`, `LOG_STACK`, `LOG_DAILY_DAYS`, `LOG_LEVEL` | Base | `LOG_STACK=daily` y `LOG_DAILY_DAYS=14`; son también los valores por defecto de `config/logging.php`. |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Base | `sqlite` en local (`database/database.sqlite`); `mysql` en producción. |
| `DB_TIMEZONE` | Base | Opcional; no está en `.env.example` y vale `-05:00` por defecto. Zona de la sesión de MySQL y MariaDB (`config/database.php`): las conversiones de `TIMESTAMP` y `NOW()`/`useCurrent()` siguen la hora de Colombia (sin horario de verano), no la del servidor de GoDaddy. SQLite la ignora. |
| `SESSION_DRIVER`, `SESSION_LIFETIME` | Base | `database`; 120 minutos. |
| `SESSION_SECURE_COOKIE` | Base | `true` en producción (HTTPS). |
| `QUEUE_CONNECTION` | Base | `database`. |
| `CACHE_STORE` | Base | `database`. |
| `MAIL_*` | Framework | Solo correo del framework (por defecto `log`). El correo de negocio va por Graph (`MailSender`). |
| `MICROSOFT_TENANT_ID`, `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET`, `MICROSOFT_REDIRECT_URI` | Core | `config('services.microsoft')`: `tenant`, `client_id`, `client_secret`, `redirect`. El tenant limita el ingreso al directorio de la empresa: nunca `common`. Sin `MICROSOFT_TENANT_ID`, el driver no se construye (`RuntimeException`); la app arranca y `/up` responde igual. La URI apunta a `/auth/microsoft/callback`. |
| `GRAPH_TENANT_ID`, `GRAPH_CLIENT_ID`, `GRAPH_CLIENT_SECRET` | Cobro | `config('cobro.graph.*')`. Registro aparte del de ingreso, solo de aplicación. `Mail.Send` y `Mail.ReadWrite` (crear el borrador permite guardar `internetMessageId` y `conversationId`) se conceden con [RBAC for Applications de Exchange Online](https://learn.microsoft.com/exchange/permissions-exo/application-rbac), limitados a los dos buzones; nunca como permiso de todo el tenant en Entra ID. La lista exacta la confirma la sesión de Cobro. |
| `COBRO_MAILBOX_SGX`, `COBRO_MAILBOX_SDC` | Cobro | `config('cobro.mailboxes.SGX')` (por defecto `segurex.info@segurex.com`) y `config('cobro.mailboxes.SDC')` (por defecto `derivados.info@derivadoscol.com`). |
| `INGESTA_HMAC_KEYS` | Ingesta | `config('ingesta.hmac_keys')`: pares `id-llave:secreto` separados por comas, en texto; Ingesta los interpreta. |
| `INGESTA_HMAC_MAX_SKEW` | Ingesta | Segundos de diferencia de reloj aceptados; por defecto `300` (entero). |
| `INGESTA_ALERT_DEADLINE` | Ingesta | Hora límite de la carga diaria; por defecto `07:00`. |
| `INGESTA_ALERT_RECIPIENTS` | Ingesta | Correos separados por comas; `config('ingesta.alert_recipients')` los entrega como lista, sin espacios ni vacíos. |
| `BANCOS_IMPORT_PATH` | Bancos | Carpeta de los extractos; por defecto `bancos/entrada`. |
| `BANCOS_API_TOKEN` | Bancos | Token de Tesorería para `GET /api/recaudo`. |

**Rotar la llave HMAC del robot** sin cortar las cargas:

1. Agrega el par nuevo junto al viejo: `INGESTA_HMAC_KEYS=k2026a:<viejo>,k2026b:<nuevo>` y corre `php artisan config:cache`.
2. Cambia la llave del robot en su propio `.env` (`etl/.env`) para que firme con `k2026b`.
3. Cuando las cargas lleguen firmadas con la nueva, quita el par viejo y vuelve a cachear la configuración.

---

## 16. Despliegue

Se despliega de GitHub a cPanel (GoDaddy) con la skill `cpanel-deploy`. La primera instalación, el cron y el PR de compilados son de la sesión de Despliegue (Fase 3); los pasos remotos los ejecuta o autoriza el usuario.

**`public/build`.** Está en `.gitignore` y ningún módulo lo sube. El servidor no tiene Node, así que la sesión de despliegue compila (`npm ci && npm run build`) y lo agrega con `git add -f public/build` en un PR propio, desde una rama que empieza con `deploy/` (p. ej. `deploy/compilados-2026-10-15`), que el usuario fusiona antes de desplegar. CI rechaza cualquier otro PR que toque `public/build/` (§14.5). Como los módulos cambian vistas y clases de Tailwind, cada despliegue con cambios de interfaz necesita un compilado nuevo.

**`deploy/deploy.sh`** corre en el servidor por SSH, nunca desde un equipo ni desde un agente:

```bash
cd ~/<carpeta-app> && bash deploy/deploy.sh
```

1. `git fetch` y **se niega** a seguir si la rama upstream no tiene `public/build/manifest.json`.
2. `php artisan down --retry=60` (si ya existe `vendor/`); un `trap` garantiza `php artisan up` aunque algo falle.
3. `git merge --ff-only '@{upstream}'`: avanza exactamente al commit revisado en el paso 1, sin volver a hacer `fetch` (un `git pull` podría traer uno más nuevo sin compilados).
4. `composer install --no-dev --optimize-autoloader --no-interaction`.
5. `php artisan migrate --force`.
6. `php artisan db:seed --force`: sincroniza los permisos de cada módulo (§11).
7. `php artisan optimize` (cachea configuración, rutas, vistas y eventos).
8. `php artisan storage:link` si falta `public/storage`.
9. `php artisan up`.

Variables opcionales: `PHP` (el mismo binario del cron) y `COMPOSER_BIN` (p. ej. `"php ~/composer.phar"`). El script no compila assets ni contiene secretos.

**Servidor:**

- Document Root del dominio en cPanel: `<carpeta-app>/public`.
- Prueba de vida: `GET /up` responde 200 cuando la app arranca.
- Cron: la línea de §10.

---

## 17. Decisiones de la Fase 0a

| Decisión | Por qué |
|---|---|
| Un proveedor que **descubre** los módulos (`ModulesServiceProvider`) en vez de listarlos en `bootstrap/providers.php` | Nadie edita un archivo compartido para agregar un módulo, y `make:provider` reescribe esa lista. |
| `register()` y `boot()` `final` en `ModuleServiceProvider` | Todos los módulos cargan igual; solo cambian los ganchos. |
| `App\Models\User` compartido, de la base | Una sola identidad para Socialite, Spatie y la sesión. Core la extiende con migraciones propias y `resolveRelationUsing()`. |
| Spatie **sin equipos** | El acceso por empresa necesita reglas propias (SGX, SDC, ambas, vista de grupo) y vive en Core detrás de `CurrentCompany`. |
| Microsoft Graph con el **cliente HTTP de Laravel**, sin SDK | Menos dependencias y se prueba con `Http::fake()` y respuestas grabadas, con `preventStrayRequests()` de red. |
| Identificadores en inglés y glosario (§18) | Regla del proyecto: código en inglés, interfaz en español. El glosario evita traducciones distintas del mismo término. |
| Montos `float` para mostrar; cadenas decimales en las filas de ingesta | Las pantallas suman y formatean; Comisiones necesita exactitud y la toma de las cadenas. |
| Fechas inmutables y modelos estrictos | Evitan mutaciones accidentales y detectan N+1 y atributos perdidos en desarrollo. |
| `/public/build` ignorado | Si cada PR trajera el compilado, todos chocarían entre sí. |
| Adiciones `<x-ui.icon>` y `<x-ui.pagination>` | Varios componentes necesitan íconos, y `->links()` traería vistas de paginación ajenas al diseño. |
| Core en la raíz y la API de Bancos en `/api` | `/`, `/login` y `/equipo` son del núcleo; Tesorería ya espera `GET /api/recaudo`. |

---

## 18. Glosario

| Término de negocio | En el código |
|---|---|
| Cartera | `CarteraRepository`, `PortfolioTotals`, `Dataset::Receivables` (`cartera`) |
| Cliente | `ClientSummary`, `ClientDetail`, `clientId` |
| Factura (cuota) | `Invoice` |
| Compromiso de pago | `Commitment`, `CompromisoService`, `CommitmentStatus` |
| Gestión, bitácora | `Activity`, `NewActivity`, `GestionLogger`, `ActivityType` |
| Bitácora de auditoría | `AuditLogger` |
| Recaudo (bancos) | `DailyCollection`, `WeeklyCollection`, `RecaudoProvider` |
| Recaudos (comisiones) | `Dataset::Collections` (`recaudos`) |
| Asesor | `Advisor`, `advisorCode` (OSLP.SlpCode), `Role::SalesAdvisor` |
| Gestor responsable | `ClientSummary::$manager` (`UserRef`) |
| Cruce | `Dataset::Offsets` (`cruces`) |
| Lote | `IncomingBatch`, `batchId`; el segmento `{lote}` es un valor de `Dataset` |
| Carga | `LoadRecord`, `LoadSource`, `LoadStatus` |
| Corte, fecha de corte | `cutoffDate` |
| Instantánea | snapshot (cada carga aceptada) |
| Cupo | `ClientDetail::$creditLimit` |
| NIT | `ClientSummary::$taxId` |
| Empresa | `Company`, `CompanyCode`, `CurrentCompany` |
| Grupo (vista de grupo) | `null` en los contratos, `isGroup()`; `grupo` en el formulario del selector |
| Estado del cliente | `ClientStatus` |
| Días de gracia | `Company::$graceDays` |
| Días de mora | `Invoice::$daysOverdue`, `ClientSummary::$maxDaysOverdue` |
| Saldo, vencido | `balance`, `overdue` |
| Edades de la deuda | `AgingBuckets` |
| Condición de pago | `ClientDetail::$paymentTerms` |
| Contacto | `Contact` |
| Respuesta, rebote | `InboundMail` (`isBounce`) |
| Banco | `Bank` |
