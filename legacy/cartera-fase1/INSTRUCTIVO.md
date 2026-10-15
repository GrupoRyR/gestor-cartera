# Cartera R&R — Instructivo de instalación (Fase 1)

App de seguimiento de cartera por cobrar para **Segurex (SGX)** y **SDC**, en tu
hosting GoDaddy cPanel (el mismo plan de Mi Despacho, sitio nuevo e independiente).
La cartera se alimenta **sola desde OneDrive** (carpeta `02_Cartera`), con carga
manual de Excel como respaldo.

**Contenido del paquete:** `index.html` · `api.php` · `config.php` · `setup.sql`
· `import-lib.php` · `xlsx-lib.php` · `onedrive-lib.php` · `onedrive-config.php`
· `cron-cartera.php` · `migracion-1.sql` y `migracion-2.sql` (solo si ya tenías
la BD creada con una versión anterior del setup.sql)

---

## 1. Crear el sitio (5 min)

1. Entra a tu **cPanel de GoDaddy**.
2. En **Dominios → Crear un nuevo dominio**, crea el subdominio
   `cartera.grupoinversorryr.com` (o el que prefieras) apuntando a una carpeta
   nueva, ej. `cartera.grupoinversorryr.com/`.
3. Activa el **candado SSL** para el subdominio (cPanel → SSL/TLS Status → Run AutoSSL).

## 2. Crear la base de datos (5 min)

1. cPanel → **Bases de datos MySQL**.
2. Crea la base `dzx21gq5iyfc_cartera`.
3. Crea un usuario (ej. `dzx21gq5iyfc_cart`) con contraseña fuerte y **agrégalo a
   la base con TODOS los privilegios**.
4. Abre **phpMyAdmin**, selecciona la base nueva → pestaña **Importar** → sube
   `setup.sql` → Continuar. Debe crear 5 tablas.

## 3. Configurar y subir (5 min)

1. Abre `config.php` en un editor y completa:
   - `DB_NAME`, `DB_USER`, `DB_PASS` → los del paso 2.
   - `ADMIN_PASS` → tu contraseña inicial (cámbiala también luego desde la app).
2. Abre `onedrive-config.php` y pega en `OD_CLIENT_SECRET` el **mismo secreto**
   que usas en el `graph-config.php` de Mi Despacho (app del tenant Segurex —
   tenant y client ID ya vienen prellenados).
3. cPanel → **Administrador de archivos** → carpeta del subdominio → sube:
   `index.html`, `api.php`, `config.php`, `import-lib.php`, `xlsx-lib.php`,
   `onedrive-lib.php`, `onedrive-config.php`, `cron-cartera.php`.

## 4. Primer ingreso

1. Abre `https://cartera.grupoinversorryr.com`.
2. Entra con `edward.ramanan@segurex.com` + la clave de `config.php`
   (el usuario admin se crea solo en el primer login).
3. Ve a **Equipo** y crea a los gestores: rol *Gestor* y empresa *SGX*, *SDC* o
   *Ambas* — cada gestor solo ve su empresa.
4. Botón **Clave** (arriba a la derecha) para cambiar tu contraseña.

## 5. Conectar OneDrive (una sola vez, ~5 min)

La app lee directamente los archivos de tu OneDrive (Edward.Ramanan@segurex.com):

- `00 Folder Financiero R&R/Panel Consolidado Claude/02_Cartera/ia_SGX - Cartera del día.xlsx`
- `00 Folder Financiero R&R/Panel Consolidado Claude/02_Cartera/ia_SDC - Cartera del día.xlsx`

**Único requisito en Entra** (lo haces tú como admin M365 de Segurex, igual que
con Mi Despacho):

