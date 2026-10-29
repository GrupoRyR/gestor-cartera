"""Publicacion de lotes en Laravel (modulo Ingesta) por HTTPS firmado con HMAC.

Protocolo v1 (debe coincidir con app/Modules/Ingesta):

    POST {INGESTA_URL}/api/ingesta/{lote}
    Cuerpo JSON por columnas: {batch_id, company, cutoff_date, columns, rows},
    comprimido con gzip si INGESTA_GZIP=true.
    Firma: HMAC-SHA256 en hex de la cadena
        "{timestamp}\\nPOST\\n/api/ingesta/{lote}\\n{sha256 hex del cuerpo enviado}"
    en la cabecera X-Ingesta-Signature como "sha256=<hex>".

El batch_id sale del contenido: reenviar la misma extraccion produce el mismo
batch_id y Laravel responde 200 (duplicado) sin cargar dos veces.

Solo usa la biblioteca estandar (urllib): el robot no necesita paquetes nuevos.
"""
from __future__ import annotations

import gzip
import hashlib
import hmac
import http.client
import json
import time
import urllib.error
import urllib.request
from datetime import date, datetime
from decimal import Decimal, InvalidOperation, ROUND_HALF_UP
from typing import Any, Callable

from config import IngestaConfig
from esquema import ENTERO, FECHA, NUMERO, TASA, TEXTO, Columna
from registro import obtener, registrar_secreto

log = obtener("http")

USER_AGENT = "gestor-cartera-robot/1"
# Esperas entre reintentos (segundos): 3 reintentos tras el primer intento.
ESPERAS = (2, 5, 10)
# Un 429 (limite de peticiones de Laravel) se reintenta aparte: espera lo que
# diga Retry-After (60 s si no viene o no es un numero de segundos), hasta 3
# veces y sin pasar de 10 minutos de espera en total.
REINTENTOS_429 = 3
ESPERA_429 = 60
ESPERA_429_TOTAL = 600
# Maximo de errores de validacion (422) que se copian al log y al aviso.
MAX_ERRORES = 10

# Escala y digitos enteros maximos que acepta Laravel por tipo decimal.
# NUMERO se guarda en decimal(18,2): 16 digitos enteros como maximo.
_DECIMALES = {
    NUMERO: (Decimal("0.01"), 16),      # ^-?\d{1,16}(\.\d{1,2})?$
    TASA: (Decimal("0.000001"), 13),    # ^-?\d{1,13}(\.\d{1,6})?$
}

# Respuesta del transporte: (codigo HTTP, cuerpo en bytes, cabeceras con el
# nombre en minusculas).
Transporte = Callable[[str, bytes, dict[str, str], int], tuple[int, bytes, dict[str, str]]]


class HttpSinkError(RuntimeError):
    """El lote no se pudo publicar en Laravel."""

    def __init__(self, mensaje: str, status: int | None = None) -> None:
        super().__init__(mensaje)
        self.status = status


class DatoInvalido(HttpSinkError):
    """Un valor de la consulta no cumple el contrato del lote (esquema.py)."""


class ErrorConexion(Exception):
    """No hubo respuesta HTTP: red caida, DNS, timeout o conexion cortada."""


# ---------------------------------------------------------------- serializar
def _a_entero(valor: Any) -> int:
    if isinstance(valor, bool):
        raise ValueError("es un booleano, se esperaba un entero")
    if isinstance(valor, int):
        return valor
    if isinstance(valor, (Decimal, float)):
        numero = Decimal(repr(valor)) if isinstance(valor, float) else valor
        if numero.is_finite() and numero == numero.to_integral_value():
            return int(numero)
        raise ValueError("no es un numero entero")
    raise ValueError(f"tipo {type(valor).__name__} no admitido para un entero")


