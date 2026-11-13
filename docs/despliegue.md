# Despliegue en GoDaddy (cPanel)

Checklist de la primera instalación y del despliegue de rutina. Complementa `docs/arquitectura.md` §15 (variables) y §16 (cómo funciona `deploy/deploy.sh`); si algo choca, mandan esas secciones.

| Dato | Valor |
|---|---|
| Repositorio | `GrupoRyR/gestor-cartera` (privado) |
| Dominio | `gestorcartera.segurex.com.co` |
| Carpeta de la app (en el home de cPanel) | `gestor-cartera` |
| Document Root | `gestor-cartera/public` |
| Alias SSH en el PC | `cartera-cpanel` (paso 1) |
| Alias de GitHub en el servidor | `github-gestor-cartera` (paso 3) |

**Reglas.**

- Los comandos remotos los ejecutas tú, o un agente solo cuando se lo pides en el chat, uno por uno.
- Nunca pegues contraseñas, secretos ni el `.env` en el chat, en correos ni en este repositorio. El usuario y el servidor de cPanel tampoco van aquí: viven en tu `~/.ssh/config` y en `.cpanel-deploy.conf`, que Git ignora.
- En el servidor no se edita código, no se resuelven conflictos y no se usa `git reset --hard`: todo entra por Git.

## 0. Antes de empezar (en el PC)

- [ ] Todo lo que va a producción está fusionado en `main`.
- [ ] **PR de compilados.** `public/build` está en `.gitignore` y ningún módulo lo sube. Desde una rama `deploy/compilados-<fecha>`, corre `npm ci && npm run build` y `git add -f public/build`. Abre su propio PR contra `main` y fusiónalo antes de desplegar. CI rechaza `public/build` en cualquier otra rama. Sin él, `deploy/deploy.sh` se niega a correr.
- [ ] `DatabaseSeeder` fuera de `local` solo corre los `*PermissionSeeder`, sin factories ni Faker, así que es seguro con `--no-dev`.

## 1. Acceso SSH desde el PC

1. En el panel de GoDaddy del hosting (Configuración › Servidor › Acceso SSH), activa SSH. Viene apagado, y sin él `ssh` no conecta aunque la llave esté bien.
2. En cPanel › Security › SSH Access › Manage SSH Keys, importa la llave **pública** de tu PC (`~/.ssh/id_ed25519.pub`) y autorízala.
3. En el PC, agrega a `~/.ssh/config` (con el usuario y el servidor que te dio GoDaddy):

   ```text
   Host cartera-cpanel
       HostName <servidor-de-cpanel>
       User <usuario-de-cpanel>
       IdentityFile ~/.ssh/id_ed25519
       IdentitiesOnly yes
   ```

4. Prueba con `ssh cartera-cpanel "echo ok"`. Debe entrar sin pedir contraseña.

## 2. PHP y Composer

Todos estos comandos se corren en el servidor (`ssh cartera-cpanel`).

- [ ] Para `gestorcartera.segurex.com.co`, cPanel › MultiPHP Manager debe decir PHP 8.3. GoDaddy puede fijar la versión para toda la cuenta; si es así, no se cambia por dominio.
- [ ] Busca el binario de PHP 8.3 y anota su ruta. La misma ruta va en el cron (paso 9) y en `PHP=` de `.cpanel-deploy.conf` (paso 12):

  ```bash
  php -v
  /usr/local/bin/php -v
  /opt/cpanel/ea-php83/root/usr/bin/php -v
  ```

- [ ] `composer --version`. Si no existe, usa `composer.phar` en el home:
  - En la terminal del servidor: `COMPOSER_BIN="<php-8.3> $HOME/composer.phar"`. Con `~` no funciona, porque `deploy.sh` no lo expande.
  - En `.cpanel-deploy.conf`: `COMPOSER_BIN=<php-8.3> ~/composer.phar`. El script del PC convierte `~/` en `$HOME/`.
