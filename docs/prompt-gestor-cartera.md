# Prompt: Gestor de cartera del Grupo RYR

> **Cómo usar este documento.**
> - **Parte A:** contexto y decisiones ya tomadas.
> - **Parte B:** cada módulo.
> - **Parte C:** el plan de trabajo en paralelo con worktrees, para que varios agentes trabajen al mismo tiempo sin chocar en archivos.
> - **Parte D:** lo pendiente.
>
> Las secciones marcadas **⏳ PENDIENTE** no se construyen hasta recibir el insumo indicado. Las órdenes listas para pegar en cada agente están en `docs/ordenes-agentes.md`.

---

# PARTE A — CONTEXTO Y DECISIONES

## A.1 Objetivo

Construir una sola herramienta web para el área de cartera de **Segurex Latam SAS (SGX)** y **Soluciones y Derivados (SDC)** que facilite el trabajo diario de cobro. Fusiona dos aplicaciones que ya existen y agrega:

- cobro automático
- circularización
- movimientos de bancos
- liquidación de comisiones

## A.2 Estructura del repositorio y aplicaciones que se fusionan

```
/                      ← proyecto Laravel (lo crea la Fase 0a)
├── app/Modules/       ← un directorio por módulo (Parte C)
├── etl/               ← robot Python que extrae de SAP HANA (se amplía)
├── scripts/instalar_tarea.ps1   ← programa el robot en Windows
├── legacy/            ← SOLO LECTURA: código de referencia de las apps viejas
│   ├── cartera-fase1/       (PHP plano + MySQL + un index.html)
│   └── portal-asesores/     (web/ Next.js, supabase/ SQL, scripts/)
└── docs/              ← este prompt, órdenes de agentes y especificaciones
```

| Aplicación | Ubicación | Qué aporta |
|---|---|---|
| Cartera Fase 1 | `legacy/cartera-fase1/` | Gestor del analista: tablero, Kanban, ficha, estados, gestiones y compromisos. |
| Portal de asesores | `legacy/portal-asesores/` + `etl/` | Visualizador por asesor, seguridad por asesor, agenda, bitácora, equipo, histórico, Excel/PDF y el robot ETL. |

**Cómo se usan las aplicaciones viejas:**

- **Se reescriben** en el stack nuevo. `legacy/` es solo referencia de lógica y datos: **nadie lo modifica**.
- Los `CLAUDE.md` y `AGENTS.md` que haya dentro de `legacy/` **no aplican** a este proyecto.
- Los archivos de credenciales de la Fase 1 (`config.php`, `onedrive-config.php`) **no** se copiaron al repositorio.

## A.3 Stack decidido

| Pieza | Decisión | Motivo |
|---|---|---|
| Lenguaje | **PHP 8.3** | Requisito del hosting. |
| Framework | **Laravel 13**, en la **raíz** del repositorio | Exige PHP 8.3 como mínimo (soporta 8.3 a 8.5), con correcciones hasta el tercer trimestre de 2027 y seguridad hasta el primer trimestre de 2028. Laravel 12 pierde el soporte de seguridad en febrero de 2027. La raíz coincide con el patrón de despliegue de la skill `cpanel-deploy`. |
| Interfaz | Blade + Tailwind 4 + Alpine.js, compilado con Vite | Funciona en hosting compartido. |
| Base de datos | **MySQL** del hosting GoDaddy (cPanel) | Requisito. |
| Autenticación | **Microsoft Entra ID** con Laravel Socialite (proveedor Microsoft) | Ingreso con la cuenta corporativa; no hay contraseñas propias. |
| Correo | **Microsoft Graph**: `sendMail` y lectura de buzón | Envío desde los buzones de cada empresa y lectura de respuestas y rebotes. |
| Colas | Driver `database` + cron de cPanel cada minuto (`schedule:run`; `queue:work --stop-when-empty`) | El hosting compartido no tiene supervisor. |
| Roles | `spatie/laravel-permission` | Roles y permisos por empresa. |
| PDF / Excel | `barryvdh/laravel-dompdf` y `openspout/openspout` | Informes y exportaciones sin dependencias del sistema. |
| PWA | Un manifiesto para **toda la app**, sin datos sin conexión | Ver A.6. |
| Pruebas y calidad | Pest, Pint, Larastan (PHPStan); scripts `composer lint` y `composer test`. En local, SQLite en memoria (el equipo no tiene MySQL instalado); en CI, MySQL 8 | Un solo comando para cada agente. |
| CI | GitHub Actions con PHP 8.3 y MySQL | Ningún PR se fusiona en rojo. |
| Despliegue | GitHub → cPanel con la skill `cpanel-deploy` | Flujo ya probado. |
| Zona horaria | `America/Bogota` | Todas las tareas programadas. |

