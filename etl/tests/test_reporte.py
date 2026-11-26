"""Pruebas del reporte de fallas a la app: nunca toca la red ni la VPN real."""
from __future__ import annotations

import hashlib
import hmac
import json
import subprocess
import unittest
import uuid
from datetime import datetime, timedelta, timezone
from pathlib import Path
from unittest import mock

import esquema
import hana
import registro
import reporte
from config import ConfigError, IngestaConfig, VpnConfig
from sinks import http_sink
from vpn import RobotVPN, VpnCredenciales, VpnError

SECRETO = "s3cr3t0-de-prueba"
CFG = IngestaConfig("https://cartera.example.com/", "k2026a", SECRETO, timeout=30)
BOGOTA = timezone(timedelta(hours=-5))
HORARIOS = {
    "zona": "America/Bogota",
    "horarios": [
        {"hora": "00:00"}, {"hora": "05:00"}, {"hora": "12:00"},
        {"hora": "16:00", "dias": ["wed"]},
    ],
}


class FalloEtapa(RuntimeError):
    """Como run_etl.FalloEtapa: envuelve el error original con su etapa."""

    def __init__(self, etapa, mensaje):
        super().__init__(mensaje)
        self.etapa = etapa


def _error_vpn_credenciales() -> VpnError:
    """El VpnError real que levanta vpn.py cuando Sophos rechaza la cuenta."""
    robot = RobotVPN(VpnConfig(Path("sccli.exe"), "vpn", "usuario", "clave-vpn", 1, True))
    salida = subprocess.CompletedProcess([], 1, "authentication failed", "")
    with mock.patch.object(RobotVPN, "esta_conectado", return_value=False), \
            mock.patch.object(RobotVPN, "conexion_existe", return_value=True), \
            mock.patch.object(RobotVPN, "_sccli", return_value=salida), \
            mock.patch.object(RobotVPN, "_esperar_tunel", return_value=False):
        try:
            robot.conectar()
        except VpnError as exc:
            return exc
    raise AssertionError("vpn.py no levanto VpnError")


class ClasificarTest(unittest.TestCase):
    def test_cada_error_tiene_su_causa(self):
        casos = [
            (VpnError("Sophos no conecto"), ("vpn", True)),
            (_error_vpn_credenciales(), ("credenciales", False)),
            # Decide la clase, no el texto.
            (VpnCredenciales("Sophos dijo que no"), ("credenciales", False)),
            (hana.HanaSinDriver("Falta el driver de SAP HANA"), ("configuracion", False)),
            (hana.HanaSinRuta("No hay ruta"), ("ruta_hana", True)),
            (hana.HanaConexion("No se pudo conectar a HANA tras 3 intentos"), ("ruta_hana", True)),
            (hana.HanaConexion("Fallo la validacion del certificado", tls=True), ("configuracion", False)),
            (http_sink.ErrorConexion("SSLError: timed out"), ("red", True)),
            (http_sink.HttpSinkError("sin respuesta", None), ("red", True)),
            (http_sink.HttpSinkError("HTTP 503", 503), ("red", True)),
            (http_sink.HttpSinkError("HTTP 401", 401), ("credenciales", False)),
            (http_sink.HttpSinkError("HTTP 422", 422), ("datos", False)),
            (http_sink.HttpSinkError("HTTP 404", 404), ("configuracion", False)),
            (hana.HanaCredenciales("HANA rechazo el usuario"), ("credenciales", False)),
            (http_sink.DatoInvalido("Valor invalido"), ("datos", False)),
            (esquema.ColumnasInesperadas("faltan columnas"), ("datos", False)),
            (hana.HanaError("Error ejecutando la consulta 'cartera'"), ("datos", False)),
            (ConfigError("Falta HANA_HOST"), ("configuracion", False)),
            (ValueError("algo raro"), ("inesperado", False)),
            (None, ("inesperado", False)),
        ]
        for exc, esperado in casos:
            with self.subTest(exc=repr(exc)):
                self.assertEqual(reporte.clasificar(exc), esperado)

    def test_decide_el_error_original_dentro_de_falla_etapa(self):
        envuelto = FalloEtapa("HANA", "fallo")
        envuelto.__cause__ = hana.HanaSinRuta("No hay ruta")
        self.assertEqual(reporte.clasificar(envuelto), ("ruta_hana", True))

    def test_una_cartera_vacia_es_de_datos(self):
        self.assertEqual(
            reporte.clasificar(FalloEtapa("VALIDACION", "0 filas")), ("datos", False)
        )


class SanearTest(unittest.TestCase):
    def test_quita_secretos_rutas_urls_cadenas_e_ips(self):
        registro.registrar_secreto("clave-super-secreta")
        casos = {
            "fallo con clave-super-secreta": "clave-super-secreta",
            "sccli enable -p otraClave1": "otraClave1",
            r"no se pudo leer C:\robot\etl\out\cartera.csv": r"C:\robot",
            r"sin acceso a \\servidor\compartida\certs": "servidor",
            "https://usuario:clave1@cartera.example.com/api": "usuario:clave1",
            "Server=10.1.2.3;Uid=sa;Pwd=Secreta1;": "Secreta1",
            "No hay ruta hasta 10.20.30.40:30015 tras 60s": "10.20.30.40",
            "Connection refused fe80::1ff:fe23:4567:890a": "fe80::1ff",
            "host 2001:0db8:85a3:0000:0000:8a2e:0370:7334 caido": "2001:0db8",
            "password=Abc12345 en el log": "Abc12345",
        }
        for crudo, secreto in casos.items():
            with self.subTest(crudo=crudo):
                self.assertNotIn(secreto, reporte.sanear(crudo))

    def test_conserva_horas_y_texto_y_junta_espacios(self):
        self.assertEqual(
            reporte.sanear("Falla a las 05:20:10\n\n  en   HANA"),
            "Falla a las 05:20:10 en HANA",
        )

    def test_corta_a_300_caracteres(self):
        self.assertEqual(len(reporte.sanear("x" * 2000)), 300)