- [ ] En cPanel › MultiPHP INI Editor, activa `zend.exception_ignore_args = On` (auditoría S-29): las trazas del log no guardan argumentos.

## 3. Llave de despliegue de GitHub

Es una llave de solo lectura, solo para esta app. En el servidor:

```bash
ssh-keygen -t ed25519 -f ~/.ssh/gestor-cartera_deploy -N "" -C "gestor-cartera-deploy"

cat >> ~/.ssh/config <<'EOF'
Host github-gestor-cartera
    HostName github.com
    User git
    IdentityFile ~/.ssh/gestor-cartera_deploy
    IdentitiesOnly yes
EOF
chmod 600 ~/.ssh/config
cat ~/.ssh/gestor-cartera_deploy.pub   # solo la pública
```

En el PC, guarda esa línea en `gestor-cartera_deploy.pub` y regístrala **sin** permiso de escritura:

```bash
gh repo deploy-key add gestor-cartera_deploy.pub --repo GrupoRyR/gestor-cartera --title "cPanel gestorcartera.segurex.com.co"
```

En el servidor, `ssh -T github-gestor-cartera`:

- [ ] La huella debe ser una de las oficiales de GitHub. Si no coincide, **no aceptes**:
  - ED25519: `SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU`
  - ECDSA: `SHA256:p2QAMXNIC1TJYWeIOttrVc98/R1BUFWu3/LiyKgUfQM`
- [ ] Debe responder «Hi GrupoRyR/gestor-cartera! You've successfully authenticated…».

## 4. Dominio y clonado

- [ ] En cPanel › Domains, crea `gestorcartera.segurex.com.co` con Document Root `gestor-cartera/public`. Va relativo al home: sin `/home/…`, sin barra al inicio y nunca la raíz del proyecto. Si queda en la raíz, el `.htaccess` de la raíz responde 403 a todo (auditoría S-03).
- [ ] Si el DNS de `segurex.com.co` no está en esta cuenta de GoDaddy, crea un registro `A` de `gestorcartera` apuntando a la IP compartida de la cuenta (la que muestra cPanel › General Information).
- [ ] cPanel crea `gestor-cartera/public` con `cgi-bin` y `.well-known`. Revísala y bórrala antes de clonar:

  ```bash
  ls -la ~/gestor-cartera ~/gestor-cartera/public   # solo cgi-bin y .well-known vacíos
  # Solo si lo anterior se confirmó; rmdir falla a propósito si queda algo más
  rm -rf ~/gestor-cartera/public/cgi-bin ~/gestor-cartera/public/.well-known && rmdir ~/gestor-cartera/public ~/gestor-cartera
  git clone git@github-gestor-cartera:GrupoRyR/gestor-cartera.git ~/gestor-cartera
  ```

- [ ] No dejes en el servidor nada fuera de Git: ni `etl/.env` ni copias de `legacy/` (auditoría S-03).

## 5. Base de datos MySQL

- [ ] En cPanel › Manage My Databases, crea la base `<prefijo>_cartera` y el usuario `<prefijo>_cartera`. El prefijo lo pone cPanel. Guarda la contraseña en el gestor de contraseñas y dale ALL PRIVILEGES.
- [ ] Cotejamiento `utf8mb4_unicode_ci`.
- [ ] ALL PRIVILEGES incluye `TRIGGER`, que usa la auditoría inmutable (auditoría S-14). Si `migrate` falla al crear un trigger, detente y avisa: no lo saltes.

## 6. Registros en Entra ID

Son dos registros separados: uno para el ingreso de las personas y otro solo para el correo.

**Ingreso** (`MICROSOFT_*`):

