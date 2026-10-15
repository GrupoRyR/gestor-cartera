"""Publicacion del snapshot de cartera en Supabase (Postgres)."""
from __future__ import annotations

import time
from contextlib import contextmanager
from datetime import datetime, timezone
from typing import Any, Iterator

from esquema import Columna, ddl
from registro import obtener, registrar_secreto

log = obtener("postgres")


class PostgresError(RuntimeError):
    """No se pudo escribir en la base de datos de la nube."""


DDL_ETL_RUNS = """
CREATE TABLE IF NOT EXISTS "etl_runs" (
    "id"              bigserial PRIMARY KEY,
    "iniciado_en"     timestamptz NOT NULL,
    "terminado_en"    timestamptz,
    "estado"          text NOT NULL,
    "filas_cartera"   integer,
    "filas_asesores"  integer,
    "duracion_seg"    numeric(10,1),
    "etapa_fallida"   text,
    "mensaje_error"   text
);
"""

# Indices que necesita la web: filtro por asesor, por cliente y por vencimiento.
DDL_INDICES = [
    'CREATE INDEX IF NOT EXISTS "ix_cartera_slp"    ON "cartera" ("slp_code");',
    'CREATE INDEX IF NOT EXISTS "ix_cartera_email"  ON "cartera" ("email_asesor");',
    'CREATE INDEX IF NOT EXISTS "ix_cartera_cliente" ON "cartera" ("codigo_sn");',
    'CREATE INDEX IF NOT EXISTS "ix_cartera_venc"   ON "cartera" ("fecha_vencimiento");',
    'CREATE INDEX IF NOT EXISTS "ix_asesores_email" ON "asesores" ("email");',
    'CREATE INDEX IF NOT EXISTS "ix_etl_runs_fin"   ON "etl_runs" ("terminado_en" DESC);',
]


@contextmanager
def conectar(url: str) -> Iterator[Any]:
    try:
        import psycopg
    except ImportError as exc:
        raise PostgresError(
            "Falta el driver de Postgres. Instalalo con:\n"
            "    pip install -r etl/requirements.txt"
        ) from exc

    registrar_secreto(url)
    try:
        conn = psycopg.connect(url, connect_timeout=30, autocommit=False)
    except Exception as exc:
        raise PostgresError(
            f"No se pudo conectar a Supabase: {exc}\n"
            "Revisa SUPABASE_DB_URL en el archivo .env (Project Settings -> "
            "Database -> Connection string -> URI)."
        ) from exc
    try:
        yield conn
    finally:
        conn.close()


def preparar(conn: Any, tablas: dict[str, list[Columna]]) -> None:
    """Crea las tablas e indices si no existen. Idempotente."""
    with conn.cursor() as cur:
        for tabla, columnas in tablas.items():
            cur.execute(ddl(tabla, columnas))
            # La tabla ya existia de una version anterior del ETL: el CREATE
            # de arriba no la toca, asi que las columnas nuevas hay que
            # anadirlas o el COPY fallaria con "column does not exist".
            for c in columnas:
                cur.execute(
                    f'ALTER TABLE "{tabla}" '
                    f'ADD COLUMN IF NOT EXISTS "{c.pg}" {c.tipo};'
                )
        cur.execute(DDL_ETL_RUNS)
        for indice in DDL_INDICES:
            cur.execute(indice)
    conn.commit()
    log.info("Esquema de Postgres verificado.")


def _limpiar(valor: Any) -> Any:
    """SAP suele devolver texto con espacios de relleno."""
    if isinstance(valor, str):
        limpio = valor.strip()
        return limpio if limpio else None
    return valor


