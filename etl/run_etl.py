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

Empresas (ETL_EMPRESAS, opcional): con varias, una sola corrida usa una sola
conexion a la VPN y dentro extrae primero la primera empresa y luego las demas,
cada una de su esquema HANA y con todos sus lotes, y publica cada una en
Ingesta con su empresa. Si una falla, las otras igual se publican; la corrida
termina con error y el aviso dice cual fallo. Sin ETL_EMPRESAS corre solo la de
INGESTA_EMPRESA, como siempre.

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
from contextlib import contextmanager
from dataclasses import dataclass, replace
from datetime import date, datetime, timedelta, timezone
from typing import Any, Callable, Iterator

import esquema
import hana
import notificar
import registro
from config import (
    STATE_DIR, ConfigError, EmpresaConfig, cargar_config, leer_query, parsear_lotes,
)
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

    def __init__(self, orden: list[str], log: logging.Logger, empresa: str = "") -> None:
        self.orden = orden
        self.log = log
        # Vacia con una sola empresa: asi los nombres y avisos son los de siempre.
        self.empresa = empresa
        # Fallo que dejo a la empresa sin publicar (solo con varias empresas).
        self.abortada: Fallo | None = None
        self.lotes: dict[str, dict[str, Any]] = {
            nombre: {"estado": "PENDIENTE", "filas": None} for nombre in orden
        }
        self.fallos: list[Fallo] = []

    def etiqueta(self, nombre: str) -> str:
        return f"{self.empresa}/{nombre}" if self.empresa else nombre

    def fallo(self, nombre: str, etapa: str, exc: BaseException) -> None:
        mensaje = str(exc)
        self.log.error("Fallo el lote '%s' en la etapa %s: %s",
                       self.etiqueta(nombre), etapa, mensaje)
        self.fallos.append(Fallo(nombre, etapa, mensaje))
        info = self.lotes[nombre]
        info["estado"] = "ERROR"
        info.setdefault("errores", []).append({"etapa": etapa, "error": mensaje})

    def omitir(self, nombre: str, motivo: str) -> None:
        self.lotes[nombre].update(estado="OMITIDO", motivo=motivo)

    def abortar(self, etapa: str, exc: BaseException) -> None:
        """La empresa no se publica; las demas siguen."""
        mensaje = str(exc)
        self.log.error("Fallo la empresa %s en la etapa %s: %s",
                       self.empresa, etapa, mensaje)
        self.abortada = Fallo("", etapa, mensaje)
        for info in self.lotes.values():
            if info["estado"] == "PENDIENTE":
                info.update(estado="OMITIDO", motivo=f"fallo la empresa {self.empresa}")

    def con_fallos(self) -> bool:
        return bool(self.abortada or self.fallos)

    def fallos_empresa(self) -> list[tuple[str, str, str]]:
        """(nombre, etapa, mensaje): primero el aborto, luego la cartera y el resto."""
        abortos = (
            [(self.empresa, self.abortada.etapa, self.abortada.mensaje)]
            if self.abortada else []
        )
        return abortos + [
            (self.etiqueta(f.lote), f.etapa, f.mensaje) for f in self.fallos_ordenados()
        ]

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
    empresas = cfg.empresas_efectivas
    multi = len(empresas) > 1
    if multi:
        log.info("Empresas de esta corrida: %s",
                 ", ".join(f"{e.codigo} ({e.esquema})" for e in empresas))
    cfgs = {e.codigo: _cfg_empresa(cfg, e) for e in empresas}
    corridas = {
        e.codigo: Corrida(orden, log, e.codigo if multi else "") for e in empresas
    }
    corrida = corridas[empresas[0].codigo]  # para los abortos de toda la corrida
    etapa = "INICIO"
    robot = RobotVPN(cfg.vpn)
    extraidos: dict[str, dict[str, tuple[list[str], list[tuple]]]] = {}

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
            for emp in empresas:
                with _aislada(multi, corridas[emp.codigo], lambda: etapa):
                    extraidos[emp.codigo] = _extraer(
                        cfgs[emp.codigo], emp, orden, corridas[emp.codigo],
                        args.diagnostico, log,
                    )
        finally:
            if not args.sin_vpn:
                robot.desconectar()

        # ------------------------------------- FASE 2: sin VPN desde aqui
        if not args.diagnostico and not cfg.ingesta.habilitada:
            log.info("INGESTA_URL vacio: no se publica en Laravel.")
        validos_por: dict[str, dict] = {}
        for emp in empresas:
            corrida_e = corridas[emp.codigo]
            if corrida_e.abortada:
                continue
            prefijo = f"{emp.codigo}_" if multi else ""
            with _aislada(multi, corrida_e, lambda: etapa):
                etapa = "VALIDACION"
                validos = _validar(extraidos[emp.codigo], corrida_e, log)
                validos_por[emp.codigo] = validos

                etapa = "RESPALDO"
                _respaldar(validos, corrida_e, prefijo)

                etapa = "CONTRATO"
                # El diagnostico tambien serializa: asi una consulta nueva se
                # valida contra el contrato de Laravel (tipos y obligatorias)
                # sin publicar.
                listos: dict[str, tuple[list[str], list[list[Any]]]] = {}
                if args.diagnostico or cfg.ingesta.habilitada:
                    listos = _serializar(validos, corrida_e)

                if not args.diagnostico and cfg.ingesta.habilitada:
                    etapa = "LARAVEL"
                    _publicar_laravel(cfgs[emp.codigo], listos, corte, corrida_e, log)

        if args.diagnostico:
            return _cerrar_diagnostico(
                corridas, log, hana.describir_cifrado(cfg.hana)
            )

        if cfg.supabase_url:
            etapa = "SUPABASE"
            # Respaldo del portal anterior, que solo es de Segurex.
            destino = "SGX" if multi else empresas[0].codigo
            if destino in validos_por and not corridas[destino].abortada:
                _publicar_supabase(cfg, validos_por[destino], corridas[destino],
                                   log, inicio, inicio_utc)
            else:
                log.info("Sin datos de %s para Supabase; no se publica el respaldo.",
                         destino)

        duracion = time.monotonic() - inicio
        if any(c.con_fallos() for c in corridas.values()):
            return _cerrar_con_fallos(cfg, log, corridas, duracion)

        log.info("Actualizacion completada en %.1fs: %s.", duracion, _resumen(corridas))
        guardar_estado(estado="OK", **_estado(corridas), duracion_seg=round(duracion, 1))
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
def _cfg_empresa(cfg, emp: EmpresaConfig):
    """La configuracion con el esquema HANA y la empresa de Ingesta de emp."""
    return replace(
        cfg,
        hana=replace(cfg.hana, esquema=emp.esquema),
        ingesta=replace(cfg.ingesta, empresa=emp.codigo),
    )


