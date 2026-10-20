# Migración de datos

Comandos de Artisan que traen a esta app el histórico de los dos sistemas anteriores:

- **Cartera Fase 1**: la base MySQL que se exporta con phpMyAdmin.
- **Supabase**: el proyecto de los asesores comerciales.

Los comandos no se conectan a ninguna de las dos bases. Leen archivos CSV que tú exportas y dejas en una carpeta, y esa carpeta se les pasa con `--dir`.

## Orden de ejecución

1. [ ] Confirma que Ingesta ya cargó los clientes, las facturas y los asesores de SAP de las dos empresas.
2. [ ] Exporta los seis archivos (ver «Cómo exportar») y déjalos en una carpeta del servidor, p. ej. `$HOME/migracion`.
3. [ ] Para cada comando, en este orden, córrelo primero en modo de prueba, revisa su reporte y solo entonces córrelo de verdad. No pases al siguiente hasta terminar el anterior: cada uno necesita lo que el anterior guardó (por ejemplo, compromisos necesita a los autores que trae usuarios).

   ```bash
   php artisan core:migrar-usuarios --dir="$HOME/migracion" --dry-run
   php artisan core:migrar-usuarios --dir="$HOME/migracion"

   php artisan core:migrar-gestiones --dir="$HOME/migracion" --dry-run
   php artisan core:migrar-gestiones --dir="$HOME/migracion"

   php artisan core:migrar-compromisos --dir="$HOME/migracion" --dry-run
   php artisan core:migrar-compromisos --dir="$HOME/migracion"

   php artisan core:migrar-estados --dir="$HOME/migracion" --dry-run
   php artisan core:migrar-estados --dir="$HOME/migracion"
   ```

   Si el modo de prueba muestra rechazos que puedes corregir (clientes, fechas, valores, columnas), corrige el archivo y repite la prueba antes de la corrida real.
4. [ ] Si después corriges filas rechazadas, vuelve a correr el comando: lo que ya entró queda omitido.

## Orden

Corre los comandos en este orden; cada uno usa lo que dejó el anterior.

| Paso | Comando | Qué trae |
|---|---|---|
| 1 | `core:migrar-usuarios` | Usuarios, sus roles, sus empresas y su vínculo con los asesores de SAP. |
| 2 | `core:migrar-gestiones` | Notas y gestiones de cobro. |
| 3 | `core:migrar-compromisos` | Compromisos de pago. |
| 4 | `core:migrar-estados` | Estados manuales de los clientes. |

Antes del paso 1, la app ya debe tener cargados por Ingesta los clientes, las facturas y los asesores de SAP (tabla `advisors`): la migración no los crea, solo los busca.

## Archivos

Cada comando lee solo sus archivos. Los nombres son fijos: guarda cada exportación con el nombre exacto de esta tabla. Puede haber más columnas que las listadas; se ignoran.

| Archivo | Origen | Columnas | Lo usa |
|---|---|---|---|
| `fase1_users.csv` | Fase 1, tabla `users` | `id`, `nombre`, `email`, `rol` (admin o gestor), `empresa` (SGX, SDC o ALL), `activo` (0 o 1) | usuarios; gestiones y compromisos (para saber quién escribió) |
| `fase1_clients.csv` | Fase 1, tabla `clients` | `id`, `empresa`, `nit`, `nombre`, `estado`, `estado_manual` | gestiones, compromisos, estados |
| `fase1_client_codes.csv` | Fase 1, consulta sobre `invoices` (opcional) | `client_id`, `codigo_sn` | gestiones, compromisos, estados |
| `fase1_actions.csv` | Fase 1, tabla `actions` | `id`, `client_id`, `empresa`, `user_id`, `tipo`, `resultado`, `detalle`, `compromiso_fecha`, `compromiso_valor`, `descuento`, `created_at`; `adjunto_nombre` si viene | gestiones, compromisos |
| `supabase_usuarios.csv` | Supabase, tabla `usuarios` | `id`, `email`, `nombre`, `rol` (asesor, gerente o admin), `slp_codes`, `activo` (true o false) | usuarios |
| `supabase_gestiones.csv` | Supabase, tabla `gestiones` | `id`, `codigo_sn`, `documento`, `slp_code`, `tipo` (nota o compromiso), `nota`, `fecha_compromiso`, `valor_compromiso`, `estado` (abierto, cumplido o cancelado), `creado_por` (correo), `creado_en`, `actualizado_en` | gestiones, compromisos |