def publicar(
    conn: Any,
    tabla: str,
    columnas: list[Columna],
    filas: list[tuple],
    posiciones: list[int],
) -> int:
    """Reemplaza el contenido de `tabla` de forma atomica.

    TRUNCATE + COPY dentro de una sola transaccion: si algo falla, se revierte
    y los asesores siguen viendo los datos de la ejecucion anterior.
    """
    if not filas:
        raise PostgresError(
            f"Se intento publicar 0 filas en '{tabla}'. Se cancela para no "
            "dejar la web sin datos."
        )

    inicio = time.monotonic()
    destino = ", ".join(f'"{c.pg}"' for c in columnas)
    sql_copy = f'COPY "{tabla}" ({destino}) FROM STDIN'

    try:
        with conn.cursor() as cur:
            cur.execute(f'TRUNCATE TABLE "{tabla}";')
            with cur.copy(sql_copy) as copia:
                for fila in filas:
                    copia.write_row([_limpiar(fila[i]) for i in posiciones])
        conn.commit()
    except Exception as exc:
        conn.rollback()
        raise PostgresError(
            f"Fallo la carga de '{tabla}', se revirtio la transaccion y los "
            f"datos anteriores siguen intactos. Detalle: {exc}"
        ) from exc

    segundos = time.monotonic() - inicio
    log.info("Publicadas %d filas en '%s' en %.1fs.", len(filas), tabla, segundos)
    return len(filas)


# ------------------------------------------------------------------ historico
# La foto del dia por asesor. La tabla "cartera" se reemplaza entera en cada
# corrida, asi que sin esto el sistema no tiene memoria y no puede responder
# "voy mejor o peor que la semana pasada".
#
# La fecha es la de Bogota, no UTC: la corrida de medianoche local se guardaria
# como del dia siguiente si se usara UTC, y el historico quedaria corrido un dia.
SQL_HISTORICO = """
INSERT INTO "historico_cartera" (
    "fecha", "slp_code", "documentos", "clientes",
    "saldo", "saldo_base", "sin_vencer", "vencido", "vencido_critico"
)
SELECT
    (now() AT TIME ZONE 'America/Bogota')::date,
    "slp_code",
    count(*),
    count(DISTINCT "codigo_sn"),
    COALESCE(sum("saldo"), 0),
    COALESCE(sum("saldo_base"), 0),
    COALESCE(sum("saldo") FILTER (WHERE "rango" = 'Sin vencer'), 0),
    COALESCE(sum("saldo") FILTER (WHERE "rango" <> 'Sin vencer'), 0),
    COALESCE(sum("saldo") FILTER (
        WHERE "rango" IN ('91-120', '121-150', '151-180', '> 180')), 0)
FROM "cartera"
GROUP BY "slp_code"
ON CONFLICT ("fecha", "slp_code") DO UPDATE SET
    "documentos"      = EXCLUDED."documentos",
    "clientes"        = EXCLUDED."clientes",
    "saldo"           = EXCLUDED."saldo",
    "saldo_base"      = EXCLUDED."saldo_base",
    "sin_vencer"      = EXCLUDED."sin_vencer",
    "vencido"         = EXCLUDED."vencido",
    "vencido_critico" = EXCLUDED."vencido_critico",
    "actualizado_en"  = now();
"""


def registrar_historico(conn: Any) -> int:
    """Guarda la foto del dia por asesor. No aborta el ETL si falla.

    El historico es un extra: si se rompiera, la cartera del dia ya esta
    publicada y los asesores pueden trabajar. Se registra el aviso y sigue.
    """
    try:
        with conn.cursor() as cur:
            cur.execute(SQL_HISTORICO)
            filas = cur.rowcount
        conn.commit()
        log.info("Historico del dia actualizado (%d asesores).", filas)
        return filas
    except Exception as exc:
        conn.rollback()
        log.warning("No se pudo guardar el historico del dia: %s", exc)
        return 0


# ------------------------------------------------------------------ auditoria
def registrar_run(
    conn: Any,
    *,
    iniciado_en: datetime,
    estado: str,
    filas_cartera: int | None = None,
    filas_asesores: int | None = None,
    duracion: float | None = None,
    etapa: str | None = None,
    error: str | None = None,
) -> None:
    """Deja constancia de la ejecucion para que la web sepa si el dato es fresco."""
    with conn.cursor() as cur:
        cur.execute(
            'INSERT INTO "etl_runs" ("iniciado_en", "terminado_en", "estado", '
            '"filas_cartera", "filas_asesores", "duracion_seg", '
            '"etapa_fallida", "mensaje_error") '
            "VALUES (%s, %s, %s, %s, %s, %s, %s, %s);",
            (
                iniciado_en,
                datetime.now(timezone.utc),
                estado,
                filas_cartera,
                filas_asesores,
                round(duracion, 1) if duracion is not None else None,
                etapa,
                (error or "")[:4000] or None,
            ),
        )
    conn.commit()
