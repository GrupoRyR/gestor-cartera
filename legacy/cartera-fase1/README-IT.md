# Cartera R&R — Documento técnico para IT

Sistema de seguimiento de cartera por cobrar de **Segurex LATAM (SGX)** y
**Soluciones y Derivados de Colombia (SDC)**. Entregado el 2026-09-09.
Responsable de negocio: Edward Ramanan (CEO). Este documento es la entrega
técnica; el paso a paso de instalación está en `INSTRUCTIVO.md`.

## 1. Qué hace

- Kanban de gestión de cobro por cliente (Al día → Por vencer → Vencida →
  En gestión → Compromiso → Incumplido → Pago recibido → Jurídico), con
  bitácora de acciones (llamadas, correos, WhatsApp, notas, documentos,
  compromisos con valor y fecha, descuento financiero ofrecido).
- La cartera se alimenta **sola desde OneDrive** (archivos `ia_SGX / ia_SDC -
  Cartera del día.xlsx` que genera SAP B1, carpeta `02_Cartera`), vía Microsoft
  Graph con revisión horaria por cron. Carga manual de Excel como respaldo.
- Registro de **pagos** aplicados a facturas (retenciones fuente/IVA/ICA,
  descuento, banco, referencia) y generación de **plano CSV para SAP B1**
  (Incoming Payments; layout por afinar con el consultor SAP).
- **Conciliación bancaria**: carga de extractos (xlsx/csv), cruce automático
  contra facturas abiertas (exacto / con retenciones / por nombre-NIT) y
  pre-aplicación que el usuario confirma.
- Cargador de **contactos** desde el maestro de Socios de Negocio de SAP
  (cruce por NIT normalizado).
- Dashboard por empresa + consolidado GRUPO. Responsive móvil (barra inferior,
  kanban táctil) e instalable como PWA (manifest + íconos).

## 2. Stack y arquitectura

- **Frontend**: SPA en un solo `index.html` (vanilla JS, sin framework).
  SheetJS (CDN) para parsear Excel en el navegador. Fuentes Google (Outfit +
  Manrope). Tema claro/oscuro.
- **Backend**: `api.php` — PHP 8 + MySQL (PDO), API JSON por `?action=`.
  Sin dependencias/composer: los parsers y clientes Graph son propios.
- **Hosting objetivo**: GoDaddy cPanel compartido (plan existente de la
  compañía, usuario `dzx21gq5iyfc`). Requisitos: PHP 8 con extensiones
  estándar (pdo_mysql, zip/ZipArchive, curl, simplexml, mbstring), MySQL,
  cron de cPanel, SSL (AutoSSL).
- **Integración OneDrive**: client-credentials contra Microsoft Graph
  (tenant Segurex `97cd024c-…`, app `d3afc9fd-…` — la misma app registration
  de "Mi Despacho"; requiere permiso de aplicación `Files.Read.All` con admin
  consent). Descarga los xlsx del OneDrive de Edward.Ramanan@segurex.com y
  compara eTag para importar solo si cambió.

```
[Navegador SPA index.html]  ⇄  api.php (PHP8 + MySQL)
        │                          │
        │ SheetJS (carga manual,   ├─ import-lib.php  (importación + estados)
        │ extractos, contactos)    ├─ xlsx-lib.php    (lector xlsx puro PHP)
        │                          ├─ onedrive-lib.php(Graph: token/meta/descarga)
        │                          └─ bank_movs/payments/actions/… (MySQL)
[cron cPanel] → cron-cartera.php → sync OneDrive por hora (eTag)
```

## 3. Archivos del paquete

| Archivo | Rol |
|---|---|
| `index.html` | SPA completa (UI + lógica de vistas) |
| `api.php` | API JSON (auth, estado, kanban, acciones, pagos, conciliación, contactos, sync) |
| `config.php` | Credenciales BD + SECRET_KEY + admin inicial (editar antes de subir) |
| `import-lib.php` | Importación de cartera, recálculo de estados, mapeo de encabezados, kv |
| `xlsx-lib.php` | Lector .xlsx sin dependencias (ZipArchive + SimpleXML) |
| `onedrive-lib.php` | Cliente Microsoft Graph (token, metadata, descarga, sync) |
| `onedrive-config.php` | Tenant/client/secret Graph + rutas de archivos + CRON_KEY |
| `cron-cartera.php` | Punto de entrada del cron (CLI o web con `?k=CRON_KEY`) |
| `setup.sql` | Esquema completo (instalación nueva) |
| `migracion-1/2/3.sql` | Solo si la BD se creó con una versión anterior del setup |
| `manifest.json`, `icon-*.png` | PWA (instalable en Android/iOS) |
| `INSTRUCTIVO.md` | Instalación paso a paso (subdominio, BD, Entra, cron) |

