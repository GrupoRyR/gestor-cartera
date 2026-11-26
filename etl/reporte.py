"""Reporte de fallas de carga a la app (POST /api/ingesta/reportes).

Cuando la cartera de una empresa no llega, el robot le cuenta a Laravel que
fallo, en que etapa y por que causa. Usa la misma llave HMAC que los lotes,
firmada para la ruta /api/ingesta/reportes, y JSON sin comprimir (hasta 8 KB).

Un reporte que no se puede enviar solo queda en el log: no hay cola ni
reintento, porque el vigilante de la app avisa igual si la carga no llega.
Cada intento fallido de la corrida manda el suyo (run_etl reintenta).
"""
from __future__ import annotations

import json
import logging
import re
import time
import uuid
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Callable

import esquema
import hana
import registro
from config import ConfigError, IngestaConfig
from sinks import http_sink
from vpn import VpnCredenciales, VpnError

log = registro.obtener("reporte")

ARCHIVO_HORARIOS = Path(__file__).resolve().parent / "horarios.json"
# horarios.json dice America/Bogota: UTC-5 fijo, sin horario de verano (en
# Windows no viene tzdata, igual que en run_etl.ZONA_COLOMBIA).
ZONA = timezone(timedelta(hours=-5))
DIAS = ("mon", "tue", "wed", "thu", "fri", "sat", "sun")
MAX_MENSAJE = 300


# --------------------------------------------------------------- clasificar
def _original(exc: BaseException | None) -> BaseException | None:
    """Saca el error original de un run_etl.FalloEtapa (tiene .etapa)."""
    while exc is not None and hasattr(exc, "etapa") and exc.__cause__ is not None:
        exc = exc.__cause__
    return exc


def clasificar(exc: BaseException | None) -> tuple[str, bool]:
    """(causa, transitoria) del contrato de la app para un error del robot."""
    exc = _original(exc)
    if isinstance(exc, VpnCredenciales):
        return "credenciales", False
    if isinstance(exc, VpnError):
        return "vpn", True
    if isinstance(exc, hana.HanaSinRuta):
        return "ruta_hana", True
    if isinstance(exc, hana.HanaConexion):
        return ("configuracion", False) if exc.tls else ("ruta_hana", True)
    if isinstance(exc, hana.HanaCredenciales):
        return "credenciales", False
    if isinstance(exc, hana.HanaSinDriver):
        return "configuracion", False
    if isinstance(exc, http_sink.ErrorConexion):
        return "red", True
    if isinstance(exc, (http_sink.DatoInvalido, esquema.ColumnasInesperadas, hana.HanaError)):
        return "datos", False
    if isinstance(exc, http_sink.HttpSinkError):
        # Sin status: publicar agoto los reintentos sin respuesta (red caida).
        if exc.status is None or exc.status == 429 or exc.status >= 500:
            return "red", True
        if exc.status == 401:
            return "credenciales", False
        if exc.status in (413, 422):
            return "datos", False
        return "configuracion", False
    if isinstance(exc, ConfigError):
        return "configuracion", False
    if exc is not None and getattr(exc, "etapa", "") == "VALIDACION":
        return "datos", False  # cartera vacia
    return "inesperado", False


# ------------------------------------------------------------------ sanear
_URL_CON_CLAVE = re.compile(r"\b[a-zA-Z][\w+.-]*://[^\s/@]+@\S*")
_PAR = r"[A-Za-z][\w ]*?=[^;]*"
_CADENA_CONEXION = re.compile(rf"(?:{_PAR};)+(?:[A-Za-z][\w ]*?=[^;\s]*)?")
_LLAVES_CONEXION = re.compile(
    r"(?:^|;)\s*(?:password|pwd|uid|user id|server|host)\s*=", re.IGNORECASE
)
_CLAVE_SUELTA = re.compile(r"\b(password|pwd|passwd)\s*[=:]\s*\S+", re.IGNORECASE)
_RUTA_UNC = re.compile(r"\\\\[^\s\\]+\\\S*")
_RUTA_WINDOWS = re.compile(r"\b[A-Za-z]:\\\S*")
_HEX = r"[0-9A-Fa-f]{1,4}"
_IPV6 = re.compile(
    rf"(?<![\w:])(?:(?:{_HEX}:){{7}}{_HEX}|(?:{_HEX}:){{1,6}}(?::{_HEX}){{1,6}}"
    rf"|(?:{_HEX}:){{1,7}}:|::(?:{_HEX}:){{0,5}}{_HEX})(?![\w:])"
)
_IPV4 = re.compile(r"\b(?:\d{1,3}\.){3}\d{1,3}(?::\d+)?\b")


def _redactar_secretos(mensaje: str) -> str:
    """El mismo filtro del log (registro.py): secretos registrados y -p <clave>."""
    record = logging.LogRecord("reporte", logging.ERROR, "", 0, mensaje, (), None)
    registro._RedactarSecretos().filter(record)
    return str(record.msg)


def _cadena(m: re.Match) -> str:
    return "[conexion]" if _LLAVES_CONEXION.search(m.group(0)) else m.group(0)