Si un comando encuentra solo algunos de sus archivos, importa esos y te dice cuáles no encontró. Si no encuentra ninguno, termina con error sin hacer nada.

## Cómo exportar

### Fase 1 (phpMyAdmin)

Para una tabla completa:

1. Entra a phpMyAdmin y abre la base de Cartera Fase 1.
2. Selecciona la tabla en el panel izquierdo.
3. Pestaña **Exportar** → método **Personalizado**.
4. Formato: **CSV** (no «CSV para MS Excel»).
5. Marca **Poner los nombres de columna en la primera fila**.
6. Conjunto de caracteres del archivo: **utf-8**.
7. Guarda el archivo con el nombre exacto de la tabla de arriba.

Para los usuarios, no exportes la tabla completa: tiene la contraseña. Usa una consulta y exporta solo su resultado (pasos de abajo):

```sql
SELECT id, nombre, email, rol, empresa, activo FROM users;
```

Para una consulta (`fase1_users.csv` y `fase1_client_codes.csv`):

1. Abre la base y entra a la pestaña **SQL**.
2. Pega la consulta y dale **Continuar**.
3. Debajo de los resultados, en **Operaciones sobre los resultados de la consulta**, haz clic en **Exportar**.
4. Sigue los pasos 3 a 7 de arriba.

La consulta de `fase1_client_codes.csv`:

```sql
SELECT DISTINCT client_id, codigo_sn FROM invoices WHERE codigo_sn <> '';
```

### Supabase

Desde el editor de tablas:

1. Entra al proyecto en Supabase → **Table Editor**.
2. Abre la tabla (`usuarios` o `gestiones`).
3. Botón **Export** → **Export to CSV**.
4. Cambia el nombre del archivo descargado al de la tabla de arriba.

O desde el editor SQL: **SQL Editor** → pega la consulta → **Run** → **Download CSV**. Por ejemplo:

```sql
select id, email, nombre, rol, slp_codes, activo from usuarios;
```

## Cómo correrlo

1. Deja todos los CSV en una carpeta del servidor, por ejemplo `~/migracion`.
2. Corre primero en modo de prueba. Hace todo, te muestra el reporte y no guarda nada:

   ```bash
   php artisan core:migrar-usuarios --dir="$HOME/migracion" --dry-run
   ```

3. Revisa el reporte. Si hay filas rechazadas, corrige el CSV (o el dato en el sistema de origen y vuelve a exportar) y repite el modo de prueba.
4. Cuando el reporte esté bien, córrelo de verdad:

   ```bash
   php artisan core:migrar-usuarios --dir="$HOME/migracion"
   ```

Cada corrida es una sola transacción: o se guarda todo lo que el reporte cuenta como importado, o nada (si algo falla a mitad de camino, el comando lo dice y no deja nada a medias).

**Córrelo fuera del horario de trabajo**, o con la app en mantenimiento (`php artisan down` antes y `php artisan up` al terminar). Por ser una sola transacción, el comando deja bloqueados los usuarios y clientes que toca hasta que termina: mientras tanto, quien intente cambiarlos en la app espera o recibe un error.

## Cómo leer el reporte

Al final, el comando muestra una tabla por archivo:

| Columna | Qué significa |
|---|---|
| Leídos | Filas con datos (las líneas en blanco no cuentan). |
| Importados | Filas que guardaron algo: un registro nuevo o algo que le faltaba a uno que ya existía. |
| Omitidos | Filas que no tenían nada nuevo que guardar (ya estaban en la app) o que importa otro comando. La tabla «Omitidos por motivo» los cuenta por motivo. |
| Rechazados | Filas que no se pudieron leer o no cumplen las reglas. No se guardó nada de ellas. |

Debajo vienen, si los hay:

- **Filas rechazadas**: archivo, línea del archivo, id en el sistema anterior y motivo. La línea es la del archivo, contando el encabezado como la 1, así que la puedes buscar en un editor de texto.
- **Avisos**: filas que sí se importaron u omitieron, pero con una salvedad (por ejemplo, un código de asesor que no existe en la app).

En modo de prueba, la última línea dice «Modo de prueba: no se guardó nada.».

## Volver a correrlo

Es seguro. Lo que ya está en la app se reconoce y queda como omitido; nada se duplica. Si corriges unas filas rechazadas y vuelves a correr el comando, solo esas se importan.

## Reglas para todos los archivos

- **Encabezados**: la primera fila tiene los nombres de las columnas, sin importar mayúsculas ni espacios. Si falta una columna, el comando se detiene sin importar nada y te dice cuáles faltan.
- **Separador**: coma o punto y coma; se detecta solo.
- **Codificación**: UTF-8, con o sin BOM. Una fila con caracteres que no son UTF-8 se rechaza. Si son muchas, vuelve a exportar con el conjunto de caracteres utf-8.
- **Vacíos**: un valor vacío o `NULL` cuenta como vacío. Si el campo es obligatorio, la fila se rechaza con «campo vacío: <columna>».
- **Fechas**: `AAAA-MM-DD`. Fechas con hora: `AAAA-MM-DD HH:MM:SS`, con o sin fracción de segundo, con o sin `T` y con o sin zona (`+00`, `+00:00` o `Z`). Una fecha con hora sin zona se toma como hora de Bogotá. Una fecha que no existe (31 de febrero) o con otro formato (`31/02/2024`) rechaza la fila. La fecha cero de MySQL (`0000-00-00` o `0000-00-00 00:00:00`) cuenta como vacía: si la fecha es obligatoria, la fila se rechaza con «fecha vacía: <columna>».
- **Valores**: con punto decimal y sin separador de miles (`2500000.50`).
- **Contraseñas**: nunca se leen, aunque vengan en el archivo.

## Usuarios (`core:migrar-usuarios`)

Lee `fase1_users.csv` y después `supabase_usuarios.csv`.

| Origen | Rol de origen | Rol en la app | Empresas | `slp_codes` |
|---|---|---|---|---|
| Fase 1 | admin | Administrador | SGX y SDC | — |
| Fase 1 | gestor | Analista de cartera | La de `empresa`; ALL es SGX y SDC | — |
| Supabase | asesor | Asesor comercial | SGX | Sus propios códigos de asesor |
| Supabase | gerente | Gerente comercial | SGX | Los asesores de su equipo |
| Supabase | admin | Administrador | SGX | Se ignoran |

- **Un solo rol por persona**, como en la pantalla Equipo. Un usuario nuevo recibe el rol del primer origen que lo trae (Fase 1 va antes que Supabase). Si la persona ya tiene un rol, lo conserva: si el origen dice otro, sale un aviso como «ya tiene el rol analista_cartera; Supabase dice gerente_comercial, se conserva el actual». Si hace falta otro rol, cámbialo en la pantalla Equipo.
- **Mismo correo**: el correo se compara sin espacios y en minúsculas. Si una persona está en los dos sistemas, queda un solo usuario con el rol del primer origen y las empresas de ambos.
- **Usuario nuevo**: se crea con el nombre y el estado (activo o inactivo) del origen, sin contraseña. Entra con su cuenta de Microsoft como cualquier otro.
- **Usuario que ya existe en la app**: no se le cambia el nombre, el estado, el rol ni la cuenta de Microsoft; solo se le agregan las empresas, los asesores y el equipo que le falten.
- **Usuario eliminado en la app**: la fila se rechaza con «el usuario está eliminado en la app». La migración nunca lo restaura; si debe volver, restáuralo en la pantalla Equipo y corre el comando otra vez.
- **Asesores**: a un asesor de Supabase se le vinculan los asesores de SAP de SGX cuyos códigos están en `slp_codes` (acepta `{84,85}`, `[84,85]`, `84,85` o vacío). Un código que no existe en la app, o que ya está vinculado a otra persona, sale como aviso y no se toca.
- **Equipo de un gerente**: los asesores de SAP de SGX cuyos códigos están en `slp_codes` se agregan a su equipo; los que ya tenía se quedan. Un código que no existe en la app sale como aviso.
- **Los códigos siguen al rol que queda**: los de un asesor solo se vinculan si el usuario queda como asesor comercial, y los de un gerente solo van a su equipo si queda como gerente comercial. Si conserva otro rol, no se vinculan y sale un aviso («no se vinculan los códigos 84, 85: el usuario conserva el rol analista_cartera»).
- **Se rechaza la fila** si el correo está vacío o no es válido, si el nombre está vacío, o si el rol, la empresa o el estado no son de los valores esperados.
- Cada usuario creado o cambiado (rol, empresas o equipo) y cada asesor vinculado queda en la auditoría, como en la pantalla Equipo, con el origen «migracion».