- [ ] Registro de aplicación de **un solo inquilino** (solo este directorio).
- [ ] Plataforma Web, URI de redirección `https://gestorcartera.segurex.com.co/auth/microsoft/callback`. Sin flujo implícito.
- [ ] Secreto de cliente: anota en el calendario cuándo vence.
- [ ] Permisos delegados de Microsoft Graph: `openid`, `profile` y `User.Read`.
- [ ] Token configuration › Add optional claim › ID › `acct`. Con él, la app rechaza a los invitados (`acct = 1`). Sin él, un invitado de este directorio pasaría, porque su `tid` es el de la empresa.
- [ ] En Aplicaciones empresariales › Propiedades, activa «¿Se requiere asignación?» y asigna solo a los empleados o al grupo que usa la app, nunca a invitados (auditoría S-05).

**Correo** (`GRAPH_*`), según `app/Modules/Cobro/README.md` («Microsoft Graph: lo que TI debe configurar»):

- [ ] Registro aparte, de un solo inquilino, con secreto de cliente. **Sin** permisos de Microsoft Graph en Entra ID y sin consentimiento de administrador: un permiso de Entra ID abre todos los buzones.
- [ ] En Exchange Online, con RBAC para aplicaciones, dale `Application Mail.Send` y `Application Mail.ReadWrite` con un alcance que cubra solo los dos buzones (`New-ServicePrincipal`, `New-ManagementScope` y `New-ManagementRoleAssignment`). Los buzones son `segurex.info@segurex.com` y `derivados.info@derivadoscol.com`, salvo que `COBRO_MAILBOX_*` diga otros.
- [ ] `Test-ServicePrincipalAuthorization` debe dar `InScope = True` para los dos buzones y `False` para cualquier otro. Los cambios tardan entre 30 minutos y 2 horas.

## 7. `.env` de producción (solo en el servidor)

Primero instala las dependencias, porque `artisan` las necesita:

```bash
cd ~/gestor-cartera
composer install --no-dev --optimize-autoloader --no-interaction   # o con el COMPOSER_BIN del paso 2
cp .env.example .env
chmod 600 .env
<php-8.3> artisan key:generate
nano .env
```

- [ ] Cada variable aparece **una sola vez**. Si se repite, Laravel usa la primera, igual que `deploy/check-env.sh`.
- [ ] No definas estas variables con `SetEnv` de Apache, en el editor de PHP de cPanel ni en `~/.bashrc`: le ganan al `.env` y la revisión no las ve.

Variables (solo los nombres; los valores van en el servidor):

| Grupo | Variables | Valor en producción |
|---|---|---|
| Base | `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_KEY` | `production`, `false`, `https://gestorcartera.segurex.com.co`; `APP_KEY` sale de `key:generate` |
| Demostración | `DEMO_LOGIN` | `false` o fuera del `.env` (auditoría S-11) |
| Registro | `LOG_LEVEL` | fuera del `.env` (registra desde `info`) o `warning`; nunca `debug` |
| Base de datos | `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | `mysql`, `localhost`, `3306` y los datos del paso 5 |
| Sesión, caché y cola | `SESSION_DRIVER`, `SESSION_SECURE_COOKIE`, `SESSION_ENCRYPT`, `CACHE_STORE`, `QUEUE_CONNECTION` | `database`, `true` o fuera, `true` o fuera, `database`, `database` |
| Correo del framework | `MAIL_MAILER` | `log`: el correo de negocio va por Graph |
| Ingreso | `MICROSOFT_TENANT_ID`, `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET`, `MICROSOFT_REDIRECT_URI` | del registro de ingreso; la URI es la del paso 6; nunca `common` |
| Correo (Graph) | `GRAPH_TENANT_ID`, `GRAPH_CLIENT_ID`, `GRAPH_CLIENT_SECRET` | del registro de correo |
| Buzones | `COBRO_MAILBOX_SGX`, `COBRO_MAILBOX_SDC`, `COBRO_TENANT_DOMAINS` | los dos buzones del paso 6; dominios solo si hace falta |
| Llaves de 32 bytes o más | `INGESTA_HMAC_KEYS` (`id:secreto`), `BANCOS_API_TOKEN`, `BANCOS_INGEST_TOKEN` | una más corta se ignora y deja la API cerrada |
| Destinatarios | `CORE_AUDIT_ALERT_RECIPIENTS`, `COMISIONES_APPROVAL_RECIPIENTS`, `INGESTA_ALERT_RECIPIENTS` | correos separados por comas; sin ellos, las alertas quedan solo en el log |
| Opcionales | `APP_TIMEZONE`, `INGESTA_HMAC_MAX_SKEW`, `INGESTA_ALERT_DEADLINE`, `BANCOS_IMPORT_PATH`, `BANCOS_IMPORT_AT`, `BANCOS_MIAMI_CURRENCY`, `CARTERA_SIN_GESTION_DIAS` | déjalas con su valor por defecto |

Genera cada secreto de llave en el servidor y pégalo directo en `nano`. Cada uno sale de 64 caracteres:

```bash
<php-8.3> -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Verifica sin mostrar secretos:

```bash
grep -n -E "^(APP_ENV|APP_DEBUG|APP_URL|DEMO_LOGIN|LOG_LEVEL|DB_CONNECTION|DB_HOST|DB_DATABASE|SESSION_DRIVER|SESSION_SECURE_COOKIE|SESSION_ENCRYPT|CACHE_STORE|QUEUE_CONNECTION|MICROSOFT_REDIRECT_URI|COBRO_MAILBOX_SGX|COBRO_MAILBOX_SDC)=" .env
# Debe imprimir 7: cuenta los secretos con valor sin mostrarlos
grep -c -E "^(APP_KEY|DB_PASSWORD|MICROSOFT_CLIENT_SECRET|GRAPH_CLIENT_SECRET|INGESTA_HMAC_KEYS|BANCOS_API_TOKEN|BANCOS_INGEST_TOKEN)=.+" .env
bash deploy/check-env.sh
```

## 8. Primer despliegue

```bash
cd ~/gestor-cartera && PHP=<php-8.3> bash deploy/deploy.sh
```

- [ ] `check-env.sh` pasa y `migrate` habla de la base MySQL, no de sqlite.
- [ ] Termina en `Deploy finished`.
- [ ] No hace falta otro `db:seed`: el despliegue ya sembró los permisos y roles.

## 9. HTTPS

- [ ] En cPanel › SSL/TLS Status, elige `gestorcartera.segurex.com.co` › Run AutoSSL. Después abre `https://gestorcartera.segurex.com.co`.

## 10. Cron (obligatorio)

- [ ] En cPanel › Cron Jobs, programa **Once Per Minute** con este comando:

  ```text
  cd ~/gestor-cartera && <php-8.3> artisan schedule:run >> /dev/null 2>&1
  ```

- [ ] Correrlo una vez en la terminal no lo deja programado: tiene que quedar en Cron Jobs. Ese minuto a minuto también vacía la cola (`queue:work --stop-when-empty`, `docs/arquitectura.md` §10).
- [ ] `crontab -l` muestra la línea y `<php-8.3> artisan schedule:list` lista las tareas.
- [ ] La app no tiene una señal de vida del scheduler. Para confirmar que corre, cambia el primer día `/dev/null` por `storage/logs/cron.log` y revisa que el archivo crezca cada minuto. Después vuelve a `/dev/null` y borra ese archivo.

## 11. Comprobaciones finales

En el servidor, `bash deploy/check-env.sh`. Desde el PC, en Git Bash (en PowerShell 5.1, `curl` es otro comando):

```bash
curl -sI http://gestorcartera.segurex.com.co/login | grep -iE '^(HTTP|location)'
curl -sI https://gestorcartera.segurex.com.co/login | grep -iE '^(strict-transport|x-content-type|x-frame|referrer-policy|permissions-policy|x-powered-by|content-security-policy)'
for path in up .env build/ dev/entrar dev/componentes; do
  printf '%s ' "$path"; curl -s -o /dev/null -w '%{http_code}\n' "https://gestorcartera.segurex.com.co/$path"
done
```

Lo esperado:

- Un 301 hacia `https://`.
- Las cabeceras de seguridad y la `Content-Security-Policy`, sin `X-Powered-By`.
- `up 200`, `.env 403`, `build/ 403` o `404`, `dev/entrar 404` y `dev/componentes 404`.

Si algo sale distinto, revisa `docs/arquitectura.md` §16 («Si algo sale distinto»).

**¿Hay un proxy delante? (auditoría S-28).** La app no confía en ningún proxy (`bootstrap/app.php` no llama `trustProxies`). Confírmalo así:

- [ ] `curl -sI https://gestorcartera.segurex.com.co/login | grep -iE '^(server|via|cf-ray|x-sucuri|x-cache|x-served-by)'`. Un `server: cloudflare`, un `cf-ray` o un `x-sucuri-id` delatan un proxy.
- [ ] `nslookup gestorcartera.segurex.com.co` debe devolver la misma IP compartida de cPanel.
- [ ] En cPanel › Metrics › Raw Access, la IP de tu visita debe ser la IP pública de tu red, no una de GoDaddy ni de un CDN.
- [ ] Si hay proxy, no despliegues cambios a ciegas: hace falta `trustProxies(at: [<ip del proxy>])` (nunca `'*'`) y `trustHosts()`, con autorización y en su propio PR. Mientras tanto, el robot y Tesorería comparten el límite de peticiones.

## 12. Despliegue de rutina en un comando

En el PC, una sola vez:

- [ ] Copia `deploy/cpanel-deploy.conf.example` como `.cpanel-deploy.conf` en la raíz del repositorio (Git lo ignora). Deja `SSH_HOST=cartera-cpanel` y `APP_DIR=gestor-cartera`, y pon en `PHP=` la ruta del paso 2.
- [ ] Prueba sin ejecutar nada: `powershell -ExecutionPolicy Bypass -File deploy\deploy-remoto.ps1 -DryRun`.

Cada vez que publiques:

1. Fusiona los cambios en `main`. Si cambió alguna vista o clase de Tailwind, fusiona también un PR de compilados nuevo (paso 0). Si el despliegue trae migraciones de datos o lotes nuevos del robot, sigue su apartado, como el paso 16.
2. Corre `powershell -ExecutionPolicy Bypass -File deploy\deploy-remoto.ps1`. Equivale a `ssh cartera-cpanel "cd ~/gestor-cartera && PHP=… bash deploy/deploy.sh"` y muestra la salida completa.
3. Revisa que termine en `Deploy finished` y que `https://gestorcartera.segurex.com.co/up` responda 200.

**Rollback:** en el PC, `git revert <commit>`, un PR y un despliegue normal. Nunca `reset` ni ediciones en el servidor. Si el cambio traía una migración destructiva, primero exporta la base (phpMyAdmin) y acuerda el `migrate:rollback` antes de correrlo.

## 13. Robot del PC de TI

En el PC con la VPN Sophos, desde la raíz del repositorio (`etl/README.md`):

- [ ] Copia `etl/.env.example` como `etl/.env` y llena:
  - `INGESTA_URL=https://gestorcartera.segurex.com.co` (sin `/api`).
  - `INGESTA_KEY_ID` y `INGESTA_SECRET`, iguales a un par de `INGESTA_HMAC_KEYS` del servidor.
  - `ETL_EMPRESAS=SGX,SDC`, `HANA_SCHEMA_SGX=SEGUREX`, `HANA_SCHEMA_SDC=HBT_SDERIVADOS`.
  - Los usuarios de HANA de cada empresa y los `SOPHOS_*`.
- [ ] Deja `SUPABASE_DB_URL` mientras siga vivo el portal anterior.
- [ ] El reloj del PC debe estar sincronizado: Laravel rechaza firmas con más de 5 minutos de diferencia.
- [ ] Haz una corrida manual con `python etl/run_etl.py` y revisa en la app que llegue el corte.
- [ ] Programa la tarea `CarteraWeb-ETL` (00:00, 05:00 y 12:00):

  ```powershell
  powershell -ExecutionPolicy Bypass -File scripts\instalar_tarea.ps1
  ```

