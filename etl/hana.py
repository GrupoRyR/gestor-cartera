"""Conexion y consultas a SAP HANA (SAP Business One)."""
from __future__ import annotations

import socket
import time
from contextlib import contextmanager
from typing import Any, Iterator

from config import HanaConfig
from registro import obtener, registrar_secreto

log = obtener("hana")

LOTE = 5000  # filas por fetch, para no cargar todo en memoria de golpe


class HanaError(RuntimeError):
    """No se pudo conectar a HANA o ejecutar la consulta."""


def _esperar_puerto(host: str, port: int, espera_total: int = 60) -> bool:
    """Espera a que el puerto responda antes de invocar al driver.

    Sophos reporta el tunel como "conectado" unos segundos antes de terminar de
    instalar las rutas hacia la red interna, asi que una comprobacion inmediata
    da un falso negativo. Se sondea hasta `espera_total` segundos.
    """
    limite = time.monotonic() + espera_total
    primer_intento = True
    while True:
        try:
            with socket.create_connection((host, port), timeout=5):
                return True
        except OSError:
            if time.monotonic() >= limite:
                return False
            if primer_intento:
                log.info("Esperando a que la VPN termine de instalar la ruta "
                         "hacia %s:%s...", host, port)
                primer_intento = False
            time.sleep(2)


@contextmanager
def conectar(cfg: HanaConfig, intentos: int = 3) -> Iterator[Any]:
    """Abre una conexion a HANA. Requiere que la VPN ya este arriba."""
    try:
        from hdbcli import dbapi
    except ImportError as exc:
        raise HanaError(
            "Falta el driver de SAP HANA. Instalalo con:\n"
            "    pip install -r etl/requirements.txt"
        ) from exc

    registrar_secreto(cfg.password)

    if not _esperar_puerto(cfg.host, cfg.port):
        raise HanaError(
            f"No hay ruta hasta {cfg.host}:{cfg.port} tras 60s de espera.\n"
            "Causas habituales:\n"
            "  - La VPN no esta realmente conectada o no enruta esa subred.\n"
            "  - HANA_HOST / HANA_PORT estan mal en el archivo .env "
            "(SAP B1 suele usar 30013 para SYSTEMDB y 30015 para el tenant).\n"
            "  - El firewall del servidor bloquea el puerto."
        )

    conn = None
    ultimo: Exception | None = None
    for intento in range(1, intentos + 1):
        try:
            log.info("Conectando a HANA %s:%s (esquema %s), intento %d/%d...",
                     cfg.host, cfg.port, cfg.esquema, intento, intentos)
            extra = {"currentSchema": cfg.esquema} if cfg.esquema else {}
            conn = dbapi.connect(
                address=cfg.host,
                port=cfg.port,
                user=cfg.usuario,
                password=cfg.password,
                connectTimeout=30000,
                communicationTimeout=cfg.timeout * 1000,
                autocommit=True,
                **extra,
            )
            log.info("Conexion a HANA establecida.")
            break
        except Exception as exc:  # dbapi.Error y derivados
            ultimo = exc
            texto = str(exc).lower()
            if "authentication failed" in texto or "invalid username" in texto:
                raise HanaError(
                    "HANA rechazo el usuario o la contrasena. Revisa "
                    "HANA_USER / HANA_PASSWORD en el archivo .env. "
                    "(No se reintenta para no bloquear el usuario.)"
                ) from exc
            log.warning("Intento %d fallido: %s", intento, exc)
            if intento < intentos:
                time.sleep(5 * intento)

    if conn is None:
        raise HanaError(
            f"No se pudo conectar a HANA tras {intentos} intentos: {ultimo}"
        ) from ultimo

    try:
        yield conn
    finally:
        try:
            conn.close()
            log.info("Conexion a HANA cerrada.")
        except Exception:
            log.debug("Error al cerrar la conexion (se ignora).", exc_info=True)


def ejecutar(
    conn: Any, sql: str, etiqueta: str, esquema: str = ""
) -> tuple[list[str], list[tuple]]:
    """Ejecuta una consulta y devuelve (nombres_de_columna, filas)."""
    inicio = time.monotonic()
    cursor = conn.cursor()
    try:
        log.info("Ejecutando consulta '%s'...", etiqueta)
        cursor.execute(sql)

        if cursor.description is None:
            raise HanaError(f"La consulta '{etiqueta}' no devolvio resultados.")
        columnas = [d[0] for d in cursor.description]

        filas: list[tuple] = []
        while True:
            lote = cursor.fetchmany(LOTE)
            if not lote:
                break
            filas.extend(tuple(f) for f in lote)
            if len(filas) % (LOTE * 10) == 0:
                log.info("  ... %d filas leidas", len(filas))

        segundos = time.monotonic() - inicio
        log.info("Consulta '%s': %d filas en %.1fs.", etiqueta, len(filas), segundos)

        if not filas:
            log.warning(
                "La consulta '%s' devolvio 0 filas. Se aborta la carga para no "
                "borrar los datos buenos que ya estan publicados.", etiqueta
            )
        return columnas, filas
    except HanaError:
        raise
    except Exception as exc:
        texto = str(exc).lower()
        if "invalid table name" in texto or "not found" in texto:
            raise HanaError(
                f"Consulta '{etiqueta}': HANA no encuentra una tabla. "
                f"Lo mas probable es que HANA_SCHEMA ('{esquema}') sea incorrecto. "
                "Debe ser el esquema de SAP B1, por ejemplo SBO_SEGUREX."
            ) from exc
        raise HanaError(f"Error ejecutando la consulta '{etiqueta}': {exc}") from exc
    finally:
        try:
            cursor.close()
        except Exception:
            pass


def listar_esquemas(conn: Any) -> list[str]:
    """Ayuda de diagnostico: esquemas visibles que parecen de SAP B1."""
    cur = conn.cursor()
    try:
        cur.execute('SELECT SCHEMA_NAME FROM "SYS"."SCHEMAS" ORDER BY SCHEMA_NAME')
        nombres = [r[0] for r in cur.fetchall()]
    finally:
        cur.close()
    # Se descartan los esquemas internos de HANA (_SYS_*, SYS, HANA_*).
    return [
        n for n in nombres
        if not n.startswith("_SYS") and n not in {"SYS", "SYSTEM", "PUBLIC"}
        and not n.startswith("HANA_")
    ]
