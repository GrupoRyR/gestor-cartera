"""Robot de actualizacion de cartera SEGUREX.

Flujo (dos fases, a proposito):
    FASE 1 - con VPN:  conectar Sophos -> consultar SAP HANA -> bajar la VPN
    FASE 2 - sin VPN:  publicar el snapshot en Supabase -> registrar la ejecucion

Se separan porque el perfil VPN es de tunel completo (0.0.0.0/0): manteniendola
arriba, el trafico hacia Supabase saldria por la red corporativa y quedaria a
merced de su proxy. Extraemos, cerramos el tunel y recien ahi publicamos.

Uso:
    python run_etl.py                # ejecucion normal
    python run_etl.py --diagnostico  # solo prueba VPN + HANA, no escribe nada
    python run_etl.py --sin-vpn      # asume la VPN ya conectada a mano
"""
from __future__ import annotations

import argparse
import json
import sys
import time
import traceback
from datetime import datetime, timezone

import esquema
import hana
import notificar
import registro
from config import STATE_DIR, ConfigError, cargar_config, leer_query
from sinks import csv_sink, postgres_sink
from vpn import RobotVPN, VpnError

ARCHIVO_ESTADO = STATE_DIR / "ultima_ejecucion.json"


class FalloEtapa(RuntimeError):
    """Error atribuible a una etapa concreta del proceso."""

    def __init__(self, etapa: str, mensaje: str) -> None:
        super().__init__(mensaje)
        self.etapa = etapa


def guardar_estado(**datos) -> None:
    """Estado local: sobrevive aunque Supabase sea justamente lo que falla."""
    try:
        STATE_DIR.mkdir(parents=True, exist_ok=True)
        datos["actualizado_en"] = datetime.now(timezone.utc).isoformat()
        ARCHIVO_ESTADO.write_text(
            json.dumps(datos, indent=2, ensure_ascii=False), encoding="utf-8"
        )
    except OSError:
        pass


def main() -> int:
    parser = argparse.ArgumentParser(description="Robot de cartera SEGUREX")
    parser.add_argument("--diagnostico", action="store_true",
                        help="Prueba VPN y HANA sin escribir en Supabase.")
    parser.add_argument("--sin-vpn", action="store_true",
                        help="No gestionar la VPN (asume que ya esta activa).")
    args = parser.parse_args()

    log = registro.configurar()
    inicio_utc = datetime.now(timezone.utc)
    inicio = time.monotonic()
    log.info("=" * 68)
    log.info("Inicio del robot de cartera%s",
             " [MODO DIAGNOSTICO]" if args.diagnostico else "")

    try:
        cfg = cargar_config()
        cfg.validar(
            requiere_supabase=not args.diagnostico,
            requiere_esquema=not args.diagnostico,
        )
    except ConfigError as exc:
        log.error("Configuracion incompleta:\n%s", exc)
        guardar_estado(estado="ERROR", etapa="CONFIGURACION", error=str(exc))
        return 2

    etapa = "INICIO"
    filas_cartera = filas_asesores = 0
    robot = RobotVPN(cfg.vpn)

    try:
        # ---------------------------------------------- FASE 1: extraccion
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

                cols_cartera, datos_cartera = hana.ejecutar(
                    conn, leer_query("cartera.sql"), "cartera", cfg.hana.esquema
                )
                cols_asesores, datos_asesores = hana.ejecutar(
                    conn, leer_query("asesores.sql"), "asesores", cfg.hana.esquema
                )
        finally:
            if not args.sin_vpn:
                robot.desconectar()

        etapa = "VALIDACION"
        if not datos_cartera:
            raise FalloEtapa(
                "VALIDACION",
                "La consulta de cartera devolvio 0 filas. Se cancela la "
                "publicacion para no dejar la web sin datos.",
            )
        pos_cartera = esquema.indices(cols_cartera, esquema.CARTERA)
        pos_asesores = esquema.indices(cols_asesores, esquema.ASESORES)
        filas_cartera, filas_asesores = len(datos_cartera), len(datos_asesores)

        etapa = "RESPALDO"
        csv_sink.guardar(
            "cartera", esquema.CARTERA,
            [[f[i] for i in pos_cartera] for f in datos_cartera],
        )

        if args.diagnostico:
            log.info("Diagnostico correcto: %d filas de cartera, %d asesores. "
                     "No se escribio en Supabase.", filas_cartera, filas_asesores)
            guardar_estado(estado="DIAGNOSTICO_OK",
                           filas_cartera=filas_cartera,
                           filas_asesores=filas_asesores)
            return 0

        # ---------------------------------------------- FASE 2: publicacion
        etapa = "SUPABASE"
        with postgres_sink.conectar(cfg.supabase_url) as pg:
            postgres_sink.preparar(
                pg, {"cartera": esquema.CARTERA, "asesores": esquema.ASESORES}
            )
            postgres_sink.publicar(
                pg, "cartera", esquema.CARTERA, datos_cartera, pos_cartera
            )
            if datos_asesores:
                postgres_sink.publicar(
                    pg, "asesores", esquema.ASESORES, datos_asesores, pos_asesores
                )
            else:
                log.warning("Sin asesores en OSLP; se conserva la tabla anterior.")

            postgres_sink.registrar_historico(pg)

            duracion = time.monotonic() - inicio
            postgres_sink.registrar_run(
                pg, iniciado_en=inicio_utc, estado="OK",
                filas_cartera=filas_cartera, filas_asesores=filas_asesores,
                duracion=duracion,
            )

        duracion = time.monotonic() - inicio
        log.info("Actualizacion completada: %d filas de cartera, %d asesores, "
                 "%.1fs.", filas_cartera, filas_asesores, duracion)
        guardar_estado(estado="OK", filas_cartera=filas_cartera,
                       filas_asesores=filas_asesores,
                       duracion_seg=round(duracion, 1))
        return 0

    except (VpnError, hana.HanaError, postgres_sink.PostgresError,
            esquema.ColumnasInesperadas, FalloEtapa) as exc:
        etapa_fallo = getattr(exc, "etapa", etapa)
        return _fallar(cfg, log, etapa_fallo, exc, inicio, inicio_utc,
                       filas_cartera, filas_asesores)

    except KeyboardInterrupt:
        log.warning("Ejecucion cancelada por el usuario.")
        return 130

    except Exception as exc:  # red de seguridad: nada sale sin registrar
        return _fallar(cfg, log, etapa, exc, inicio, inicio_utc,
                       filas_cartera, filas_asesores, inesperado=True)


def _fallar(cfg, log, etapa, exc, inicio, inicio_utc, filas_cartera,
            filas_asesores, inesperado: bool = False) -> int:
    duracion = time.monotonic() - inicio
    detalle = traceback.format_exc()
    if inesperado:
        log.error("Error inesperado en la etapa %s.", etapa)
        log.debug(detalle)
    log.error("FALLO en la etapa %s: %s", etapa, exc)
    log.error("Los asesores conservan los datos de la ultima carga correcta.")

    guardar_estado(estado="ERROR", etapa=etapa, error=str(exc),
                   duracion_seg=round(duracion, 1))

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
