# Migración de datos

Comandos de Artisan que traen a esta app el histórico de los dos sistemas anteriores:

- **Cartera Fase 1**: la base MySQL que se exporta con phpMyAdmin.
- **Supabase**: el proyecto de los asesores comerciales.

Los comandos no se conectan a ninguna de las dos bases. Leen archivos CSV que tú exportas y dejas en una carpeta, y esa carpeta se les pasa con `--dir`.

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
| `fase1_users.csv` | Fase 1, tabla `users` | `id`, `nombre`, `email`, `rol` (admin o gestor), `empresa` (SGX, SDC o ALL), `activo` (0 o 1) | usuarios |
| `fase1_clients.csv` | Fase 1, tabla `clients` | `id`, `empresa`, `nit`, `nombre`, `estado`, `estado_manual` | gestiones, compromisos, estados |
| `fase1_client_codes.csv` | Fase 1, consulta sobre `invoices` (opcional) | `client_id`, `codigo_sn` | gestiones, compromisos, estados |
| `fase1_actions.csv` | Fase 1, tabla `actions` | `id`, `client_id`, `empresa`, `user_id`, `tipo`, `resultado`, `detalle`, `compromiso_fecha`, `compromiso_valor`, `descuento`, `created_at` | gestiones, compromisos |
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

## Cómo leer el reporte

Al final, el comando muestra una tabla por archivo:

| Columna | Qué significa |
|---|---|
| Leídos | Filas con datos (las líneas en blanco no cuentan). |
| Importados | Filas que guardaron algo: un registro nuevo o algo que le faltaba a uno que ya existía. |
| Omitidos | Filas que no tenían nada nuevo que guardar (ya estaban en la app). |
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
- **Se rechaza la fila** si el correo está vacío o no es válido, si el nombre está vacío, o si el rol, la empresa o el estado no son de los valores esperados.
- Cada usuario creado o cambiado (rol, empresas o equipo) y cada asesor vinculado queda en la auditoría, como en la pantalla Equipo, con el origen «migracion».