## 14. Después del primer ingreso

- [ ] **Primer administrador.** Una persona nueva entra sin rol, y la app no trae un comando para dar `admin`. Entra una vez con Microsoft y luego, en el servidor:

  ```bash
  cd ~/gestor-cartera && <php-8.3> artisan tinker --execute="App\Models\User::where('email', '<tu-correo>')->firstOrFail()->assignRole('admin');"
  ```

  Los demás roles y las empresas (SGX, SDC) se dan desde la pantalla Equipo. `admin` no tiene el permiso `cobro.manage`. Por eso las copias y los envíos de prueba de abajo los hace alguien con `gerente_cartera` o `analista_cartera`. Dale ese rol en Equipo antes de seguir.
- [ ] Rol de aprobadora de comisiones:

  ```bash
  <php-8.3> artisan comisiones:rol tatiana.munoz@segurex.com comisiones_aprobador
  ```

  Ella debe haber entrado antes al menos una vez.
- [ ] **Copias del cobro.** En `/cobro/copias`, guarda las copias de SGX y de SDC. No hay copias por defecto: un envío sin copias guardadas sale sin ellas (auditoría S-31).
- [ ] **Prueba de circularización.** En `/cobro/circularizacion`, envía una prueba de cada empresa a un buzón interno y revisa remitente, formato y copias. Haz lo mismo con el cobro semanal en `/cobro/semanal`.

## 15. Riesgos abiertos

- **Envíos reales automáticos.** Con el cron activo y la cartera cargada, el cobro semanal sale a los clientes **los miércoles a las 5:00 p. m.** y la circularización **el día 1 a las 9:00 a. m.** No hay un interruptor en `.env`. Antes del primer miércoles, revisa las plantillas y las copias, y acuerda que el portal anterior deje de enviar los mismos correos, para que nadie los reciba dos veces. Mientras el lote `contactos` no esté activo, los clientes salen «Sin correo» y no reciben nada; al activarlo (paso 16), los envíos empiezan a llegar a los clientes.
- **Cambio de estados sin vuelta atrás.** La migración `2026_10_01_000015` no se deshace con `migrate:rollback`; el plan es la copia de la base (paso 16).
- **Proxy (S-28)** sin confirmar hasta hacer el paso 11.
- **Secretos con vencimiento.** Los secretos de cliente de los dos registros de Entra ID vencen: renuévalos antes y luego corre un despliegue (o `php artisan optimize`).
- **Fuera de este documento:** la migración de datos del portal anterior y el apagado de Cartera Fase 1 y del portal Next.js, con la rotación del secreto de OneDrive.

## 16. Actualización de noviembre de 2026 (#116 a #125)

Checklist del despliegue que sigue al de la primera instalación. Trae el lote `contactos`, los valores antes de IVA, el gestor del cliente, «Datos actualizados» y el retiro del estado «En gestión». Usa el paso 12 para el despliegue y este paso para lo que cambia.

### Antes (en el PC)

