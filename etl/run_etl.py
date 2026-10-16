"""Robot del gestor de cartera SEGUREX.

Flujo (dos fases, a proposito):
    FASE 1 - con VPN:  conectar Sophos -> consultar SAP HANA (una consulta por
                       lote) -> bajar la VPN
    FASE 2 - sin VPN:  validar -> respaldo CSV -> publicar en Laravel (HTTPS
                       firmado) -> publicar en Supabase (respaldo) -> registrar

Se separan porque el perfil VPN es de tunel completo (0.0.0.0/0): manteniendola
arriba, el trafico hacia Laravel y Supabase saldria por la red corporativa y
quedaria a merced de su proxy. Extraemos, cerramos el tunel y recien ahi
publicamos.

Lotes (esquema.LOTES, elegidos con ETL_LOTES o --lotes):
    - cartera y asesores son la base: un fallo de HANA, del contrato de
      columnas o una cartera vacia abortan la corrida completa, como siempre.
    - los demas son de mejor esfuerzo: si fallan se registran y se avisan al
      final, pero nunca frenan la cartera.

Uso:
    python run_etl.py                               # ejecucion normal
    python run_etl.py --diagnostico                 # VPN + HANA + CSV, no publica
    python run_etl.py --diagnostico --lotes ventas  # valida una consulta nueva
    python run_etl.py --sin-vpn                     # asume la VPN ya conectada
"""
from __future__ import annotations

import argparse
import json
import logging
import sys
import time
import traceback
from dataclasses import dataclass
from datetime import date, datetime, timedelta, timezone
from typing import Any

import esquema
import hana
import notificar
import registro
from config import STATE_DIR, ConfigError, cargar_config, leer_query, parsear_lotes
from sinks import csv_sink, http_sink, postgres_sink
from vpn import RobotVPN, VpnError

ARCHIVO_ESTADO = STATE_DIR / "ultima_ejecucion.json"

# Colombia no tiene horario de verano: UTC-5 fijo. No se usa zoneinfo porque
# en Windows no viene la base tzdata y fallaria sin instalar paquetes.
ZONA_COLOMBIA = timezone(timedelta(hours=-5))

# Cualquier fallo de estos lotes aborta la corrida completa.
LOTES_BASE = ("cartera", "asesores")


class FalloEtapa(RuntimeError):
    """Error atribuible a una etapa concreta del proceso."""

    def __init__(self, etapa: str, mensaje: str) -> None:
        super().__init__(mensaje)
        self.etapa = etapa


@dataclass(frozen=True)
class Fallo:
    lote: str
    etapa: str
    mensaje: str


class Corrida:
    """Resultado de cada lote: alimenta el archivo de estado y el aviso final."""

    def __init__(self, orden: list[str], log: logging.Logger) -> None:
        self.orden = orden
        self.log = log
        self.lotes: dict[str, dict[str, Any]] = {
            nombre: {"estado": "PENDIENTE", "filas": None} for nombre in orden
        }
        self.fallos: list[Fallo] = []

    def fallo(self, nombre: str, etapa: str, exc: BaseException) -> None:
        mensaje = str(exc)
        self.log.error("Fallo el lote '%s' en la etapa %s: %s", nombre, etapa, mensaje)
        self.fallos.append(Fallo(nombre, etapa, mensaje))
        info = self.lotes[nombre]
        info["estado"] = "ERROR"
        info.setdefault("errores", []).append({"etapa": etapa, "error": mensaje})

    def omitir(self, nombre: str, motivo: str) -> None:
        self.lotes[nombre].update(estado="OMITIDO", motivo=motivo)

    def fallos_ordenados(self) -> list[Fallo]:
        """La cartera primero; luego el resto en el orden de la corrida."""
        return sorted(self.fallos, key=lambda f: self.orden.index(f.lote))

    def filas(self, nombre: str) -> int | None:
        return self.lotes.get(nombre, {}).get("filas")

    def cerrar(self) -> dict[str, dict[str, Any]]:
        for info in self.lotes.values():
            if info["estado"] == "PENDIENTE":
                info["estado"] = "OK"
        return self.lotes


def guardar_estado(**datos) -> None:
    """Estado local: sobrevive aunque la publicacion sea justamente lo que falla."""
    try:
        STATE_DIR.mkdir(parents=True, exist_ok=True)
        datos["actualizado_en"] = datetime.now(timezone.utc).isoformat()
        ARCHIVO_ESTADO.write_text(
            json.dumps(datos, indent=2, ensure_ascii=False), encoding="utf-8"
        )
    except OSError:
        pass


def hoy_colombia() -> date:
    """Fecha de corte: el dia en Colombia (la corrida de las 00:00 en UTC ya
    seria del dia siguiente)."""
    return datetime.now(ZONA_COLOMBIA).date()