class FranjaTest(unittest.TestCase):
    def test_la_franja_es_la_ultima_programada(self):
        casos = [
            (datetime(2026, 11, 26, 0, 10, tzinfo=BOGOTA), (26, 0, 0)),
            (datetime(2026, 11, 26, 5, 20, tzinfo=BOGOTA), (26, 5, 0)),
            (datetime(2026, 11, 25, 16, 5, tzinfo=BOGOTA), (25, 16, 0)),  # miercoles
            (datetime(2026, 11, 26, 16, 5, tzinfo=BOGOTA), (26, 12, 0)),  # jueves
            # En UTC: 05:20 UTC son las 00:20 en Bogota.
            (datetime(2026, 11, 26, 5, 20, tzinfo=timezone.utc), (26, 0, 0)),
        ]
        for inicio, (dia, hora, minuto) in casos:
            with self.subTest(inicio=inicio):
                self.assertEqual(
                    reporte.franja(inicio, HORARIOS),
                    datetime(2026, 11, dia, hora, minuto, tzinfo=BOGOTA),
                )

    def test_sin_horarios_usa_el_inicio_truncado_al_minuto(self):
        inicio = datetime(2026, 11, 26, 7, 41, 33, 120, tzinfo=BOGOTA)
        self.assertEqual(
            reporte.franja(inicio, {"horarios": []}),
            datetime(2026, 11, 26, 7, 41, tzinfo=BOGOTA),
        )

    def test_lee_los_horarios_del_robot(self):
        self.assertEqual(reporte.cargar_horarios()["zona"], "America/Bogota")


class Transporte:
    def __init__(self, respuesta):
        self.respuesta = respuesta
        self.llamadas = []

    def __call__(self, url, cuerpo, cabeceras, timeout):
        self.llamadas.append((url, cuerpo, dict(cabeceras), timeout))
        if isinstance(self.respuesta, Exception):
            raise self.respuesta
        status, datos = self.respuesta
        return status, json.dumps(datos).encode("utf-8"), {}


class EnviarTest(unittest.TestCase):
    def _datos(self):
        return reporte.armar(
            "0a9b8c7d-6e5f-4a3b-8c2d-1e0f9a8b7c6d", "SGX",
            datetime(2026, 11, 23, 5, 0, tzinfo=BOGOTA), "HANA",
            hana.HanaSinRuta("No hay ruta hasta 10.0.0.1:30015 tras 60s"),
        )

    def _enviar(self, respuesta, datos=None):
        transporte = Transporte(respuesta)
        resultado = reporte.enviar(
            CFG, datos or self._datos(), transporte=transporte, reloj=lambda: 1792152000.0
        )
        return resultado, transporte

    def test_arma_el_reporte_con_todos_los_campos(self):
        datos = self._datos()
        self.assertEqual(uuid.UUID(datos["report_id"]).version, 4)
        self.assertEqual(datos["run_id"], "0a9b8c7d-6e5f-4a3b-8c2d-1e0f9a8b7c6d")
        self.assertEqual(
            {k: v for k, v in datos.items() if k not in ("report_id", "run_id", "occurred_at")},
            {
                "company": "SGX", "dataset": "cartera",
                "slot": "2026-11-23T05:00:00-05:00", "stage": "HANA",
                "cause": "ruta_hana", "transient": True, "attempt": 1,
                "max_attempts": 1, "final": True,
                "message": "No hay ruta hasta [ip] tras 60s",
            },
        )
        self.assertEqual(datetime.fromisoformat(datos["occurred_at"]).utcoffset(),
                         timedelta(hours=-5))

    def test_firma_para_la_ruta_de_reportes_y_envia_json_plano(self):
        datos = self._datos()
        enviado, transporte = self._enviar((201, {"duplicate": False}), datos)

        self.assertTrue(enviado)
        ((url, cuerpo, cabeceras, timeout),) = transporte.llamadas
        self.assertEqual(url, "https://cartera.example.com/api/ingesta/reportes")
        self.assertEqual(timeout, 30)
        self.assertEqual(json.loads(cuerpo), datos)
        self.assertNotIn("Content-Encoding", cabeceras)
        self.assertEqual(cabeceras["Content-Type"], "application/json")
        self.assertEqual(cabeceras["X-Ingesta-Key"], "k2026a")
        self.assertEqual(cabeceras["X-Ingesta-Timestamp"], "1792152000")
        canonica = "\n".join((
            "1792152000", "POST", "/api/ingesta/reportes", hashlib.sha256(cuerpo).hexdigest(),
        ))
        esperado = hmac.new(SECRETO.encode(), canonica.encode(), hashlib.sha256).hexdigest()
        self.assertEqual(cabeceras["X-Ingesta-Signature"], f"sha256={esperado}")

    def test_un_duplicado_cuenta_como_enviado(self):
        self.assertTrue(self._enviar((200, {"duplicate": True}))[0])

    def test_nunca_lanza_y_devuelve_false_si_no_se_pudo(self):
        for respuesta in (
            http_sink.ErrorConexion("URLError: timed out"),
            (401, {"message": "Firma inválida."}),
            (422, {"message": "invalido", "errors": {"slot": ["mal"]}}),
            (500, {"message": "error"}),
        ):
            with self.subTest(respuesta=repr(respuesta)):
                with self.assertLogs("cartera.reporte", level="WARNING"):
                    self.assertFalse(self._enviar(respuesta)[0])


if __name__ == "__main__":
    unittest.main()