def _a_decimal(valor: Any, escala: Decimal, max_enteros: int) -> str:
    if isinstance(valor, bool):
        raise ValueError("es un booleano, se esperaba un numero")
    if isinstance(valor, Decimal):
        numero = valor
    elif isinstance(valor, int):
        numero = Decimal(valor)
    elif isinstance(valor, float):
        # repr da el decimal mas corto que representa el float (2.675, no
        # 2.67499999...), asi el redondeo hacia arriba se aplica a lo esperado.
        numero = Decimal(repr(valor))
    else:
        raise ValueError(f"tipo {type(valor).__name__} no admitido para un numero")
    if not numero.is_finite():
        raise ValueError("no es un numero finito")
    try:
        numero = numero.quantize(escala, rounding=ROUND_HALF_UP)
    except InvalidOperation as exc:
        raise ValueError("fuera de rango") from exc
    if numero == 0:
        numero = numero.copy_abs()  # nunca "-0.00"
    texto = format(numero, "f")
    if len(texto.lstrip("-").split(".")[0]) > max_enteros:
        raise ValueError(f"fuera de rango (mas de {max_enteros} digitos enteros)")
    return texto


def _a_fecha(valor: Any) -> str:
    if isinstance(valor, datetime):
        valor = valor.date()
    if isinstance(valor, date):
        return valor.isoformat()
    raise ValueError(f"tipo {type(valor).__name__} no admitido para una fecha")


def _convertir(valor: Any, columna: Columna) -> Any:
    if columna.tipo == ENTERO:
        return _a_entero(valor)
    if columna.tipo in _DECIMALES:
        escala, max_enteros = _DECIMALES[columna.tipo]
        return _a_decimal(valor, escala, max_enteros)
    if columna.tipo == FECHA:
        return _a_fecha(valor)
    if columna.tipo == TEXTO:
        return str(valor)
    raise ValueError(f"tipo de columna desconocido: {columna.tipo}")


def serializar(
    columnas: list[Columna],
    filas_hana: list[tuple],
    posiciones: list[int],
) -> tuple[list[str], list[list[Any]]]:
    """Convierte las filas de HANA al formato JSON del protocolo.

    Devuelve (nombres pg, filas) con: enteros como int, NUMERO y TASA como
    cadena decimal sin exponente (2 y 6 decimales, redondeo hacia arriba en la
    mitad), fechas "AAAA-MM-DD" y texto como str. Lanza DatoInvalido con la
    columna y la fila si un valor no se puede convertir o si falta uno
    obligatorio (nulo o texto en blanco, igual que la regla required de Laravel).
    """
    nombres = [c.pg for c in columnas]
    salida: list[list[Any]] = []
    for numero_fila, fila in enumerate(filas_hana):
        convertida: list[Any] = []
        for columna, posicion in zip(columnas, posiciones):
            valor = fila[posicion]
            try:
                if valor is None or (
                    columna.requerida and isinstance(valor, str) and not valor.strip()
                ):
                    if columna.requerida:
                        raise ValueError("es obligatoria y llego vacia")
                    convertida.append(None)
                    continue
                convertida.append(_convertir(valor, columna))
            except ValueError as exc:
                muestra = repr(valor)
                if len(muestra) > 80:
                    muestra = muestra[:77] + "..."
                raise DatoInvalido(
                    f"Valor invalido en la fila {numero_fila} "
                    f"(rows.{numero_fila}.{columna.pg}), columna '{columna.pg}' "
                    f"(alias HANA '{columna.hana}'): {exc}. Valor recibido: "
                    f"{muestra}. Revisa la consulta o etl/esquema.py."
                ) from exc
        salida.append(convertida)
    return nombres, salida


# ------------------------------------------------------------ batch y firma
def _json(datos: Any) -> bytes:
    return json.dumps(datos, ensure_ascii=False, separators=(",", ":")).encode("utf-8")


def batch_id(
    lote: str,
    empresa: str,
    corte: date,
    columnas: list[str],
    filas: list[list[Any]],
) -> str:
    """Id idempotente: misma extraccion -> mismo id -> Laravel no la duplica."""
    huella = hashlib.sha256(_json({"columns": columnas, "rows": filas})).hexdigest()
    return f"{lote}-{empresa}-{corte.isoformat()}-{huella[:16]}"


def firmar(secreto: str, timestamp: int, lote: str, cuerpo: bytes) -> str:
    """Valor de X-Ingesta-Signature para los bytes exactos que se envian."""
    canonica = "\n".join((
        str(timestamp),
        "POST",
        f"/api/ingesta/{lote}",
        hashlib.sha256(cuerpo).hexdigest(),
    ))
    firma = hmac.new(
        secreto.encode("utf-8"), canonica.encode("utf-8"), hashlib.sha256
    ).hexdigest()
    return f"sha256={firma}"