## Gestiones (`core:migrar-gestiones`)

Lee `fase1_actions.csv` y después `supabase_gestiones.csv`, y deja cada gestión en la bitácora del cliente. Para encontrar clientes y autores usa también `fase1_clients.csv` (obligatorio si traes `fase1_actions.csv`), `fase1_client_codes.csv` (opcional, pero mejora la búsqueda) y `fase1_users.csv`. Corre antes `core:migrar-usuarios`, para que los autores ya estén en la app.

Los compromisos de pago no se importan aquí: las filas con `tipo` compromiso quedan como omitidas («se importa con core:migrar-compromisos»). Una llamada de Fase 1 que traía fecha de compromiso sí se importa como llamada; el compromiso lo crea el otro comando.

### Cómo se escribe cada gestión

| Origen | `tipo` | Tipo en la app | Resumen | Detalle |
|---|---|---|---|---|
| Fase 1 | llamada | Llamada | «Llamada: <resultado>» (el resultado también queda como resultado de la llamada) | `detalle` |
| Fase 1 | correo | Correo enviado | `resultado` o, si no hay, la primera línea de `detalle` | `detalle` |
| Fase 1 | whatsapp | Nota | «WhatsApp: <resultado o primera línea de detalle>» | `detalle` |
| Fase 1 | nota | Nota | `resultado` o la primera línea de `detalle` | `detalle` |
| Fase 1 | documento | Nota | «Documento: <adjunto_nombre o primera línea de detalle>» | `detalle` |
| Fase 1 | pago | Nota | «Pago registrado: <resultado o primera línea de detalle>» | `detalle` |
| Fase 1 | sistema, con resultado «Cambio de estado» | Cambio de estado | «De «Vencida» a «Jurídico»», como un cambio manual de la app, si `detalle` trae los dos estados; si no, la primera línea de `detalle` | — |
| Fase 1 | sistema, cualquier otro | Sistema | `resultado` o la primera línea de `detalle` | `detalle` |
| Fase 1 | compromiso | — | Omitida: la importa `core:migrar-compromisos` | — |
| Supabase | nota | Nota | La primera línea de `nota` | `nota` |
| Supabase | compromiso | — | Omitida: la importa `core:migrar-compromisos` | — |

- El resumen se corta en 255 caracteres con «…». El detalle no se guarda si es igual al resumen.
- La fecha de la gestión es `created_at` (Fase 1, hora de Bogotá) o `creado_en` (Supabase, con su zona, pasada a hora de Bogotá).
- En los datos extra de la gestión (`metadata`) quedan, cuando vienen: `descuento`, `compromiso_fecha` y `compromiso_valor`; el nombre del adjunto; el `documento` de Supabase; los estados de un cambio de estado; el `resultado` que no quedó en el resumen ni como resultado de la llamada; y el autor que la app no tiene.
- `descuento`, `compromiso_fecha` y `compromiso_valor` nunca rechazan la gestión: si uno no se puede leer (p. ej. `1.500.000` o `31/02/2025`), se guarda tal como viene, como texto, y sale un aviso.
- **Los archivos adjuntos no se migran.** De un documento de Fase 1 solo queda el nombre del archivo y su descripción; el archivo sigue en el servidor de Fase 1.