**Reglas sobre dependencias y compilados:**

- **Ninguna dependencia nueva se agrega fuera de la Fase 0a.** Si un módulo necesita una librería, se detiene y la pide.
- **`public/build` no se incluye en los commits de los módulos.** El servidor no tiene Node, así que el paso de despliegue compila y sube `public/build` en un commit propio sobre `main`. Si cada PR lo incluyera, todos chocarían entre sí.

## A.4 Datos de origen: SAP Business One sobre HANA

**SAP:**

- Es **Business One sobre HANA**, esquema `SEGUREX`; no es ECC ni S/4HANA.
- Las tablas `BSAD`, `BKPF` y `LFA1` citadas en la especificación de comisiones **no aplican**.

**Robot ETL:**

- El robot (`etl/`, Python) ya extrae la cartera y los asesores por la VPN Sophos. Usar la skill `robot-sap-hana`.
- **Cambio de destino:** hoy publica en Supabase/Postgres. Ahora publica en **Laravel por HTTPS**, sin abrir el MySQL remoto:
  - endpoint `POST /api/ingesta/{lote}`
  - firmado con HMAC y llave rotable
  - nuevo sink `etl/sinks/http_sink.py`
  - los sinks actuales quedan como respaldo
- **Consultas nuevas** (todas en `etl/queries/`):
  - `contactos_clientes.sql`: correo, teléfono y persona de contacto por cliente (tablas `OCRD` y `OCPR`). Reemplaza la carga manual de contactos.
  - `ventas.sql`, `recaudos.sql`, `cruces.sql`, `maestro_clientes.sql` y `maestro_vendedores.sql`: para comisiones (Parte B, módulo 7).

**Reglas de carga:**

- La carga manual del Excel de cartera queda **solo como respaldo**, para cuando el robot falla.
- Cada carga de cartera se guarda como **instantánea con fecha**; nunca se sobrescribe el histórico.

## A.5 Usuarios y roles

| Persona | Rol | Empresa | Qué hace |
|---|---|---|---|
| Miguel | `gerente_cartera` | SGX y SDC | Ve todo, usa el tablero gerencial y liquida comisiones. |
| María Paula | `analista_cartera` | SGX y SDC | Gestiona la cartera, ve todos los compromisos, lee las respuestas de los clientes y maneja cobros y circularización. |
| Asesores comerciales | `asesor` | Su empresa | Ven **solo su cartera** y crean compromisos de pago (en SGX). |
| Gerente comercial | `gerente_comercial` | Su empresa | Ve la cartera de los asesores de su equipo. |
| Tesorería | `tesoreria` (futuro) | — | Entrega los movimientos bancarios. |
| TI | `admin` | — | Usuarios, parámetros y buzones. |

**Reglas de acceso:**

- El usuario entra con Microsoft. La app lo empareja por correo con su rol y con su asesor de SAP (`OSLP.Email`, como hoy en el portal).
- Un usuario sin rol asignado ve una pantalla neutra que le pide solicitar acceso.
- Cada gestor ve solo los **clientes que tiene asignados**, salvo el gerente y la analista.

## A.6 Diseño y PWA

**Diseño:**

- Cargar la skill **`ryr-design`** antes de cualquier vista. Reglas que no se negocian:
  - Colores solo por las variables `--brand-*`; un mismo diseño sirve para SGX y SDC.
  - La marca sale de la **empresa activa del usuario**, no del dominio de su correo. Sin sesión, paleta neutra.
  - Tipo oración en toda la interfaz; nada en mayúsculas sostenidas.
  - Accesibilidad:
    - contraste AA y foco visible
    - respeto a `prefers-reduced-motion`
    - tamaños en rem, probados con la letra al 200 %
  - Mobile-first, con zonas táctiles de al menos 44 × 44.
  - Fuentes Figtree y Bricolage Grotesque, autoalojadas.
  - Textos de interfaz en español, tuteando; código y comentarios en inglés.
- Para gráficos y KPIs, cargar también la skill `dataviz`.
- El selector de empresa (SGX, SDC o Grupo) cambia la marca en vivo.

**PWA:**