def ordenar_lotes(lotes: tuple[str, ...] | list[str]) -> list[str]:
    """Primero los lotes base: asi un lote nuevo que tumbe la conexion a HANA
    nunca deja sin extraer la cartera."""
    return [n for n in LOTES_BASE if n in lotes] + [
        n for n in lotes if n not in LOTES_BASE
    ]


def main() -> int:
    parser = argparse.ArgumentParser(description="Robot del gestor de cartera SEGUREX")
    parser.add_argument("--diagnostico", action="store_true",
                        help="Prueba VPN, HANA y las consultas sin publicar nada.")
    parser.add_argument("--sin-vpn", action="store_true",
                        help="No gestionar la VPN (asume que ya esta activa).")
    parser.add_argument("--lotes", metavar="LOTE[,LOTE]",
                        help="Lotes a extraer separados por comas; reemplaza "
                             "ETL_LOTES (ej: --lotes ventas).")
    args = parser.parse_args()

    log = registro.configurar()
    inicio_utc = datetime.now(timezone.utc)
    inicio = time.monotonic()
    log.info("=" * 68)
    log.info("Inicio del robot de cartera%s",
             " [MODO DIAGNOSTICO]" if args.diagnostico else "")

    try:
        cfg = cargar_config()
        lotes = parsear_lotes(args.lotes) if args.lotes is not None else cfg.lotes
        cfg.validar(
            requiere_destino=not args.diagnostico,
            requiere_esquema=not args.diagnostico,
        )
    except ConfigError as exc:
        log.error("Configuracion incompleta:\n%s", exc)
        guardar_estado(estado="ERROR", etapa="CONFIGURACION", error=str(exc))
        return 2

    orden = ordenar_lotes(lotes)
    log.info("Lotes de esta corrida: %s", ", ".join(orden))
    corrida = Corrida(orden, log)
    etapa = "INICIO"
    robot = RobotVPN(cfg.vpn)
    extraidos: dict[str, tuple[list[str], list[tuple]]] = {}

    try:
        # ---------------------------------------------- FASE 1: extraccion
        # El corte es el dia de la extraccion: una corrida que extrae antes de
        # medianoche y publica despues sigue siendo del dia en que se extrajo.
        corte = hoy_colombia()
        etapa = "VPN"
        if args.sin_vpn:
            log.info("Se omite la gestion de la VPN (--sin-vpn).")
        else:
            robot.conectar()

        try:
            etapa = "HANA"
            with hana.conectar(cfg.hana) as conn:
                if args.diagnostico:
                    esquemas = hana.listar_esquemas(conn)
                    log.info("Conexion OK. Esquemas visibles: %s",
                             ", ".join(esquemas) or "(ninguno)")
                    if not cfg.hana.esquema:
                        raise FalloEtapa(
                            "HANA",
                            "Conexion correcta, pero HANA_SCHEMA esta vacio. "
                            "Elige uno de los esquemas listados arriba "
                            "(el de SAP B1) y ponlo en el archivo .env.",
                        )

                for nombre in orden:
                    lote = esquema.LOTES[nombre]
                    try:
                        extraidos[nombre] = hana.ejecutar(
                            conn, leer_query(lote.consulta), nombre,
                            cfg.hana.esquema,
                        )
                    except (hana.HanaError, ConfigError) as exc:
                        if nombre in LOTES_BASE:
                            raise
                        corrida.fallo(nombre, "HANA", exc)
        finally:
            if not args.sin_vpn:
                robot.desconectar()

        # ------------------------------------- FASE 2: sin VPN desde aqui
        etapa = "VALIDACION"
        validos = _validar(extraidos, corrida, log)

        etapa = "RESPALDO"
        _respaldar(validos, corrida)

        etapa = "CONTRATO"
        # El diagnostico tambien serializa: asi una consulta nueva se valida
        # contra el contrato de Laravel (tipos y obligatorias) sin publicar.
        listos: dict[str, tuple[list[str], list[list[Any]]]] = {}
        if args.diagnostico or cfg.ingesta.habilitada:
            listos = _serializar(validos, corrida)

        if args.diagnostico:
            return _cerrar_diagnostico(corrida, log)

        if cfg.ingesta.habilitada:
            etapa = "LARAVEL"
            _publicar_laravel(cfg, listos, corte, corrida, log)
        else:
            log.info("INGESTA_URL vacio: no se publica en Laravel.")

        if cfg.supabase_url:
            etapa = "SUPABASE"
            _publicar_supabase(cfg, validos, corrida, log, inicio, inicio_utc)

        duracion = time.monotonic() - inicio
        if corrida.fallos:
            return _cerrar_con_fallos(cfg, log, corrida, duracion)

        log.info("Actualizacion completada en %.1fs: %s.", duracion, _resumen(corrida))
        guardar_estado(estado="OK", lotes=corrida.cerrar(),
                       filas_cartera=corrida.filas("cartera"),
                       filas_asesores=corrida.filas("asesores"),
                       duracion_seg=round(duracion, 1))
        return 0

    except (VpnError, hana.HanaError, postgres_sink.PostgresError,
            esquema.ColumnasInesperadas, FalloEtapa, ConfigError) as exc:
        etapa_fallo = getattr(exc, "etapa", etapa)
        return _fallar(cfg, log, etapa_fallo, exc, inicio, inicio_utc, corrida)

    except KeyboardInterrupt:
        log.warning("Ejecucion cancelada por el usuario.")
        return 130

    except Exception as exc:  # red de seguridad: nada sale sin registrar
        return _fallar(cfg, log, etapa, exc, inicio, inicio_utc, corrida,
                       inesperado=True)