1. [entra.microsoft.com](https://entra.microsoft.com) → **App registrations** →
   la app de Mi Despacho (client `d3afc9fd-…`) → **API permissions**.
2. **Add a permission → Microsoft Graph → Application permissions →
   `Files.Read.All`** → Add.
3. Botón **Grant admin consent for Segurex** → Sí.

**Cron en cPanel** (revisión automática cada hora, solo importa si el archivo
cambió):

```
0 6-19 * * * /usr/local/bin/php /home/dzx21gq5iyfc/RUTA_DEL_SITIO/cron-cartera.php
```

(ajusta `RUTA_DEL_SITIO` a la carpeta del subdominio; es el mismo formato de los
cron de Mi Despacho).

**Prueba inmediata** desde el navegador:
`https://TU_SITIO/cron-cartera.php?k=LLAVE` (la `CRON_KEY` está en
`onedrive-config.php`). Debe responder JSON con `"status": "importado"` para
SGX y SDC. Desde la app: pestaña **Datos → Sincronizar ahora**.

## 6. Carga manual (respaldo)

Si algún día falla OneDrive o quieres cargar un corte histórico: pestaña
**Datos → Carga manual**, verifica la fecha de corte y arrastra el Excel.

Cada importación (automática o manual) **reemplaza la foto completa** de esa empresa:
- Los días de mora se recalculan contra la fecha de corte.
- Clientes cuya cartera queda en cero pasan solos a **Pago recibido**.
- La **bitácora** y los estados de gestión manual (En gestión, Compromiso,
  Jurídico, Incumplido) **se conservan** entre cargas.

## 7. Pagos y plano para SAP

- En la ficha del cliente, **💰 Registrar pago** (o mover la ficha a «Pago
  recibido») abre el formulario: fecha, banco, referencia, facturas aplicadas,
  retenciones (fuente/IVA/ICA) y descuento financiero tomado. El saldo de las
  facturas se descuenta de inmediato en la app.
- Los pagos quedan **en cola en la pestaña Datos → «Pagos por aplicar en SAP»**.
  El botón **Descargar plano SAP** genera un CSV (separado por «;», una fila por
  factura) para que contabilidad lo importe en SAP B1 como Incoming Payments —
  el layout exacto se ajusta con el consultor SAP. Al descargar, los pagos se
  marcan como exportados.
- SAP sigue siendo la fuente oficial: la siguiente sincronización desde OneDrive
  refleja lo que contabilidad haya aplicado realmente.

## 8. Conciliación bancaria (🏦)

1. Pestaña **Conciliación** → elige el banco → carga el extracto (Excel o CSV,
   el que descarga tesorería del portal del banco). La app detecta las columnas
   (fecha, descripción, referencia, valor) y deja ajustarlas antes de cargar.
   Solo toma los **ingresos** y no duplica movimientos ya cargados.
2. Cada ingreso queda cruzado contra las facturas abiertas:
   - 🟢 **Exacta** — coincide al peso con una factura o con las más antiguas del cliente.
   - 🟡 **Con retenciones** — 0-12% menor (retefuente/IVA/ICA); calcula la retención implícita.
   - 🔵 **Por nombre** — la descripción menciona al cliente o su NIT.
   - ⚪ **Sin sugerencia** — se asigna manualmente («Otro cliente…») o se ignora
     (ingresos que no son cartera).
3. **Nada se aplica solo**: «✓ Aplicar» abre el formulario de pago pre-diligenciado
   (facturas, banco, referencia, fecha, retención sugerida). El usuario revisa,
   ajusta retenciones/descuento y **confirma** — el pago descuenta las facturas
   y entra a la cola del **plano SAP** (paso 7).

## 9. Uso diario

- **Dashboard**: total, vencido, edades, top deudores, compromisos de la semana,
  vencidos sin gestión.
- **Kanban**: arrastra las fichas entre columnas. Estados automáticos (Al día /
  Por vencer / Vencida / Pago recibido) los pone la app; los de gestión los mueve
  el equipo.
- **Ficha del cliente** (clic en cualquier tarjeta o fila): facturas con saldo,
  bitácora, registrar 📞 llamada / 🤝 compromiso / 📝 nota, adjuntar 📎 documentos
  de cobro (máx. 25 MB), y guardar **correo y teléfono del cliente** (el Excel no
  los trae — se capturan una sola vez aquí).
- **✉️ Correo / 💬 WhatsApp**: arma el mensaje de cobro con las facturas vencidas
  y lo abre en tu correo (con copia a cartera@) o en WhatsApp del gestor; al
  volver, registra la acción en la bitácora. *(El envío automático desde
  cartera@segurex.com / cartera@derivadoscol.com vía Microsoft Graph es la
  Fase 2; el campo «Modo de correo» de cada cliente ya queda listo para eso.)*

## Seguridad

- Login con contraseña cifrada (bcrypt) y sesiones firmadas (HMAC, 90 días).
- Gestores restringidos por empresa también en el servidor, no solo en pantalla.
- Los adjuntos se guardan en `/docs` bloqueada por `.htaccess` (solo se descargan
  autenticado vía la API).

## Próximas fases

- **Fase 2 — Correos automáticos**: envío desde cartera@ por Microsoft Graph
  (reusa los registros Entra de Mi Despacho), reglas por vencimiento con cron de
  cPanel y modo auto/manual por cliente.
- **Fase 3 — WhatsApp Business API**: plantillas aprobadas por Meta y envío
  directo desde la app.