def sanear(mensaje: str) -> str:
    """Mensaje apto para salir del equipo: sin secretos, rutas, URLs con
    clave, cadenas de conexion ni IPs; espacios juntos y 300 caracteres."""
    texto = _redactar_secretos(str(mensaje))
    texto = _URL_CON_CLAVE.sub("[url]", texto)
    texto = _CADENA_CONEXION.sub(_cadena, texto)
    texto = _CLAVE_SUELTA.sub(r"\1=***", texto)
    texto = _RUTA_UNC.sub("[ruta]", texto)
    texto = _RUTA_WINDOWS.sub("[ruta]", texto)
    texto = _IPV6.sub("[ip]", texto)
    texto = _IPV4.sub("[ip]", texto)
    return " ".join(texto.split())[:MAX_MENSAJE]


# ------------------------------------------------------------------ franja
def cargar_horarios() -> dict[str, Any]:
    """etl/horarios.json; vacio si no se puede leer (franja cae al inicio)."""
    try:
        return json.loads(ARCHIVO_HORARIOS.read_text(encoding="utf-8"))
    except (OSError, ValueError) as exc:
        log.warning("No se pudo leer %s: %s", ARCHIVO_HORARIOS.name, exc)
        return {}


def franja(inicio: datetime, horarios: dict[str, Any]) -> datetime:
    """Hora programada de esta corrida, en Bogota.

    Es la franja de horarios.json mas reciente a la hora del inicio o antes,
    buscando hoy y ayer y respetando "dias" (p. ej. la de las 16:00 solo los
    miercoles). Si ninguna aplica (archivo vacio o ilegible), es el inicio
    truncado al minuto.
    """
    local = inicio.astimezone(ZONA)
    candidatas = []
    for atras in (0, 1):
        dia = local - timedelta(days=atras)
        for entrada in horarios.get("horarios", []):
            dias = entrada.get("dias")
            if dias and DIAS[dia.weekday()] not in dias:
                continue
            hora, minuto = (int(p) for p in str(entrada["hora"]).split(":"))
            programada = dia.replace(hour=hora, minute=minuto, second=0, microsecond=0)
            if programada <= local:
                candidatas.append(programada)
    return max(candidatas, default=local.replace(second=0, microsecond=0))


# ------------------------------------------------------------------- enviar
def armar(
    run_id: str,
    empresa: str,
    hora_programada: datetime,
    etapa: str,
    exc: BaseException | None,
    mensaje: str | None = None,
    *,
    dataset: str = "cartera",
    intento: int = 1,
    max_intentos: int = 1,
    final: bool = True,
) -> dict[str, Any]:
    """Cuerpo del reporte; el mensaje (o str(exc)) sale saneado."""
    causa, transitoria = clasificar(exc)
    return {
        "report_id": str(uuid.uuid4()),
        "run_id": run_id,
        "company": empresa,
        "dataset": dataset,
        "slot": hora_programada.astimezone(ZONA).isoformat(),
        "stage": etapa,
        "cause": causa,
        "transient": transitoria,
        "attempt": intento,
        "max_attempts": max_intentos,
        "final": final,
        "message": sanear(mensaje if mensaje is not None else str(exc)) or "Sin detalle.",
        "occurred_at": datetime.now(ZONA).isoformat(timespec="seconds"),
    }


def enviar(
    cfg_http: IngestaConfig,
    datos: dict[str, Any],
    *,
    transporte: http_sink.Transporte | None = None,
    reloj: Callable[[], float] = time.time,
) -> bool:
    """Envia un reporte. Nunca lanza: True con 201 o 200 (duplicado); si no,
    lo deja en el log y devuelve False."""
    try:
        registro.registrar_secreto(cfg_http.secreto)
        cuerpo = json.dumps(datos, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
        timestamp = int(reloj())
        cabeceras = {
            "Content-Type": "application/json",
            "Accept": "application/json",
            "User-Agent": http_sink.USER_AGENT,
            "X-Ingesta-Key": cfg_http.key_id,
            "X-Ingesta-Timestamp": str(timestamp),
            "X-Ingesta-Signature": http_sink.firmar(
                cfg_http.secreto, timestamp, "reportes", cuerpo
            ),
        }
        url = f"{cfg_http.url.rstrip('/')}/api/ingesta/reportes"
        status, contenido, _ = (transporte or http_sink.enviar_http)(
            url, cuerpo, cabeceras, cfg_http.timeout
        )
    except Exception as exc:  # red, timeout o cualquier otra cosa
        log.warning("No se pudo enviar el reporte de falla de %s/%s: %s",
                    datos.get("company"), datos.get("stage"), sanear(str(exc)))
        return False
    if status in (200, 201):
        log.info("Reporte de falla de %s/%s enviado a la app (HTTP %d).",
                 datos.get("company"), datos.get("stage"), status)
        return True
    try:
        servidor = json.loads(contenido.decode("utf-8")).get("message", "")
    except (UnicodeDecodeError, ValueError, AttributeError):
        servidor = ""
    log.warning("La app rechazo el reporte de falla de %s/%s (HTTP %d): %s",
                datos.get("company"), datos.get("stage"), status,
                sanear(str(servidor)) or "(sin mensaje)")
    return False
