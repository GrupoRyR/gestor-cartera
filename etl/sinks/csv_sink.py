"""Respaldo local en CSV de cada extraccion (auditoria y plan B)."""
from __future__ import annotations

import csv
from datetime import datetime
from pathlib import Path

from config import OUT_DIR
from esquema import Columna
from registro import obtener

log = obtener("csv")


def guardar(
    nombre: str,
    columnas: list[Columna],
    filas: list[tuple],
    conservar: int = 30,
) -> Path:
    """Escribe un CSV con marca de tiempo y purga los respaldos antiguos."""
    marca = datetime.now().strftime("%Y%m%d_%H%M")
    ruta = OUT_DIR / f"{nombre}_{marca}.csv"

    with ruta.open("w", newline="", encoding="utf-8-sig") as fh:
        escritor = csv.writer(fh, delimiter=";")
        escritor.writerow([c.pg for c in columnas])
        escritor.writerows(filas)

    log.info("Respaldo CSV: %s (%d filas)", ruta.name, len(filas))
    _purgar(nombre, conservar)
    return ruta


def _purgar(nombre: str, conservar: int) -> None:
    antiguos = sorted(
        OUT_DIR.glob(f"{nombre}_*.csv"),
        key=lambda p: p.stat().st_mtime,
        reverse=True,
    )
    for viejo in antiguos[conservar:]:
        try:
            viejo.unlink()
        except OSError:
            log.debug("No se pudo borrar %s", viejo, exc_info=True)