- **Un solo manifiesto para toda la app.** Instalarla es opcional: el equipo de cartera puede ignorarla y los asesores la instalan en el celular.
- Al abrir la app instalada, cada usuario llega a su pantalla de inicio según su rol.
- El service worker guarda **solo** archivos estáticos (CSS, JS, fuentes, íconos) y una página «Sin conexión».
- **No guarda datos de cartera en el dispositivo:** el equipo marcó No al modo sin conexión, y además serían datos financieros guardados en un celular.

---

# PARTE B — MÓDULOS

Cada módulo vive en `app/Modules/<Modulo>/` (ver C.2). Las funciones salen de la revisión de la app Fase 1 que hizo el equipo de cartera el 2026-10-14.

## Módulo 0D — Interfaz base y PWA (`resources/` compartido)

1. **Tokens y fuentes:**
   - tokens de `ryr-design` en Tailwind 4
   - fuentes autoalojadas
   - modo claro y oscuro del sistema
2. **Layouts:** `<x-layouts.app>` (encabezado, barra lateral en escritorio y barra inferior en celular) y `<x-layouts.guest>`.
3. **Componentes del contrato C.5**, con una página de galería en `/dev/componentes` (solo en local).
4. Marca de la empresa activa inyectada en `<head>` y selector de empresa.
5. PWA según A.6: manifiesto, íconos de cada marca, service worker de estáticos y página «Sin conexión».

## Módulo 0N — Núcleo (`Core`)

1. Ingreso con Microsoft, roles y permisos, selección de empresa activa.
2. Pantalla **Equipo** (admin):
   - usuarios, con su rol y su empresa (SGX, SDC o ambas)
   - asesor de SAP vinculado
   - activar o desactivar
3. Entidades compartidas:
   - `Empresa`, `Cliente`, `Factura` (instantánea) y `CargaCartera`
   - `Asesor` y `Contacto`
   - `Gestion` (bitácora) y `CompromisoPago`
4. **Estados automáticos** del cliente, con **días de gracia por empresa** (parámetro):
   - SGX: la factura cuenta como vencida después de **15 días**.
   - SDC: después de **5 días**.
   - Estados: Al día, Por vencer, Vencida, En gestión, Compromiso, Incumplido, Pago recibido y Jurídico.
5. Un compromiso vencido sin pago pasa **solo** a «Incumplido» (tarea diaria).
6. Borrado lógico de usuarios, clientes y gestiones, con bitácora de quién y cuándo.
7. Bitácora de auditoría inmutable para todo el sistema.
8. Implementación real de los contratos de `Core/Contracts` (C.4) que le corresponden.

## Módulo 1 — Ingesta (`Ingesta`)

1. Endpoint firmado que recibe lotes del robot:
   - cartera, asesores y contactos
   - más adelante, ventas, recaudos y cruces
2. Validación, idempotencia por llave natural e instantánea diaria.
3. Historial de cargas: fecha de corte, origen (robot o manual), número de facturas, total y vencido.
4. Carga manual del Excel de cartera como respaldo, con fecha de corte y vista previa antes de confirmar.
5. Alerta por correo a TI y al gerente si el robot no publica antes de las 7:00 a. m.
6. Cambios en `etl/`:
   - `http_sink.py`
   - las consultas nuevas de A.4
   - la documentación del robot
   - ajuste de `scripts/instalar_tarea.ps1`

## Módulo 2 — Gestor de cartera (`Cartera`) · Miguel y María Paula

1. **Tablero** con:
   - total, vencido y % vencido
   - edades de la deuda (1-30, 31-60, 61-90 y más de 90 días)
   - los 10 clientes que más deben
   - compromisos de la semana
   - vencidos sin gestión
   - actividad reciente
2. Comparación SGX contra SDC en la vista Grupo.
3. **Kanban** con 8 columnas:
   - arrastrar entre columnas, con un botón alternativo para teclado y celular
   - ocultar o mostrar «Al día» y «Pago recibido»
4. Lista de clientes con búsqueda por nombre o NIT y orden por columna.
5. **Ficha del cliente**:
   - datos, cupo y facturas abiertas
   - contactos (traídos de SAP) y gestor responsable
   - bitácora, compromisos y cambio de estado
   - **respuestas de correo** del cliente, por el contrato `MailboxReader`
6. Registrar llamadas con su resultado, compromisos (fecha y valor), notas y descuentos ofrecidos.
7. **Compromisos de pago según la empresa:**
   - SGX: los crea el asesor (Módulo 3). María Paula los **ve todos** en una bandeja con filtros por asesor, fecha y estado.
   - SDC: los crea María Paula directamente, porque allá hay un solo asesor.
8. Exportar a Excel la lista y la ficha.

**Fuera de alcance**, porque el equipo marcó No:

- adjuntar documentos
- botones manuales de correo y WhatsApp
- modo sin conexión
- sincronización con OneDrive
- registro de pagos con plano SAP

## Módulo 3 — Visualizador del asesor (`Asesores`)

Migra el portal Next.js (`legacy/portal-asesores/web`).

1. El asesor ve **solo su cartera**: tablero, clientes, ficha, edades, tendencia e histórico.
2. **Compromisos de pago** (SGX): los crea, los edita y los marca cumplidos. María Paula los ve al instante.
3. Agenda de compromisos y bitácora de gestiones.
4. Equipo: el gerente comercial ve a sus asesores (hoy es el rol `gerente`, de `legacy/portal-asesores/supabase/06_rol_gerente.sql`).
5. Se conservan del portal: exportar a Excel, el reporte PDF del cliente y el botón de WhatsApp al cliente.
6. La seguridad por asesor, que hoy hacen las vistas de Supabase (`04_seguridad_vistas.sql`), se reimplementa con **policies y scopes de Eloquent**. Debe tener pruebas que demuestren que un asesor no puede leer la cartera de otro.

## Módulo 4 — Correo y cobro automático (`Cobro`) · solo SGX en esta fase

**Buzones de envío y lectura:**

| Empresa | Buzón |
|---|---|
| SGX | `segurex.info@…` (⏳ confirmar el dominio exacto con TI) |
| SDC | `derivados.info@derivadoscol.com` |

- Envío con Graph `sendMail` en nombre del buzón de la empresa. Lectura de la bandeja con Graph para traer respuestas y rebotes.
- Permisos de aplicación limitados **solo a esos dos buzones** (política de acceso de aplicaciones de Exchange o RBAC para aplicaciones). TI lo configura; la app no recibe acceso a todo el correo.
- Cada envío guarda `internetMessageId` y `conversationId`, para unir las respuestas con el cliente y con el envío.
- Implementa los contratos `MailSender` y `MailboxReader`.

**4.1 Cobro semanal de facturas vencidas**

1. Todos los **miércoles a las 5:00 p. m.** (hora de Colombia) se envía a cada cliente con facturas vencidas un correo con el detalle de esas facturas.
2. **Lista de exclusión:** pantalla con casillas para marcar los clientes a los que **no** se cobra, con motivo y una fecha opcional en que la exclusión vence. Tabla propia `cobro_exclusiones`.
3. Vista previa del lote antes del envío programado y botón para enviar una prueba al usuario.
4. Plantilla de correo editable por empresa, con la marca de la empresa.
5. **Informe de entrega:**
   - enviados, rebotados y sin dirección de correo
   - cliente por cliente, con el motivo del rebote leído del buzón
6. **Respuestas en la app:** las respuestas llegan a la ficha del cliente y a una bandeja «Respuestas de clientes» para la analista, marcadas como leídas o pendientes.

**4.2 Circularización mensual de cartera al cierre** — ⏳ PENDIENTE

1. Una vez al mes, al cierre, se envía a cada cliente el estado de su cartera.
2. **Formato:** ⏳ pendiente. El usuario enviará el informe que hoy se manda a los clientes y se debe replicar **tal cual**.
3. Usa la misma lista de exclusión, el mismo informe de entrega y la misma bandeja de respuestas de 4.1.
4. Mientras llega el formato, en la Fase 1 se construye solo el motor (programación, lote, envío e informe) con una plantilla provisional marcada como borrador.

## Módulo 5 — Movimientos de bancos y recaudo (`Bancos`)

**Flujo actual:**

- Tesorería descarga los movimientos de los bancos y hoy los modifica a mano para dos usos: cargarlos a SAP y enviarlos a cartera.
- Los débitos son de Tesorería; los **créditos** son de cartera.

1. **Importador** con un lector por banco: **Banco de Bogotá, Davivienda, Bancolombia y Miami**.
   - detecta las columnas
   - toma solo los créditos
   - no duplica movimientos ya cargados
2. **Fuente:**
   - **Por ahora:** archivos dejados en una carpeta o subidos en la app.
   - **Más adelante:** la app de Tesorería (en construcción) los envía por API.
   - El contrato de la API se define **ya** con el mismo formato que el lector de archivos, para que la app de cartera no cambie cuando llegue.
