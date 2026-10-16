"""Logging del ETL: consola + archivo rotativo, con redaccion de secretos."""
from __future__ import annotations

import logging
import re
import sys
from logging.handlers import RotatingFileHandler

from config import LOGS_DIR

_SECRETOS: list[str] = []


class _RedactarSecretos(logging.Filter):
    """Evita que una contrasena termine escrita en el log."""

    def filter(self, record: logging.LogRecord) -> bool:
        try:
            mensaje = record.getMessage()
        except Exception:
            return True
        for secreto in _SECRETOS:
            if secreto and secreto in mensaje:
                mensaje = mensaje.replace(secreto, "***")
        # Cubre tambien el patron  -p <algo>  de la linea de comandos
        mensaje = re.sub(r"(-p|--password)\s+\S+", r"\1 ***", mensaje)
        record.msg = mensaje
        record.args = ()
        return True


def registrar_secreto(valor: str | None) -> None:
    """Marca un valor para que nunca aparezca en los logs."""
    if valor and len(valor) >= 4 and valor not in _SECRETOS:
        _SECRETOS.append(valor)


def configurar(nivel: int = logging.INFO) -> logging.Logger:
    logger = logging.getLogger("cartera")
    if logger.handlers:
        return logger

    logger.setLevel(nivel)
    formato = logging.Formatter(
        "%(asctime)s | %(levelname)-7s | %(name)s | %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S",
    )
    filtro = _RedactarSecretos()

    consola = logging.StreamHandler(sys.stdout)
    consola.setFormatter(formato)
    consola.addFilter(filtro)
    logger.addHandler(consola)

    LOGS_DIR.mkdir(parents=True, exist_ok=True)
    archivo = RotatingFileHandler(
        LOGS_DIR / "etl.log",
        maxBytes=5 * 1024 * 1024,
        backupCount=10,
        encoding="utf-8",
    )
    archivo.setFormatter(formato)
    archivo.addFilter(filtro)
    logger.addHandler(archivo)

    return logger


def obtener(nombre: str) -> logging.Logger:
    return logging.getLogger(f"cartera.{nombre}")