# ------------------------------------------------------------------ etapas
def _validar(
    extraidos: dict[str, tuple[list[str], list[tuple]]],
    corrida: Corrida,
    log: logging.Logger,
) -> dict[str, tuple[esquema.Lote, list[tuple], list[int]]]:
    """Contrato de columnas y regla de lote vacio. Devuelve los lotes validos."""
    validos = {}
    for nombre, (columnas_hana, datos) in extraidos.items():
        lote = esquema.LOTES[nombre]
        corrida.lotes[nombre]["filas"] = len(datos)
        try:
            posiciones = esquema.indices(columnas_hana, lote.columnas)
            if not datos and not lote.permite_vacio:
                if nombre == "cartera":
                    raise FalloEtapa(
                        "VALIDACION",
                        "La consulta de cartera devolvio 0 filas. Se cancela la "
                        "publicacion para no dejar la web sin datos.",
                    )
                if nombre == "asesores":
                    log.warning("Sin asesores en OSLP; no se envian y se "
                                "conservan los datos anteriores.")
                    corrida.omitir(nombre, "0 filas")
                    continue
                raise FalloEtapa(
                    "VALIDACION",
                    f"La consulta del lote '{nombre}' devolvio 0 filas y este "
                    "lote no admite cargas vacias; no se envia.",
                )
        except (FalloEtapa, esquema.ColumnasInesperadas) as exc:
            if nombre in LOTES_BASE:
                raise
            corrida.fallo(nombre, "VALIDACION", exc)
            continue
        validos[nombre] = (lote, datos, posiciones)
    return validos


def _respaldar(
    validos: dict[str, tuple[esquema.Lote, list[tuple], list[int]]],
    corrida: Corrida,
) -> None:
    """CSV local de cada lote extraido (auditoria y plan B)."""
    for nombre, (lote, datos, posiciones) in validos.items():
        try:
            csv_sink.guardar(
                nombre, lote.columnas, [[f[i] for i in posiciones] for f in datos]
            )
        except OSError as exc:
            if nombre in LOTES_BASE:
                raise
            corrida.fallo(nombre, "RESPALDO", exc)


def _serializar(
    validos: dict[str, tuple[esquema.Lote, list[tuple], list[int]]],
    corrida: Corrida,
) -> dict[str, tuple[list[str], list[list[Any]]]]:
    listos = {}
    for nombre, (lote, datos, posiciones) in validos.items():
        try:
            listos[nombre] = http_sink.serializar(lote.columnas, datos, posiciones)
        except http_sink.DatoInvalido as exc:
            corrida.fallo(nombre, "CONTRATO", exc)
    return listos


def _publicar_laravel(cfg, listos, corte: date, corrida: Corrida,
                      log: logging.Logger) -> None:
    """Todos los lotes, aunque uno falle: cada uno es independiente en Laravel.

    corte es la fecha tomada al iniciar la extraccion (FASE 1), no la de ahora.
    """
    log.info("Publicando en Laravel %s (empresa %s, corte %s).",
             cfg.ingesta.url, cfg.ingesta.empresa, corte.isoformat())
    for nombre, (columnas, filas) in listos.items():
        try:
            respuesta = http_sink.publicar(
                cfg.ingesta, nombre, cfg.ingesta.empresa, corte, columnas, filas
            )
        except http_sink.HttpSinkError as exc:
            corrida.fallo(nombre, "LARAVEL", exc)
            continue
        corrida.lotes[nombre]["laravel"] = (
            "duplicado" if respuesta.get("duplicate") else "nuevo"
        )