3. Carga **diaria, a día vencido**, e histórico anual.
4. Pestaña **Movimientos por banco**, una por cada banco, con filtros por fecha, valor y estado.
5. Al aplicar el pago en SAP, la analista registra en el movimiento el **número de recibo de caja**. Estados: pendiente, aplicado o ignorado.
6. **Informe de recaudo diario:** se calcula solo, como la suma de los créditos de todos los bancos del día anterior. Reemplaza el informe que hoy llena María Paula a mano. ⏳ Pedir a María Paula el formato actual.
7. **Acumulado semanal de ingresos** para revisión de cartera, y un informe para gerencia. ⏳ Revisar con Tesorería cómo lo hacen hoy.
8. **Conciliación** — ⏳ PENDIENTE: el usuario enviará el archivo de conciliación. Hasta entonces no se construye la sugerencia de cliente por movimiento.
9. **Flujo de caja:**
   - Es de Tesorería y **no** se construye aquí.
   - La app expone el recaudo diario por una API de solo lectura (`GET /api/recaudo?desde=&hasta=`), para que Tesorería lo use en su flujo.
   - Implementa el contrato `RecaudoProvider`.

## Módulo 6 — Tablero gerencial (`Gerencia`) · Miguel

1. Indicadores consolidados del grupo y por empresa:
   - tendencia mensual
   - rotación de cartera
   - cumplimiento de compromisos por asesor
2. Resultados del cobro semanal y de la circularización: entregados, rebotados y respuestas.
3. Recaudo diario y semanal desde el Módulo 5.

## Módulo 7 — Liquidación de comisiones comerciales (`Comisiones`) · solo SGX

La especificación completa está en `docs/especificaciones/liquidacion-comisiones.md` y **es la fuente de verdad** de reglas, pantallas, controles y casos de prueba. Este prompt solo ajusta lo que ya está decidido.

1. **Origen de los datos:**
   - Los reportes 1, 2, 4, 5 y 6 de la Parte B de esa especificación **no** se entregan como CSV con archivo de control.
   - Los extrae el robot ETL con consultas a HANA y los publica por el endpoint de Ingesta.
   - El Reporte 3 (cartera) ya existe.
2. **Punto 14.1 de esa especificación:** resuelto. Es SAP Business One sobre HANA, y la extracción la hace el robot ETL.
3. **Roles del módulo:**
   - analista de liquidación (Miguel)
   - aprobador (⏳ pendiente)
   - consulta
   - administrador de parámetros
   - **Segregación de funciones:** el aprobador debe ser una persona distinta.
4. **Pruebas:** los casos del Anexo 2 de la especificación son **criterios de aceptación**. Van como pruebas automatizadas de las reglas de cálculo.
5. **Puntos 14.2 a 14.7:** siguen abiertos (Parte D). Mientras se resuelven, cada uno se implementa como **parámetro**, con el comportamiento actual del Excel por defecto.

---

# PARTE C — PLAN DE TRABAJO EN PARALELO

## C.1 Principio

- Cada agente trabaja en **su propia sesión con worktree** y solo toca **los archivos que le pertenecen**.
- Los archivos compartidos los crea **una sola vez** la Fase 0a; después solo los cambia su dueño.
- Así los PR de una misma fase no tienen conflictos entre sí y se pueden fusionar en cualquier orden.

## C.2 Estructura que evita choques

```
app/Modules/<Modulo>/
  Providers/<Modulo>ServiceProvider.php   ← registra todo lo del módulo
  Domain/            ← entidades y reglas (sin Laravel)
  Application/       ← casos de uso
  Infrastructure/    ← Eloquent, Graph, lectores de archivos
  Http/Controllers/  Http/Requests/
  routes/web.php  routes/api.php
  database/migrations/  database/seeders/
  resources/views/   ← vistas con namespace del módulo
  resources/lang/es/
  config/<modulo>.php
tests/Modules/<Modulo>/
```

| Punto de choque típico | Cómo se evita |
|---|---|
| `bootstrap/providers.php` | La Fase 0a crea un cargador que **descubre** los `*ServiceProvider` de `app/Modules/*`. Nadie edita la lista. |
| `routes/web.php` y `routes/api.php` | Cada proveedor carga sus propias rutas, con prefijo y nombre del módulo (`cartera.*`, `cobro.*`). |
| Menú de navegación | La Fase 0a crea `App\Support\Navigation\NavRegistry`; cada módulo registra sus entradas desde su proveedor. |
| Tareas programadas (`routes/console.php`) | Cada proveedor registra su programación con `callAfterResolving(Schedule::class, …)`. |
| Migraciones | Cada módulo carga las suyas desde su carpeta, con un prefijo de fecha reservado (C.6). Ningún módulo altera tablas de otro: si necesita un dato extra, crea una tabla propia con llave foránea. |
| `composer.json`, `package.json` y sus lockfiles | Solo la Fase 0a. |
| `resources/css`, `resources/js`, layouts, componentes y `public/` | Solo la Fase 0b de diseño. Tailwind 4 escanea `app/Modules/**/resources/views` con `@source`. |
| `public/build` | Nadie lo incluye en un commit; lo compila y sube solo el paso de despliegue. |
| Permisos y roles | Cada módulo declara sus permisos en un seeder propio (`<Modulo>PermissionSeeder`), que el `DatabaseSeeder` descubre. |
| Traducciones | Namespace por módulo (`__('cobro::mensajes.enviado')`). |
| `.env.example` | La Fase 0a incluye **todas** las variables previstas, con comentarios. |
| Contratos entre módulos | Viven en `app/Modules/Core/Contracts/` y se congelan al cerrar la Fase 0a. Un cambio va en un PR aparte, coordinado con el usuario. |