@contextmanager
def _aislada(aislar: bool, corrida: Corrida, etapa: Callable[[], str]) -> Iterator[None]:
    """Con varias empresas, un fallo que abortaria la corrida solo aborta a la
    suya; con una sola se propaga, como siempre."""
    try:
        yield
    except Exception as exc:
        if not aislar:
            raise
        corrida.abortar(getattr(exc, "etapa", etapa()), exc)


def _extraer(cfg, emp: EmpresaConfig, orden: list[str], corrida: Corrida,
             diagnostico: bool, log: logging.Logger
             ) -> dict[str, tuple[list[str], list[tuple]]]:
    """Una consulta por lote contra el esquema de la empresa."""
    extraidos: dict[str, tuple[list[str], list[tuple]]] = {}
    with hana.conectar(cfg.hana) as conn:
        if diagnostico:
            esquemas = hana.listar_esquemas(conn)
            log.info("Conexion OK (%s). Esquemas visibles: %s",
                     hana.describir_cifrado(cfg.hana),
                     ", ".join(esquemas) or "(ninguno)")
            if not emp.esquema:
                variable = f"HANA_SCHEMA_{emp.codigo}" if corrida.empresa else "HANA_SCHEMA"
                raise FalloEtapa(
                    "HANA",
                    f"Conexion correcta, pero {variable} esta vacio. "
                    "Elige uno de los esquemas listados arriba "
                    "(el de SAP B1) y ponlo en el archivo .env.",
                )

        for nombre in orden:
            lote = esquema.LOTES[nombre]
            try:
                extraidos[nombre] = hana.ejecutar(
                    conn, leer_query(lote.consulta, emp.codigo), nombre,
                    cfg.hana.esquema,
                )
            except (hana.HanaError, ConfigError) as exc:
                if nombre in LOTES_BASE:
                    raise
                corrida.fallo(nombre, "HANA", exc)
    return extraidos


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
    prefijo: str = "",
) -> None:
    """CSV local de cada lote extraido (auditoria y plan B).

    prefijo separa por empresa los archivos de una corrida con varias."""
    for nombre, (lote, datos, posiciones) in validos.items():
        try:
            csv_sink.guardar(
                f"{prefijo}{nombre}", lote.columnas, [[f[i] for i in posiciones] for f in datos]
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
def _resumen(corridas: dict[str, Corrida]) -> str:
    def uno(corrida: Corrida) -> str:
        return ", ".join(
            f"{nombre} {info['filas'] if info['filas'] is not None else '-'} filas"
            for nombre, info in corrida.lotes.items()
        )

    if len(corridas) == 1:
        return uno(next(iter(corridas.values())))
    return "; ".join(f"{cod}: {uno(c)}" for cod, c in corridas.items())


def _estado(corridas: dict[str, Corrida]) -> dict[str, Any]:
    """Campos del archivo de estado: los de siempre con una empresa; con varias,
    uno por empresa."""
    def uno(corrida: Corrida) -> dict[str, Any]:
        return {"lotes": corrida.cerrar(),
                "filas_cartera": corrida.filas("cartera"),
                "filas_asesores": corrida.filas("asesores")}

    if len(corridas) == 1:
        return uno(next(iter(corridas.values())))
    return {"empresas": {
        cod: {"estado": "ERROR" if c.con_fallos() else "OK", **uno(c)}
        for cod, c in corridas.items()
    }}


def _fallos(corridas: dict[str, Corrida]) -> list[tuple[str, str, str]]:
    """(nombre, etapa, mensaje) de todas las empresas, en el orden de la corrida."""
    return [f for c in corridas.values() for f in c.fallos_empresa()]


def _cerrar_diagnostico(corridas: dict[str, Corrida], log: logging.Logger,
                        cifrado: str = "") -> int:
    """cifrado: modo TLS de HANA, para confirmar que la conexion cifrada sirve."""
    estado = _estado(corridas)
    fallos = _fallos(corridas)
    if fallos:
        for nombre, etapa, mensaje in fallos:
            log.error("Diagnostico: el lote '%s' fallo en %s: %s", nombre, etapa, mensaje)
        guardar_estado(estado="DIAGNOSTICO_CON_FALLOS", **estado)
        return 1
    log.info("Diagnostico correcto (%s). HANA: %s. No se publico nada; revisa "
             "los CSV en etl/out.", _resumen(corridas), cifrado or "-")
    guardar_estado(estado="DIAGNOSTICO_OK", **estado)
    return 0


def _cerrar_con_fallos(cfg, log: logging.Logger, corridas: dict[str, Corrida],
                       duracion: float) -> int:
    """Todos los lotes y empresas ya se intentaron: se registra y se avisa una sola vez."""
    fallos = _fallos(corridas)
    detalle = "\n".join(
        f"- {nombre} ({etapa}): {mensaje}" for nombre, etapa, mensaje in fallos
    )
    log.error("La corrida termino con %d fallo(s):\n%s", len(fallos), detalle)
    log.error("Lo que fallo conserva su ultima carga correcta.")
    nombre, etapa, _ = fallos[0]
    guardar_estado(estado="ERROR", etapa=etapa, error=detalle,
                   **_estado(corridas), duracion_seg=round(duracion, 1))
    notificar.avisar_fallo(
        cfg.alertas, f"{etapa} ({nombre})",
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