## 4. Base de datos (MySQL, utf8mb4)

| Tabla | Contenido |
|---|---|
| `users` | Usuarios; rol `admin`/`gestor`; visibilidad `SGX`/`SDC`/`ALL` |
| `clients` | Clientes por empresa+NIT; contacto (email/tel), gestor, estado kanban, modo_correo |
| `invoices` | Facturas de la foto vigente (`activa=1`); histórico por `upload_id` |
| `uploads` | Historial de cargas/sincronizaciones con totales |
| `actions` | Bitácora (tipo, resultado, detalle, compromiso, descuento, adjunto) |
| `payments` | Pagos registrados (retenciones, facturas JSON, cola `exportado` del plano SAP) |
| `bank_movs` | Movimientos de extractos (hash único anti-duplicado, estado pendiente/conciliado/ignorado) |
| `kv` | Estado de sincronización (eTags, último resultado) |

Regla clave de importación: cada carga **reemplaza la foto completa** de la
empresa (facturas anteriores pasan a `activa=0`); estados de gestión manual y
bitácora se conservan; clientes cuya cartera queda en cero pasan a
`pago_recibido` automáticamente.

## 5. API (api.php?action=…)

`login`, `state`, `client`, `upload`, `sync`, `move`, `save_client`,
`add_action`, `add_payment`, `export_paid`, `open_invoices`, `movs`,
`movs_upload`, `mov_update`, `contacts_upload`, `upload_doc`, `doc`,
`user_save`, `passwd`.

Autenticación: header `X-Auth` con token HMAC-SHA256 firmado con `SECRET_KEY`
(vigencia 90 días). El primer login crea el admin definido en `config.php`.

## 6. Seguridad

- Contraseñas con `password_hash` (bcrypt). Tokens firmados HMAC, sin sesiones
  en servidor.
- Autorización por empresa **en el servidor** (no solo UI): un gestor de SGX
  no puede leer/escribir datos de SDC.
- Adjuntos en `/docs` con `.htaccess Deny from all` (solo se sirven
  autenticados vía `action=doc`), nombres aleatorios, extensiones ejecutables
  bloqueadas, límite 25 MB.
- `cron-cartera.php` exige `CRON_KEY` por web (o CLI). Dedupe de extractos por
  hash único.
- Secretos solo en `config.php` / `onedrive-config.php` (no viajan al
  navegador). Recomendado: permisos 600 y mantenerlos fuera de repositorios.

## 7. Operación

- **Cron**: `0 6-19 * * * /usr/local/bin/php /home/dzx21gq5iyfc/RUTA/cron-cartera.php`
  (importa solo si el archivo cambió; `?force=1` fuerza).
- **Monitoreo rápido**: pestaña Datos muestra último sync y resultado;
  `cron-cartera.php?k=…` devuelve JSON de estado.
- **Respaldo**: dump de MySQL + carpeta `/docs`. El código es estático.
- **Demo**: `cartera-demo.html` (carpeta `demo/`) es autocontenido — la misma
  UI con backend simulado en memoria y datos reales embebidos; útil para UAT
  sin tocar el servidor. No desplegar en público (contiene datos de clientes).

## 8. Hoja de ruta acordada

- **Fase 2 — Correos**: envío real desde `cartera@segurex.com` /
  `cartera@derivadoscol.com` vía Graph (`Mail.Send`, ya consentido en ambos
  tenants para Mi Despacho), plantillas por empresa y reglas de vencimiento por
  cron; modo auto/manual por cliente (campo `modo_correo` ya existe).
- **Fase 3 — WhatsApp Business API**: número dedicado por empresa solo para
  recordatorios automáticos (verificar coexistencia Meta); la gestión 1-a-1
  sigue por click-to-chat desde los celulares de cartera.
- **Plano SAP**: validar layout definitivo con contabilidad/consultor SAP B1
  (hoy: CSV `;` una fila por factura con PAGO_ID, Código SN, NIT, retenciones).