### Cómo se encuentra el cliente

- **Fase 1**: el cliente de la gestión (`client_id`) se busca en `fase1_clients.csv`; si no está, la fila se rechaza. Luego se busca en la app, en la misma empresa y sin clientes eliminados:
  1. Por código SAP: los `codigo_sn` de ese cliente en `fase1_client_codes.csv` que existen en la app. Si coincide uno, ese es. Si coinciden varios clientes, la fila se rechaza («el cliente 6 tiene varios códigos SAP en la app: C600, C601»).
  2. Si no hay código, por NIT. De cada NIT se toman todos sus dígitos y, si termina en un guion seguido de un solo dígito (el dígito de verificación, como en `900123456-7`), también los dígitos de antes del guion. Dos NIT coinciden si tienen los mismos dígitos, o si los dígitos de uno son los del otro sin su dígito de verificación: `900.123.456` coincide con `900123456-7`, y `900123456-7` con `9001234567`. Un dígito de más sin guion no basta: `1234567` no coincide con `12345678`. Si coincide un cliente, ese es; si coinciden varios, se rechaza («NIT ambiguo…»); si ninguno, se rechaza («cliente no existe en la app (SGX, NIT 900123456)»).
- **Supabase**: el cliente de SGX con el código `codigo_sn`; si no existe, la fila se rechaza.
- Si la empresa de la gestión no es la del cliente, la fila se rechaza.

### Autor