def _publicar_supabase(cfg, validos, corrida: Corrida, log: logging.Logger,
                       inicio: float, inicio_utc: datetime) -> None:
    """Respaldo mientras viva el portal anterior: cartera y asesores, como antes."""
    tablas = [n for n in LOTES_BASE if n in validos]
    if not tablas:
        log.info("Ningun lote de esta corrida va a Supabase.")
        return
    actual = tablas[0]
    try:
        with postgres_sink.conectar(cfg.supabase_url) as pg:
            postgres_sink.preparar(
                pg, {"cartera": esquema.CARTERA, "asesores": esquema.ASESORES}
            )
            for actual in tablas:
                lote, datos, posiciones = validos[actual]
                postgres_sink.publicar(pg, actual, lote.columnas, datos, posiciones)
                corrida.lotes[actual]["supabase"] = "ok"
            actual = tablas[0]  # un fallo de aqui en adelante es de la corrida
            if "cartera" in tablas:
                postgres_sink.registrar_historico(pg)
            postgres_sink.registrar_run(
                pg, iniciado_en=inicio_utc, estado="OK",
                filas_cartera=corrida.filas("cartera"),
                filas_asesores=corrida.filas("asesores"),
                duracion=time.monotonic() - inicio,
            )
    except Exception as exc:  # respaldo: su fallo no tumba lo ya publicado
        corrida.fallo(actual, "SUPABASE", exc)


# ----------------------------------------------------------------- cierre
def _resumen(corrida: Corrida) -> str:
    return ", ".join(
        f"{nombre} {info['filas'] if info['filas'] is not None else '-'} filas"
        for nombre, info in corrida.lotes.items()
    )


def _cerrar_diagnostico(corrida: Corrida, log: logging.Logger) -> int:
    lotes = corrida.cerrar()
    if corrida.fallos:
        for f in corrida.fallos_ordenados():
            log.error("Diagnostico: el lote '%s' fallo en %s: %s",
                      f.lote, f.etapa, f.mensaje)
        guardar_estado(estado="DIAGNOSTICO_CON_FALLOS", lotes=lotes)
        return 1
    log.info("Diagnostico correcto (%s). No se publico nada; revisa los CSV "
             "en etl/out.", _resumen(corrida))
    guardar_estado(estado="DIAGNOSTICO_OK", lotes=lotes,
                   filas_cartera=corrida.filas("cartera"),
                   filas_asesores=corrida.filas("asesores"))
    return 0


def _cerrar_con_fallos(cfg, log: logging.Logger, corrida: Corrida,
                       duracion: float) -> int:
    """Todos los lotes ya se intentaron: se registra y se avisa una sola vez."""
    fallos = corrida.fallos_ordenados()
    detalle = "\n".join(f"- {f.lote} ({f.etapa}): {f.mensaje}" for f in fallos)
    log.error("La corrida termino con %d fallo(s):\n%s", len(fallos), detalle)
    log.error("Los lotes que fallaron conservan su ultima carga correcta.")
    primero = fallos[0]
    guardar_estado(estado="ERROR", etapa=primero.etapa, error=detalle,
                   lotes=corrida.cerrar(),
                   filas_cartera=corrida.filas("cartera"),
                   filas_asesores=corrida.filas("asesores"),
                   duracion_seg=round(duracion, 1))
    notificar.avisar_fallo(
        cfg.alertas, f"{primero.etapa} ({primero.lote})",
        f"{len(fallos)} fallo(s) en la corrida:\n{detalle}",
    )
    return 1


def _fallar(cfg, log, etapa, exc, inicio, inicio_utc, corrida: Corrida,
            inesperado: bool = False) -> int:
    """Aborto de la corrida completa (VPN, HANA o un lote base)."""
    duracion = time.monotonic() - inicio
    detalle = traceback.format_exc()
    if inesperado:
        log.error("Error inesperado en la etapa %s.", etapa)
        log.debug(detalle)
    log.error("FALLO en la etapa %s: %s", etapa, exc)
    log.error("Los asesores conservan los datos de la ultima carga correcta.")

    filas_cartera = corrida.filas("cartera")
    filas_asesores = corrida.filas("asesores")
    guardar_estado(estado="ERROR", etapa=etapa, error=str(exc),
                   lotes=corrida.lotes, duracion_seg=round(duracion, 1))

    # Intento de dejar rastro en Supabase (puede que sea justo lo que fallo).
    if cfg.supabase_url and etapa != "SUPABASE":
        try:
            with postgres_sink.conectar(cfg.supabase_url) as pg:
                postgres_sink.registrar_run(
                    pg, iniciado_en=inicio_utc, estado="ERROR",
                    filas_cartera=filas_cartera or None,
                    filas_asesores=filas_asesores or None,
                    duracion=duracion, etapa=etapa, error=str(exc),
                )
        except Exception:
            log.warning("Tampoco se pudo registrar el fallo en Supabase.")

    notificar.avisar_fallo(cfg.alertas, etapa, str(exc),
                           detalle if inesperado else "")
    return 1


if __name__ == "__main__":
    sys.exit(main())