- [ ] **PR de compilados nuevo** (paso 0). El CSS compilado del primer despliegue no trae `xl:grid-cols-5`, que usa la ficha del cliente de Asesores (#120): sin recompilar, las cinco fichas de saldo se ven en tres columnas. Después de compilar, comprueba que el CSS lo trae: `grep -F -c 'xl\:grid-cols-5' public/build/assets/*.css` debe dar 1 o más.
- [ ] **Versión de MySQL.** La migración `2026_10_01_000014_add_before_tax_amounts_to_invoices` agrega dos columnas con `->after()`. En MySQL anterior a 8.0.29 o en MariaDB puede reconstruir `invoices`, con un bloqueo corto. El sitio ya está en mantenimiento durante `migrate`, así que solo confirma la versión en cPanel › Manage My Databases (o con `SELECT VERSION();` en phpMyAdmin).
- [ ] **Copia de la base.** Expórtala desde phpMyAdmin. La migración `2026_10_01_000015_retire_in_progress_client_status` cambia datos y su `down()` está vacío a propósito: `migrate:rollback` no la deshace.
- [ ] Avisa a Cartera: los clientes que estaban «En gestión» cambian de columna del Kanban y de contador.

### Durante

`deploy.sh` entra en mantenimiento, corre `migrate --force` y siembra los permisos. No hay variables nuevas en `.env`.

- [ ] `2026_10_01_000014`: agrega `amount_before_tax` y `balance_before_tax` (nulas) a `invoices`.
- [ ] `2026_10_01_000015`: pasa todos los clientes en `en_gestion`, también los eliminados, a su estado automático según la última carga de su empresa: «Vencida», «Por vencer», «Al día» o, sin saldo, «Pago recibido». Escribe una gestión y una auditoría por cliente, sin mover la última actividad.
- [ ] Permisos nuevos: `core.clients.assign` (`admin`, `gerente_cartera`, `analista_cartera`) y `cartera.clients.start_on_mine` (solo `analista_cartera`). Se siembran solos.
- [ ] Termina en `Deploy finished`.

### Después (en el servidor y en la app)

- [ ] `<php-8.3> artisan core:verificar-auditoria`: la cadena de auditoría debe seguir válida después de la migración `000015`.
- [ ] Gestores. `assignees()` solo lista a quien tenga rol `gerente_cartera` o `analista_cartera`, esté activo y trabaje en la empresa del cliente. En Equipo, revisa el rol y las empresas de las personas que serán gestores.
- [ ] Recorre con cada rol: «Mi cartera» y «Sin asignar» en la lista de clientes (la analista abre en «Mi cartera»), «Asignar gestor» en la lista y en la ficha, el Kanban de 7 columnas, y la ficha del asesor con cinco fichas de saldo. Los cambios de #119, #120, #122, #123, #124 y #125 se probaron solo con pruebas automáticas, no en un navegador.
- [ ] Los valores antes de IVA salen vacíos («—») hasta que el robot envíe la siguiente carga de `cartera`; no es un error.

### Robot: activar el lote `contactos`

Hasta ahora ninguna carga llenaba los contactos, y por eso el cobro salía «Sin correo». Desde #116, Core importa el lote `contactos`, pero el robot no lo extrae por defecto (`ETL_LOTES` trae `cartera,asesores`). En el PC de TI con la VPN, desde la raíz del repositorio (`etl/README.md`):

- [ ] `.venv\Scripts\python.exe etl\run_etl.py --diagnostico --lotes contactos`. Revisa los conteos por empresa, que cada cliente tenga como mucho un contacto principal y los puntos marcados en `contactos_clientes.sql`.
- [ ] Agrega `contactos` a `ETL_LOTES` en `etl/.env`: `ETL_LOTES=cartera,asesores,contactos`. No cambies las demás variables.
- [ ] Después de la siguiente corrida del robot, y con el cron activo (paso 10), abre la ficha de varios clientes de cada empresa y confirma que aparecen sus contactos. La importación corre en la cola, así que puede tardar un minuto.

### Antes del primer envío con contactos

- [ ] Revisa el riesgo de «Envíos reales automáticos» (paso 15): con contactos cargados, el cobro semanal y la circularización dejan de salir «Sin correo» y llegan a los clientes.
- [ ] Buzón de Derivados. El cobro semanal de SDC sale de verdad desde `COBRO_MAILBOX_SDC`. Confirma que `Test-ServicePrincipalAuthorization` da `InScope = True` para ese buzón y para el de Segurex (paso 6), y envía una prueba desde `/cobro/semanal` (`app/Modules/Cobro/README.md`, «Microsoft Graph»). El alcance se arma con un grupo de seguridad con correo que tenga solo esos dos buzones como miembros directos, y los ids del `New-ServicePrincipal` son los de Aplicaciones empresariales.