- El autor se busca por correo entre los usuarios de la app que no están eliminados: en Fase 1, el `user_id` se traduce a correo con `fase1_users.csv`; en Supabase, `creado_por` ya es el correo.
- Una gestión de Fase 1 sin `user_id` es del sistema y queda sin autor.
- Si el autor no está en la app, la gestión **sí se importa**, sin autor; su correo (o «usuario Fase 1 #7» si no está en `fase1_users.csv`) queda en `metadata` y sale un aviso.

### Última actividad

Las gestiones importadas escritas por una persona de la app (con autor y de un tipo distinto de «Sistema», la misma regla de la app; un cambio de estado de Fase 1 hecho por una persona cuenta) mueven la última actividad del cliente, solo si son posteriores a la que ya tiene. No cambian el estado del cliente ni envían nada.

### Volver a correrlo

Cada gestión importada guarda su origen en `metadata.legacy_key` (`fase1:actions:<id>` o `supabase:gestiones:<id>`). Una fila cuyo origen ya está en la bitácora, aunque la gestión se haya eliminado en la app, queda como omitida («ya importado»): una gestión eliminada no vuelve.

### Se rechaza la fila

- Si `empresa`, `tipo`, `created_at` o `creado_en` están vacíos, o el tipo no es de los esperados.
- Si `created_at` o `creado_en` no son una fecha válida.
- Si no se encuentra el cliente, como se explica arriba.

## Compromisos (`core:migrar-compromisos`)

Lee los mismos archivos que `core:migrar-gestiones` y crea un compromiso de pago por cada compromiso de los sistemas anteriores. Corre antes `core:migrar-usuarios`: cada compromiso necesita un autor que exista en la app.

### Qué filas son compromisos

- **Fase 1** (`fase1_actions.csv`): las filas con `compromiso_fecha`, de cualquier tipo (una llamada que dejó compromiso queda como llamada en la bitácora y, además, como compromiso), y las de `tipo` compromiso.
- **Supabase** (`supabase_gestiones.csv`): las filas de `tipo` compromiso.
- Las demás filas quedan como omitidas («no es un compromiso»), para que «Leídos» siga siendo el número de filas del archivo.

### Cómo queda cada compromiso

| Campo | Fase 1 | Supabase |
|---|---|---|
| Cliente | Como en las gestiones (código SAP o NIT) | El cliente de SGX con `codigo_sn` |
| Asesor | El asesor actual del cliente en la app | `slp_code` |
| Valor | `compromiso_valor` | `valor_compromiso` |
| Fecha | `compromiso_fecha` | `fecha_compromiso` |
| Notas | `detalle`; en una llamada con resultado, «Llamada: <resultado>» y debajo el detalle | `nota` |
| Autor | El usuario de `user_id` | El usuario de `creado_por` |
| Creado | `created_at` (hora de Bogotá) | `creado_en` |

### Estado

| Origen | Situación | Estado en la app |
|---|---|---|
| Fase 1 | Vence hoy o después (calendario de Bogotá) | Pendiente |
| Fase 1 | Ya venció | Anulado, con la fecha de la importación y el motivo «Importado de Cartera Fase 1 sin estado de cierre.» (Fase 1 no guardaba si se cumplió) |
| Supabase | `abierto` y vence hoy o después | Pendiente |
| Supabase | `abierto` y ya venció | Incumplido, con la fecha de la importación, como lo hace la tarea diaria de incumplidos |
| Supabase | `cumplido` | Cumplido, con la fecha de `actualizado_en` |
| Supabase | `cancelado` | Anulado, con la fecha de `actualizado_en` y el motivo «Anulado en el portal de asesores.» |

### Bitácora y efectos

- Cada compromiso deja en la bitácora del cliente la misma gestión que escribe la app al crear uno («Compromiso de pago por $ … para el …», con las notas como detalle), escrita por su autor y con la fecha de creación del compromiso. Mueve la última actividad del cliente con la regla de las gestiones.
- **No cambia el estado del cliente** ni envía nada. Los estados manuales los trae `core:migrar-estados`.

### Volver a correrlo

La gestión de cada compromiso guarda su origen en `metadata.legacy_key` (`fase1:compromiso:<id>` o `supabase:compromiso:<id>`, distinto del de la gestión, así que una llamada con compromiso no choca). Una fila cuyo origen ya está en la bitácora queda como omitida («ya importado»).

### Se rechaza la fila

- «compromiso sin fecha»: un compromiso sin fecha.
- «compromiso sin valor»: sin valor, o con valor cero o negativo.
- «el autor no existe en la app: <correo o usuario Fase 1 #N>; corre primero core:migrar-usuarios»: el autor no es un usuario de la app.
- Si no se encuentra el cliente, si la empresa no es la del cliente o si una fecha no es válida, como en las gestiones.

## Estados manuales (`core:migrar-estados`)

Lee `fase1_clients.csv` (con `fase1_client_codes.csv` si está, para encontrar los clientes) y lleva a la app los estados manuales de Fase 1: en gestión, compromiso, incumplido y jurídico. Los estados automáticos (al día, por vencer, vencida, pago recibido) no se migran: la app los calcula con cada carga de cartera.

Para cada cliente de Fase 1, en este orden:

| Caso | Resultado |
|---|---|
| `estado` no es un estado de la app | Rechazada: «estado desconocido: <estado>» |
| `estado_manual` no es 1, o el estado no es manual | Omitida: «no es estado manual» |
| El cliente no se encuentra en la app (como en las gestiones) | Rechazada, con el motivo |
| El cliente no tiene saldo pendiente en la app | Omitida: «sin saldo pendiente en la app» (la próxima carga lo devolvería a su estado automático) |
| Ya se aplicó en una corrida anterior, o el cliente ya tiene ese estado | Omitida: «ya aplicado» |
| El cliente ya tiene otro estado manual en la app | Omitida: «ya tiene estado manual en la app: <estado>». La migración nunca pisa lo que alguien decidió en la app. |
| Cualquier otro | Se aplica |

Aplicar un estado hace lo mismo que el cambio manual de la app, pero como el sistema:

- Cambia el estado del cliente y guarda la fecha del cambio.
- Deja en la bitácora una gestión «Cambio de estado» («De «Vencida» a «Jurídico»») sin autor, con el detalle «Importado de Cartera Fase 1» y `metadata` `from`, `to` y `legacy_key` (`fase1:estado:<id del cliente en Fase 1>`). Como no tiene autor, no mueve la última actividad del cliente.
- Audita `core.client.status_changed`, con el origen «migracion», el archivo y la línea.

Volver a correrlo no aplica dos veces el mismo estado, aunque en la app lo hayan cambiado después.