**Regla de dependencias entre módulos:** un módulo solo usa a otro por las **interfaces** de `Core/Contracts`. Nunca importa clases internas de otro módulo.

## C.3 Fases, sesiones y modelos

En la app de escritorio, el modelo se elige en el selector junto al botón de enviar, y el esfuerzo con **Ctrl+Shift+E**.

### Fase 0a — Cimientos (una sesión; se fusiona antes de todo lo demás)

| Sesión | Modelo | Esfuerzo | Archivos que le pertenecen |
|---|---|---|---|
| Cimientos | Opus 5.5 | xhigh | Todo el proyecto Laravel, excepto `etl/`, `legacy/` y `docs/especificaciones/` |

1. Proyecto Laravel 13 en la raíz del repositorio, con PHP 8.3 y MySQL. `composer create-project` exige una carpeta vacía: crearlo en una carpeta temporal y mover el contenido sin pisar `etl/`, `legacy/`, `docs/`, `scripts/`, `CLAUDE.md` ni `README.md`.
2. Todas las dependencias de A.3 instaladas y configuradas; `.env.example` completo.
3. Cargador de módulos, `NavRegistry` y registro de la programación y de los seeders (tabla C.2).
4. **Contratos** en `app/Modules/Core/Contracts/` (C.4) y sus **implementaciones falsas** en `tests/Fakes/`.
5. **Componentes del contrato C.5** como **stubs** funcionales sin estilo, con sus props definitivas, y los layouts mínimos.
6. Esqueleto vacío de cada módulo (carpetas y `ServiceProvider` registrado) para que todos arranquen iguales.
7. Pest, Pint, Larastan; scripts `composer lint` y `composer test`; CI en `.github/workflows/ci.yml`.
8. Ruta `/dev/componentes` (solo en local) que muestra la vista `design.gallery`.
9. `docs/arquitectura.md` con las reglas de C.2 y C.4, y actualización de `CLAUDE.md` si hace falta.
10. `deploy/deploy.sh` según la skill `cpanel-deploy`, sin ejecutar nada remoto.

### Fase 0b — Diseño y núcleo (dos sesiones en paralelo)

| Sesión | Modelo | Esfuerzo | Archivos que le pertenecen |
|---|---|---|---|
| Diseño | Opus 5.5 | high | `resources/css/**`, `resources/js/**`, `resources/views/layouts/**`, `resources/views/components/**`, `resources/views/design/**`, `app/View/Components/**`, `public/fonts/**`, `public/icons/**`, `public/manifest.webmanifest`, `public/sw.js`, `public/offline.html`, `tests/Feature/Design/**` |
| Núcleo | Opus 5.5 | xhigh | `app/Modules/Core/**` (sin cambiar los contratos existentes), `tests/Modules/Core/**` |

### Fase 1 — Módulos (seis sesiones en paralelo)

| Sesión | Módulo | Modelo | Esfuerzo | Archivos que le pertenecen |
|---|---|---|---|---|
| Ingesta | 1 | Opus 5.5 | high | `app/Modules/Ingesta/**`, `tests/Modules/Ingesta/**`, `etl/**`, `scripts/instalar_tarea.ps1` |
| Cartera | 2 | Sonnet 5.5 | high | `app/Modules/Cartera/**`, `tests/Modules/Cartera/**` |
| Asesores | 3 | Sonnet 5.5 | high | `app/Modules/Asesores/**`, `tests/Modules/Asesores/**` |
| Cobro | 4.1 + motor de 4.2 | Opus 5.5 | high | `app/Modules/Cobro/**`, `tests/Modules/Cobro/**` |
| Bancos | 5 (sin 5.8) | Sonnet 5.5 | high | `app/Modules/Bancos/**`, `tests/Modules/Bancos/**` |
| Comisiones: cálculo | 7 (dominio y reglas) | Opus 5.5 (o Fable 5.1) | xhigh | `app/Modules/Comisiones/Domain/**`, `app/Modules/Comisiones/Application/**`, `tests/Modules/Comisiones/**` |

