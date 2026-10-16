# Robot de SAP del gestor de cartera

El robot extrae datos de SAP Business One (HANA, esquema `SEGUREX`) y los publica en el gestor de cartera (Laravel, módulo Ingesta). Corre en un equipo de Segurex con la VPN Sophos, programado en el Programador de tareas de Windows.

## Qué hace

Cada corrida tiene dos fases, a propósito:

1. **Con VPN:** conecta Sophos, ejecuta en HANA la consulta de cada lote y baja la VPN, aunque algo falle.
2. **Sin VPN:** valida cada lote, guarda el respaldo CSV y publica.

El perfil de la VPN es de túnel completo: con ella arriba, el tráfico hacia Laravel saldría por la red corporativa. Por eso nunca se publica con la VPN conectada.

Al final deja el resultado de cada lote en `etl/state/ultima_ejecucion.json` y, si algo falló, envía un correo de alerta (si configuraste el SMTP).

## Destinos

| Destino | Papel | Qué recibe |
|---|---|---|
| Laravel (`INGESTA_URL`) | Principal | Todos los lotes de `ETL_LOTES`, por HTTPS firmado con HMAC. |
| CSV local (`etl/out`) | Auditoría y plan B | Cada lote extraído; conserva los últimos 30 de cada uno. |
| Supabase (`SUPABASE_DB_URL`) | Respaldo del portal anterior | Solo cartera y asesores, igual que antes. Déjalo vacío cuando el portal anterior se apague. |

Necesitas al menos un destino: Laravel completo (`INGESTA_URL`, `INGESTA_KEY_ID` e `INGESTA_SECRET`) o Supabase.

### Cómo publica en Laravel

- `POST {INGESTA_URL}/api/ingesta/{lote}`, con el cuerpo JSON por columnas y comprimido con gzip.
- La firma es HMAC-SHA256 de la hora, la ruta y el hash del cuerpo exacto que se envía. Laravel rechaza una firma vencida (más de 5 minutos de diferencia), así que el reloj de este equipo debe estar en hora.
- El `batch_id` sale del contenido: si el robot reenvía la misma extracción, Laravel responde «duplicado» y no la carga dos veces.
- Reintenta hasta 3 veces (a los 2, 5 y 10 segundos) solo si no hubo respuesta o si Laravel respondió 5xx. Un 4xx (firma, lote desconocido, cuerpo inválido) no se reintenta: el lote queda fallido con el mensaje de Laravel.
- La fecha de corte es el día en Colombia (UTC−5).

## Configuración

Copia `etl/.env.example` como `etl/.env` y complétalo. El `.env` nunca se sube a Git; los secretos se ocultan en los logs.

| Variable | Para qué |
|---|---|
| `SOPHOS_*` | Usuario y conexión de la VPN Sophos. |
| `HANA_*` | Servidor, usuario y esquema de SAP HANA. |
| `INGESTA_URL` | Raíz del gestor de cartera, sin `/api` (por ejemplo `https://cartera.segurex.com`). Debe ser `https://`; `http://` solo se acepta para un Laravel local. |
| `INGESTA_KEY_ID`, `INGESTA_SECRET` | Par de la llave HMAC. Debe existir en `INGESTA_HMAC_KEYS` del `.env` de Laravel como `id:secreto`. |
| `INGESTA_EMPRESA` | `SGX` (Segurex, por defecto) o `SDC` (Soluciones y Derivados). |
| `INGESTA_TIMEOUT` | Segundos de espera por envío; por defecto 120. |
| `INGESTA_GZIP` | Comprimir el cuerpo; por defecto `true`. |
| `ETL_LOTES` | Lotes que se extraen y publican, separados por comas; por defecto `cartera,asesores`. |
| `SUPABASE_DB_URL` | Respaldo en Supabase (opcional). |
| `ALERTA_*` | Correo de alerta cuando algo falla (opcional). |

### Rotar la llave HMAC

Sin cortar las cargas:

1. En Laravel, agrega el par nuevo junto al viejo: `INGESTA_HMAC_KEYS=k2026a:<viejo>,k2026b:<nuevo>` y corre `php artisan config:cache`.
2. En `etl/.env`, cambia `INGESTA_KEY_ID=k2026b` e `INGESTA_SECRET=<nuevo>`.
3. Cuando las cargas lleguen firmadas con la llave nueva, quita el par viejo en Laravel y vuelve a cachear la configuración.

## Lotes

Cada lote es una consulta de `etl/queries` y su contrato de columnas en `etl/esquema.py` (nombres, tipos, columnas obligatorias, llave natural y si admite 0 filas). Ese archivo es la fuente de verdad: Laravel lo replica, así que cualquier cambio de columnas va en los dos lados.

- **Cartera y asesores** son la base. Si su consulta falla, cambia de columnas o la cartera llega vacía, se aborta toda la corrida y no se publica nada.
- **Los demás lotes** son de mejor esfuerzo: si uno falla, se registra, se avisa al final y la corrida termina con código 1, pero la cartera se publica igual.
- Un lote que no admite 0 filas y llega vacío no se envía; Laravel conserva su última carga.

`ETL_LOTES` decide qué lotes corren. Agrega un lote nuevo solo después de validar su consulta con datos reales.

## Validar una consulta nueva

```powershell
.venv\Scripts\python.exe etl\run_etl.py --diagnostico --lotes ventas
```

El modo diagnóstico conecta la VPN, ejecuta la consulta, baja la VPN, guarda el CSV en `etl/out` y revisa cada fila contra el contrato de `esquema.py` (tipos y columnas obligatorias). No publica en ningún lado. `--lotes` reemplaza a `ETL_LOTES` en esa corrida; acepta varios separados por comas.

Revisa el CSV y compara los totales con el informe equivalente de SAP antes de agregar el lote a `ETL_LOTES`.

## Pruebas

Desde la raíz del repositorio, solo con la biblioteca estándar:

```powershell
python -m unittest discover -s etl/tests -t etl -v
```

Las pruebas no usan la VPN, SAP, Laravel ni Supabase: trabajan con datos de muestra y transportes falsos. La única conexión real es a un servidor de prueba en `127.0.0.1`.

## Programación

`scripts/instalar_tarea.ps1` registra la tarea `CarteraWeb-ETL` con tres corridas diarias:

| Hora | Para qué |
|---|---|
| 00:00 | Corrida principal. |
| 05:00 | Reintento antes de la alerta de Laravel de las 07:00. Repetir una extracción idéntica no duplica nada. |
| 12:00 | Actualización de mediodía. |

Las horas evitan las 06:00 y las 12:30 del robot de cuentas por pagar, que comparte la VPN: si se solapan, uno le baja el túnel al otro.

```powershell
powershell -ExecutionPolicy Bypass -File scripts\instalar_tarea.ps1
powershell -ExecutionPolicy Bypass -File scripts\instalar_tarea.ps1 -Horas 00:00,05:00
powershell -ExecutionPolicy Bypass -File scripts\instalar_tarea.ps1 -Desinstalar
```