# --------------------------------------------------------------- transporte
class _SinRedirecciones(urllib.request.HTTPRedirectHandler):
    """Una redireccion convertiria el POST en GET sin cuerpo: mejor verla."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


_ABRIDOR = urllib.request.build_opener(_SinRedirecciones)


def enviar_http(
    url: str, cuerpo: bytes, cabeceras: dict[str, str], timeout: int
) -> tuple[int, bytes, dict[str, str]]:
    """POST real. Devuelve (codigo, cuerpo, cabeceras) para cualquier
    respuesta HTTP y lanza ErrorConexion cuando no hubo respuesta."""
    peticion = urllib.request.Request(
        url, data=cuerpo, headers=cabeceras, method="POST"
    )
    try:
        with _ABRIDOR.open(peticion, timeout=timeout) as respuesta:
            return respuesta.status, respuesta.read(), _cabeceras(respuesta.headers)
    except urllib.error.HTTPError as exc:
        try:
            contenido = exc.read()
        except Exception:
            contenido = b""
        finally:
            exc.close()
        return exc.code, contenido, _cabeceras(exc.headers)
    except (urllib.error.URLError, http.client.HTTPException, OSError) as exc:
        motivo = getattr(exc, "reason", None) or exc
        raise ErrorConexion(f"{type(exc).__name__}: {motivo}") from exc


def _cabeceras(mensaje: Any) -> dict[str, str]:
    return {nombre.lower(): valor for nombre, valor in (mensaje or {}).items()}


# ----------------------------------------------------------------- publicar
def _espera_429(cabeceras: dict[str, str]) -> int:
    """Segundos de Retry-After; ESPERA_429 si no viene o no es un entero."""
    valor = cabeceras.get("retry-after", "").strip()
    return max(1, int(valor)) if valor.isdigit() else ESPERA_429


def _leer_json(contenido: bytes) -> dict[str, Any]:
    try:
        datos = json.loads(contenido.decode("utf-8"))
    except (UnicodeDecodeError, ValueError):
        return {}
    return datos if isinstance(datos, dict) else {}


def _detalle_422(datos: dict[str, Any]) -> str:
    errores = datos.get("errors")
    if not isinstance(errores, dict) or not errores:
        return ""
    lineas = []
    for campo, mensajes in list(errores.items())[:MAX_ERRORES]:
        if isinstance(mensajes, list):
            mensajes = "; ".join(str(m) for m in mensajes)
        lineas.append(f"\n  - {campo}: {mensajes}")
    if len(errores) > MAX_ERRORES:
        lineas.append(f"\n  ... y {len(errores) - MAX_ERRORES} errores mas.")
    return "".join(lineas)


def _mensaje_rechazo(lote: str, status: int, datos: dict[str, Any]) -> str:
    servidor = str(datos.get("message") or "(sin mensaje)")
    base = f"Laravel rechazo el lote '{lote}' (HTTP {status}): {servidor}"
    if status == 401:
        return (
            f"{base}\nRevisa INGESTA_KEY_ID e INGESTA_SECRET en etl/.env, que "
            "el par exista en INGESTA_HMAC_KEYS de Laravel y que el reloj de "
            "este equipo este en hora."
        )
    if status == 404:
        return (
            f"{base}\nRevisa INGESTA_URL (la raiz del sitio, sin /api) y que "
            "Laravel conozca este lote."
        )
    if status == 413:
        return f"{base}\nEl lote supera el tamano maximo que acepta Laravel."
    if status == 422:
        return base + _detalle_422(datos)
    if 300 <= status < 400:
        return (
            f"{base}\nEs una redireccion: revisa que INGESTA_URL use https:// "
            "y apunte a la raiz correcta del sitio."
        )
    return base


def publicar(
    cfg_http: IngestaConfig,
    lote: str,
    empresa: str,
    corte: date,
    columnas: list[str],
    filas: list[list[Any]],
    *,
    transporte: Transporte | None = None,
    dormir: Callable[[float], None] = time.sleep,
    reloj: Callable[[], float] = time.time,
) -> dict[str, Any]:
    """Envia un lote ya serializado y devuelve el JSON de la respuesta.

    Reintenta sin respuesta (red, timeout) o con 5xx, hasta 3 veces
    (esperas de 2, 5 y 10 s), con el mismo cuerpo y una firma nueva en cada
    intento. Un 429 lleva su propia cuenta: espera Retry-After (60 s si no
    viene), hasta 3 veces y 10 minutos en total. Otro 4xx no se reintenta:
    lanza HttpSinkError con el mensaje del servidor. 201 = carga nueva;
    200 = Laravel ya tenia ese batch_id.
    """
    registrar_secreto(cfg_http.secreto)
    enviar = transporte or enviar_http
    identificador = batch_id(lote, empresa, corte, columnas, filas)
    crudo = _json({
        "batch_id": identificador,
        "company": empresa,
        "cutoff_date": corte.isoformat(),
        "columns": columnas,
        "rows": filas,
    })
    # mtime=0: el mismo contenido produce siempre los mismos bytes comprimidos.
    cuerpo = gzip.compress(crudo, mtime=0) if cfg_http.gzip else crudo
    url = f"{cfg_http.url.rstrip('/')}/api/ingesta/{lote}"
    log.info(
        "Enviando el lote '%s' a Laravel: %d filas, %.1f KB%s, batch_id %s.",
        lote, len(filas), len(cuerpo) / 1024,
        f" (gzip de {len(crudo) / 1024:.1f} KB)" if cfg_http.gzip else "",
        identificador,
    )

    intentos = len(ESPERAS) + 1
    intento = 0
    reintentos_429 = 0
    espera_429_total = 0
    ultimo_error = ""
    ultimo_status: int | None = None
    while intento < intentos:
        intento += 1
        timestamp = int(reloj())
        cabeceras = {
            "Content-Type": "application/json",
            "Accept": "application/json",
            "User-Agent": USER_AGENT,
            "X-Ingesta-Key": cfg_http.key_id,
            "X-Ingesta-Timestamp": str(timestamp),
            "X-Ingesta-Signature": firmar(cfg_http.secreto, timestamp, lote, cuerpo),
        }
        if cfg_http.gzip:
            cabeceras["Content-Encoding"] = "gzip"
        inicio = time.monotonic()
        try:
            status, contenido, respuesta = enviar(url, cuerpo, cabeceras, cfg_http.timeout)
        except ErrorConexion as exc:
            ultimo_status = None
            ultimo_error = f"sin respuesta del servidor ({exc})"
        else:
            datos = _leer_json(contenido)
            if status == 429:
                espera = _espera_429(respuesta)
                if (
                    reintentos_429 >= REINTENTOS_429
                    or espera_429_total + espera > ESPERA_429_TOTAL
                ):
                    raise HttpSinkError(
                        f"Laravel limito las peticiones del lote '{lote}' (HTTP 429) "
                        f"tras {reintentos_429} reintentos y {espera_429_total} s de "
                        f"espera; pedia esperar {espera} s mas. Revisa si alguien "
                        "esta saturando la API de ingesta.",
                        status,
                    )
                reintentos_429 += 1
                espera_429_total += espera
                # Un 429 no gasta los reintentos de red y 5xx.
                intento -= 1
                log.warning(
                    "Laravel limito las peticiones del lote '%s' (HTTP 429): "
                    "reintento %d/%d en %d s.",
                    lote, reintentos_429, REINTENTOS_429, espera,
                )
                dormir(espera)
                continue
            if 200 <= status < 300:
                duplicado = status == 200 or datos.get("duplicate") is True
                if duplicado:
                    log.info(
                        "Laravel ya tenia el lote '%s' (HTTP %d, duplicado): no "
                        "se cargo dos veces.", lote, status,
                    )
                else:
                    log.info(
                        "Lote '%s' aceptado por Laravel (HTTP %d) en %.1fs.",
                        lote, status, time.monotonic() - inicio,
                    )
                return datos
            if status < 500:
                raise HttpSinkError(_mensaje_rechazo(lote, status, datos), status)
            ultimo_status = status
            ultimo_error = (
                f"HTTP {status}: {datos.get('message') or 'error del servidor'}"
            )
        if intento < intentos:
            espera = ESPERAS[intento - 1]
            log.warning(
                "Intento %d/%d del lote '%s' fallo: %s. Se reintenta en %d s.",
                intento, intentos, lote, ultimo_error, espera,
            )
            dormir(espera)

    raise HttpSinkError(
        f"No se pudo publicar el lote '{lote}' en Laravel tras {intentos} "
        f"intentos. Ultimo error: {ultimo_error}",
        ultimo_status,
    )