- Todos usan las implementaciones falsas de `tests/Fakes/` mientras el módulo real no esté fusionado.
- El robot ETL (`etl/**`) pertenece **solo** a Ingesta, **incluidas** las consultas de comisiones. El agente de comisiones no toca `etl/`.

### Fase 2 — Módulos que dependen de otros

| Sesión | Módulo | Modelo | Esfuerzo | Archivos | Se abre cuando |
|---|---|---|---|---|---|
| Gerencia | 6 | Sonnet 5.5 | high | `app/Modules/Gerencia/**`, `tests/Modules/Gerencia/**` | Cartera, Cobro y Bancos están en `main` |
| Comisiones: interfaz | 7 (pantallas, PDF, cierre, auditoría) | Opus 5.5 | high | `app/Modules/Comisiones/{Http,Infrastructure,Providers,routes,resources,database,config}/**`, `tests/Modules/Comisiones/Feature/**` | Comisiones: cálculo e Ingesta están en `main` |
| Circularización | 4.2 con el formato real | Sonnet 5.5 | high | `app/Modules/Cobro/Circularizacion/**`, `app/Modules/Cobro/resources/views/circularizacion/**`, `tests/Modules/Cobro/Circularizacion/**` | Cobro está en `main` y llegó el ⏳ formato |
| Conciliación | 5.8 | Sonnet 5.5 | high | `app/Modules/Bancos/Conciliacion/**`, `app/Modules/Bancos/resources/views/conciliacion/**`, `tests/Modules/Bancos/Conciliacion/**` | Bancos está en `main` y llegó el ⏳ archivo |

### Fase 3 — Integración y salida a producción (secuencial)

| Sesión | Modelo | Esfuerzo | Qué hace |
|---|---|---|---|
| Migración de datos | Opus 5.5 | high | Importa el histórico desde Supabase y desde la base de Cartera Fase 1: gestiones, compromisos, estados manuales y usuarios. Comandos Artisan idempotentes en `app/Modules/Core/Console/Migracion/**`. |
| Revisión de diseño y calidad | Opus 5.5 | high | Recorre la app por rol con ambas marcas, en claro y oscuro, a 375 px, 768 px y en escritorio, y con la letra al 200 %. Exige Lighthouse en celular de al menos 90 en rendimiento y accesibilidad. Corrige solo lo de diseño y reporta el resto. |
| Despliegue | Opus 5.5 | high | Primera instalación en GoDaddy con la skill `cpanel-deploy`, cron de cPanel, compilado y commit de `public/build`. Los pasos remotos los ejecuta o autoriza el usuario. Al final, plan de apagado de Cartera Fase 1 y del portal Next.js. |

## C.4 Contratos de `Core/Contracts` (los crea la Fase 0a)

| Contrato | Lo implementa | Para qué |
|---|---|---|
| `CurrentCompany` | Núcleo | Empresa activa, sus días de gracia y su paleta de marca. |
| `CarteraRepository` | Núcleo | Consultas de clientes, facturas e instantáneas, con el alcance del usuario. |
| `CompromisoService` | Núcleo | Crear, actualizar y listar compromisos de pago. |
| `GestionLogger` | Núcleo | Escribir en la bitácora. |
| `AuditLogger` | Núcleo | Bitácora de auditoría inmutable. |
| `IngestaSink` | Ingesta | Recibir lotes del robot y registrar cargas. |
| `MailSender` | Cobro | Enviar correo por Graph desde el buzón de una empresa. |
| `MailboxReader` | Cobro | Leer respuestas y rebotes y unirlos al cliente. |
| `RecaudoProvider` | Bancos | Recaudo diario y semanal por empresa. |

## C.5 Contrato de componentes Blade (los crea la Fase 0a como stubs; los diseña la Fase 0b)

`<x-layouts.app>`, `<x-layouts.guest>`, `<x-ui.page-header>`, `<x-ui.card>`, `<x-ui.stat>`, `<x-ui.chip>`, `<x-ui.button>`, `<x-ui.link-button>`, `<x-ui.table>`, `<x-ui.empty>`, `<x-ui.alert>`, `<x-ui.input>`, `<x-ui.select>`, `<x-ui.checkbox>`, `<x-ui.textarea>`, `<x-ui.modal>`, `<x-ui.tabs>`, `<x-ui.badge-state>` (estado del cliente), `<x-ui.money>` (formato `$ 1.234.567`), `<x-ui.date>` (formato `DD/MM/AAAA`).

- Las props las fija la Fase 0a en `docs/arquitectura.md`.
- Ningún módulo crea estilos propios ni cambia estos componentes. Si falta uno, lo pide.

## C.6 Reglas de trabajo para cada agente

1. **Antes de empezar:**
   - leer este documento y `docs/arquitectura.md` (cuando exista)
   - cargar las skills que le apliquen: `ryr-design`, `dataviz`, `robot-sap-hana`, `cpanel-deploy`, `work-unit-commits` y `chained-pr`
2. **Archivos permitidos:** escribir solo en los archivos de su fila. Si necesita algo fuera, se detiene y lo pide al usuario.
3. **Prefijos de fecha para migraciones:**

   | Módulo | Prefijo |
   |---|---|
   | Core | `2026_10_01_*` |
   | Ingesta | `2026_10_02_*` |
   | Cartera | `2026_10_03_*` |
   | Asesores | `2026_10_04_*` |
   | Cobro | `2026_10_05_*` |
   | Bancos | `2026_10_06_*` |
   | Gerencia | `2026_10_07_*` |
   | Comisiones | `2026_10_08_*` |

4. **Pruebas primero:** cuando exista una prueba ejecutable con resultado claro, verla fallar, implementar y verla pasar. Las reglas de estados, días de gracia, exclusiones, tramos y comisiones van con pruebas unitarias.
5. **Commits:** Conventional Commits, sin líneas de coautoría; una unidad de trabajo por commit, con pruebas y documentación junto al código.
6. **PR:** alrededor de 400 líneas cada uno. Si un módulo es más grande, se usan PR encadenados (skill `chained-pr`).
7. **Integración con `main`:** antes de abrir o actualizar el PR, sincronizar con `main` y correr `composer lint` y `composer test`. Ningún PR se fusiona con CI en rojo.
8. **Sin secretos en el repositorio:** las credenciales de Graph, de HANA y de la base van solo en `.env`.
9. **Idioma:** textos de interfaz en español, tuteando y en tipo oración. Código y comentarios en inglés.
10. **Push y PR:** el agente sube su rama y abre el PR contra `main`. **Nunca** fusiona ni sube directo a `main`; eso lo decide el usuario.

---

# PARTE D — PENDIENTES ANTES DE CONSTRUIR

| # | Pendiente | Quién lo entrega | Bloquea |
|---|---|---|---|
| 1 | ⏳ Formato del informe de **circularización** que hoy se envía a los clientes | Usuario | Circularización |
| 2 | ⏳ Archivo de **conciliación** bancaria | Usuario | Conciliación |
| 3 | ⏳ Ejemplos de extracto de cada banco (Bogotá, Davivienda, Bancolombia y Miami) | Tesorería | Lectores de Bancos |
| 4 | ⏳ Formato actual del informe de recaudo diario | María Paula | 5.6 |
| 5 | ⏳ Cómo hace hoy Tesorería el acumulado semanal de ingresos | Tesorería | 5.7 |
| 6 | Dirección exacta del buzón de Segurex (`segurex.info@…`), y permisos de Graph limitados a los dos buzones | TI | Cobro en producción |
| 7 | Registro de la aplicación en Entra ID (ingreso y correo) | TI | Fase 0b núcleo, en producción |
| 8 | Plan de GoDaddy: versión de MySQL, acceso SSH, cron y PHP 8.3 en MultiPHP | TI | Despliegue |
| 9 | Quién **aprueba** la liquidación de comisiones (distinto de Miguel) | Gerencia | Comisiones: interfaz |
| 10 | Puntos 14.2 a 14.7 de la especificación de comisiones: IVA en ventas, cruces en directores y en 85.1, códigos 50 y 53, cambio de asesor en cruces, cumplimiento por código o por persona, moneda extranjera | Miguel | Partes de Comisiones: cálculo |
| 11 | Confirmar que los «15 días» de SGX se cuentan **después del vencimiento** de la factura y no desde su fecha de emisión | María Paula | Estados del núcleo |
| 12 | Si el cobro semanal y la circularización aplican también a SDC desde ya, o solo a SGX en esta fase | Miguel | Alcance de Cobro |
| 13 | Rotar el secreto de la aplicación de OneDrive de la Fase 1 cuando se apague esa app | TI | Despliegue |
