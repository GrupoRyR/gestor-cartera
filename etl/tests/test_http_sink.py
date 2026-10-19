"""Pruebas del sink HTTP: serializacion, batch_id, firma y reintentos."""
from __future__ import annotations

import gzip
import hashlib
import hmac
import http.server
import json
import socket
import threading
import unittest
from datetime import date, datetime
from decimal import Decimal

from config import IngestaConfig
from esquema import ENTERO, FECHA, NUMERO, TASA, TEXTO, Columna
from sinks import http_sink
from sinks.http_sink import DatoInvalido, ErrorConexion, HttpSinkError

SECRETO = "k2026a-secreto-de-prueba"
CORTE = date(2026, 10, 16)


def _cfg(**cambios) -> IngestaConfig:
    datos = {"url": "https://gestor.example/", "key_id": "k2026a", "secreto": SECRETO}
    datos.update(cambios)
    return IngestaConfig(**datos)


def _uno(columna: Columna, valor):
    """Serializa un solo valor con una sola columna."""
    return http_sink.serializar([columna], [(valor,)], [0])[1][0][0]


class SerializarTest(unittest.TestCase):
    def test_entero_acepta_int_y_decimales_sin_fraccion(self):
        columna = Columna("N", "n", ENTERO)
        self.assertEqual(_uno(columna, 12), 12)
        self.assertEqual(_uno(columna, Decimal("12.000")), 12)
        self.assertEqual(_uno(columna, 12.0), 12)
        self.assertEqual(_uno(columna, -3), -3)
        self.assertIsInstance(_uno(columna, Decimal("7")), int)
        self.assertIsNone(_uno(columna, None))

    def test_entero_rechaza_booleanos_fracciones_y_texto(self):
        columna = Columna("N", "n", ENTERO)
        for valor in (True, Decimal("12.5"), 1.5, "12", float("nan")):
            with self.subTest(valor=valor), self.assertRaises(DatoInvalido):
                _uno(columna, valor)

    def test_numero_redondea_a_dos_decimales_hacia_arriba_en_la_mitad(self):
        columna = Columna("V", "v", NUMERO)
        casos = {
            Decimal("1.005"): "1.01",
            Decimal("-1.005"): "-1.01",
            Decimal("1.004"): "1.00",
            Decimal("1234567.891"): "1234567.89",
            2.675: "2.68",          # el float se lee por su repr, no 2.67499...
            5: "5.00",
            Decimal("1E+3"): "1000.00",  # nunca notacion exponencial
            # decimal(18,2): 16 digitos enteros como maximo.
            Decimal("9999999999999999.99"): "9999999999999999.99",
            Decimal("-9999999999999999.99"): "-9999999999999999.99",
        }
        for valor, esperado in casos.items():
            with self.subTest(valor=valor):
                self.assertEqual(_uno(columna, valor), esperado)

    def test_numero_nunca_devuelve_cero_negativo(self):
        columna = Columna("V", "v", NUMERO)
        for valor in (Decimal("-0"), Decimal("-0.001"), Decimal("-0.00"), -0.0):
            with self.subTest(valor=valor):
                self.assertEqual(_uno(columna, valor), "0.00")

    def test_tasa_usa_seis_decimales(self):
        columna = Columna("T", "t", TASA)
        self.assertEqual(_uno(columna, Decimal("4123.4567895")), "4123.456790")
        self.assertEqual(_uno(columna, Decimal("3")), "3.000000")
        self.assertEqual(_uno(columna, Decimal("1E-7")), "0.000000")
        self.assertEqual(_uno(columna, Decimal("-0.0000001")), "0.000000")
        self.assertEqual(_uno(columna, Decimal("-2.5")), "-2.500000")

    def test_numeros_fuera_de_rango_o_no_finitos_fallan(self):
        for columna, valor in (
            (Columna("V", "v", NUMERO), Decimal("1E+16")),
            (Columna("V", "v", NUMERO), Decimal("99999999999999999.99")),
            (Columna("V", "v", NUMERO), Decimal("9999999999999999.995")),
            (Columna("V", "v", NUMERO), Decimal("1E+40")),
            (Columna("T", "t", TASA), Decimal("1E+13")),
            (Columna("V", "v", NUMERO), Decimal("NaN")),
            (Columna("V", "v", NUMERO), float("inf")),
            (Columna("V", "v", NUMERO), "1.00"),
            (Columna("V", "v", NUMERO), False),
        ):
            with self.subTest(valor=valor), self.assertRaises(DatoInvalido):
                _uno(columna, valor)

    def test_fecha_acepta_date_y_datetime(self):
        columna = Columna("F", "f", FECHA)
        self.assertEqual(_uno(columna, date(2026, 10, 1)), "2026-10-01")
        self.assertEqual(_uno(columna, datetime(2026, 10, 16, 23, 59, 59)), "2026-10-16")
        with self.assertRaises(DatoInvalido):
            _uno(columna, "2026-10-16")

    def test_texto_convierte_numeros_a_cadena(self):
        columna = Columna("Tipo doc", "tipo_doc", TEXTO)
        self.assertEqual(_uno(columna, 13), "13")
        self.assertEqual(_uno(columna, Decimal("13")), "13")
        self.assertEqual(_uno(columna, "Ferretería"), "Ferretería")
        self.assertEqual(_uno(columna, ""), "")

    def test_obligatoria_nula_o_en_blanco_falla_con_columna_y_fila(self):
        columnas = [
            Columna("Código SN", "codigo_sn", TEXTO, requerida=True),
            Columna("Saldo", "saldo", NUMERO, requerida=True),
        ]
        filas = [("C1", Decimal("10")), ("C2", None)]
        with self.assertRaises(DatoInvalido) as error:
            http_sink.serializar(columnas, filas, [0, 1])
        mensaje = str(error.exception)
        self.assertIn("fila 1", mensaje)
        self.assertIn("rows.1.saldo", mensaje)
        self.assertIn("'Saldo'", mensaje)

        with self.assertRaises(DatoInvalido) as error:
            http_sink.serializar(columnas, [("   ", Decimal("1"))], [0, 1])
        self.assertIn("rows.0.codigo_sn", str(error.exception))

    def test_reordena_por_posiciones_y_devuelve_nombres_pg(self):
        columnas = [
            Columna("SlpCode", "slp_code", ENTERO),
            Columna("Nombre", "nombre", TEXTO),
        ]
        nombres, filas = http_sink.serializar(
            columnas, [("Ana", "x", Decimal("12"))], [2, 0]
        )
        self.assertEqual(nombres, ["slp_code", "nombre"])
        self.assertEqual(filas, [[12, "Ana"]])


class BatchIdTest(unittest.TestCase):
    columnas = ["slp_code", "saldo"]
    filas = [[12, "100.00"], [13, "-5.50"]]

    def test_es_determinista_y_cumple_el_formato(self):
        primero = http_sink.batch_id("cartera", "SGX", CORTE, self.columnas, self.filas)
        segundo = http_sink.batch_id("cartera", "SGX", CORTE, self.columnas, self.filas)
        self.assertEqual(primero, segundo)
        self.assertRegex(primero, r"^cartera-SGX-2026-10-16-[0-9a-f]{16}$")
        self.assertRegex(primero, r"^[A-Za-z0-9._:-]{8,120}$")

    def test_la_huella_es_el_sha256_del_json_canonico(self):
        canonico = json.dumps(
            {"columns": self.columnas, "rows": self.filas},
            ensure_ascii=False, separators=(",", ":"),
        ).encode("utf-8")
        huella = hashlib.sha256(canonico).hexdigest()[:16]
        self.assertEqual(
            http_sink.batch_id("cartera", "SGX", CORTE, self.columnas, self.filas),
            f"cartera-SGX-2026-10-16-{huella}",
        )

    def test_cambia_si_cambia_el_contenido_la_empresa_o_el_corte(self):
        base = http_sink.batch_id("cartera", "SGX", CORTE, self.columnas, self.filas)
        otras_filas = [[12, "100.00"], [13, "-5.51"]]
        self.assertNotEqual(
            base, http_sink.batch_id("cartera", "SGX", CORTE, self.columnas, otras_filas)
        )
        self.assertNotEqual(
            base, http_sink.batch_id("cartera", "SDC", CORTE, self.columnas, self.filas)
        )
        self.assertNotEqual(
            base,
            http_sink.batch_id("cartera", "SGX", date(2026, 10, 17), self.columnas, self.filas),
        )


class FirmaTest(unittest.TestCase):
    def test_vector_conocido(self):
        # Vector fijo: Laravel puede verificar su implementacion con el mismo.
        firma = http_sink.firmar(SECRETO, 1792152000, "cartera", b'{"rows":[]}')
        self.assertEqual(
            firma,
            "sha256=ba04577457e09c8e9a6bec8983b85a09559f01b466e48a8f8fb2090e3a5b0fc9",
        )

    def test_coincide_con_un_calculo_independiente(self):
        cuerpo = gzip.compress("{\"company\":\"SGX\",\"nombre\":\"Ñandú\"}".encode("utf-8"))
        esperado = hmac.new(
            SECRETO.encode("utf-8"),
            f"1700000000\nPOST\n/api/ingesta/ventas\n{hashlib.sha256(cuerpo).hexdigest()}".encode("utf-8"),
            hashlib.sha256,
        ).hexdigest()
        self.assertEqual(
            http_sink.firmar(SECRETO, 1700000000, "ventas", cuerpo), f"sha256={esperado}"
        )


class TransporteFalso:
    """Devuelve respuestas en orden; una excepcion en la cola se lanza."""

    def __init__(self, *respuestas):
        self.respuestas = list(respuestas)
        self.llamadas: list[dict] = []

    def __call__(self, url, cuerpo, cabeceras, timeout):
        self.llamadas.append(
            {"url": url, "cuerpo": cuerpo, "cabeceras": dict(cabeceras), "timeout": timeout}
        )
        respuesta = self.respuestas.pop(0)
        if isinstance(respuesta, Exception):
            raise respuesta
        status, datos = respuesta
        return status, json.dumps(datos).encode("utf-8")


class Reloj:
    def __init__(self, inicio: int = 1792152000):
        self.ahora = inicio

    def __call__(self) -> float:
        self.ahora += 7
        return float(self.ahora)


class PublicarTest(unittest.TestCase):
    columnas = ["slp_code", "nombre"]
    filas = [[12, "Ana Pérez"], [13, None]]

    def _publicar(self, transporte, cfg=None, reloj=None):
        self.esperas: list[float] = []
        return http_sink.publicar(
            cfg or _cfg(), "asesores", "SGX", CORTE, self.columnas, self.filas,
            transporte=transporte, dormir=self.esperas.append,
            reloj=reloj or Reloj(),
        )

    def _verificar_firma(self, llamada):
        cabeceras = llamada["cabeceras"]
        self.assertEqual(
            cabeceras["X-Ingesta-Signature"],
            http_sink.firmar(
                SECRETO, int(cabeceras["X-Ingesta-Timestamp"]), "asesores", llamada["cuerpo"]
            ),
        )

    def test_201_envia_cuerpo_gzip_firmado_con_todas_las_cabeceras(self):
        transporte = TransporteFalso((201, {"load": {"id": 1}, "duplicate": False}))
        respuesta = self._publicar(transporte)

        self.assertEqual(respuesta, {"load": {"id": 1}, "duplicate": False})
        self.assertEqual(self.esperas, [])
        (llamada,) = transporte.llamadas
        self.assertEqual(llamada["url"], "https://gestor.example/api/ingesta/asesores")
        self.assertEqual(llamada["timeout"], 120)
        cabeceras = llamada["cabeceras"]
        self.assertEqual(cabeceras["Content-Type"], "application/json")
        self.assertEqual(cabeceras["Accept"], "application/json")
        self.assertEqual(cabeceras["Content-Encoding"], "gzip")
        self.assertEqual(cabeceras["X-Ingesta-Key"], "k2026a")
        self.assertRegex(cabeceras["X-Ingesta-Timestamp"], r"^\d+$")
        self.assertTrue(cabeceras["User-Agent"].startswith("gestor-cartera-robot/"))
        self._verificar_firma(llamada)

        cuerpo = json.loads(gzip.decompress(llamada["cuerpo"]).decode("utf-8"))
        self.assertEqual(
            cuerpo,
            {
                "batch_id": http_sink.batch_id(
                    "asesores", "SGX", CORTE, self.columnas, self.filas
                ),
                "company": "SGX",
                "cutoff_date": "2026-10-16",
                "columns": ["slp_code", "nombre"],
                "rows": [[12, "Ana Pérez"], [13, None]],
            },
        )

    def test_el_mismo_lote_produce_los_mismos_bytes(self):
        primero = TransporteFalso((201, {}))
        segundo = TransporteFalso((201, {}))
        self._publicar(primero)
        self._publicar(segundo)
        self.assertEqual(primero.llamadas[0]["cuerpo"], segundo.llamadas[0]["cuerpo"])

    def test_sin_gzip_envia_json_plano(self):
        transporte = TransporteFalso((201, {}))
        self._publicar(transporte, cfg=_cfg(gzip=False))
        (llamada,) = transporte.llamadas
        self.assertNotIn("Content-Encoding", llamada["cabeceras"])
        self.assertEqual(json.loads(llamada["cuerpo"])["company"], "SGX")
        self._verificar_firma(llamada)

    def test_200_es_duplicado_y_cuenta_como_exito(self):
        transporte = TransporteFalso((200, {"load": {"id": 1}, "duplicate": True}))
        with self.assertLogs("cartera.http", "INFO") as registros:
            respuesta = self._publicar(transporte)
        self.assertTrue(respuesta["duplicate"])
        self.assertEqual(len(transporte.llamadas), 1)
        self.assertTrue(any("duplicado" in linea for linea in registros.output))

    def test_422_falla_de_inmediato_con_el_mensaje_y_hasta_diez_errores(self):
        errores = {f"rows.{i}.nombre": [f"Error {i}."] for i in range(12)}
        transporte = TransporteFalso(
            (422, {"message": "El lote no es valido.", "errors": errores})
        )
        with self.assertRaises(HttpSinkError) as error:
            self._publicar(transporte)
        mensaje = str(error.exception)
        self.assertEqual(error.exception.status, 422)
        self.assertEqual(len(transporte.llamadas), 1)
        self.assertEqual(self.esperas, [])
        self.assertIn("El lote no es valido.", mensaje)
        self.assertIn("rows.0.nombre: Error 0.", mensaje)
        self.assertIn("rows.9.nombre", mensaje)
        self.assertNotIn("rows.10.nombre", mensaje)
        self.assertIn("2 errores mas", mensaje)

    def test_otros_4xx_no_se_reintentan(self):
        for status in (401, 404, 413, 400, 302):
            with self.subTest(status=status):
                transporte = TransporteFalso((status, {"message": "No."}))
                with self.assertRaises(HttpSinkError) as error:
                    self._publicar(transporte)
                self.assertEqual(error.exception.status, status)
                self.assertEqual(len(transporte.llamadas), 1)
                self.assertEqual(self.esperas, [])

    def test_401_dice_que_revisar(self):
        transporte = TransporteFalso((401, {"message": "Firma inválida."}))
        with self.assertRaises(HttpSinkError) as error:
            self._publicar(transporte)
        mensaje = str(error.exception)
        self.assertIn("Firma inválida.", mensaje)
        self.assertIn("INGESTA_SECRET", mensaje)
        self.assertNotIn(SECRETO, mensaje)

    def test_503_y_luego_201_reintenta_con_firma_y_hora_nuevas(self):
        transporte = TransporteFalso((503, {"message": "Mantenimiento"}), (201, {}))
        self._publicar(transporte)
        self.assertEqual(self.esperas, [2])
        primera, segunda = transporte.llamadas
        self.assertEqual(primera["cuerpo"], segunda["cuerpo"])
        self.assertNotEqual(
            primera["cabeceras"]["X-Ingesta-Timestamp"],
            segunda["cabeceras"]["X-Ingesta-Timestamp"],
        )
        self.assertNotEqual(
            primera["cabeceras"]["X-Ingesta-Signature"],
            segunda["cabeceras"]["X-Ingesta-Signature"],
        )
        self._verificar_firma(primera)
        self._verificar_firma(segunda)

    def test_sin_conexion_agota_los_reintentos(self):
        transporte = TransporteFalso(*[ErrorConexion("URLError: sin red")] * 4)
        with self.assertRaises(HttpSinkError) as error:
            self._publicar(transporte)
        self.assertEqual(len(transporte.llamadas), 4)
        self.assertEqual(self.esperas, [2, 5, 10])
        self.assertIn("tras 4 intentos", str(error.exception))
        self.assertIsNone(error.exception.status)

    def test_5xx_persistente_agota_los_reintentos(self):
        transporte = TransporteFalso(*[(500, {"message": "Boom"})] * 4)
        with self.assertRaises(HttpSinkError) as error:
            self._publicar(transporte)
        self.assertEqual(error.exception.status, 500)
        self.assertIn("HTTP 500: Boom", str(error.exception))


class _Manejador(http.server.BaseHTTPRequestHandler):
    """Servidor local de prueba: responde segun la ruta pedida."""

    recibido: dict = {}

    def do_POST(self):  # noqa: N802
        largo = int(self.headers.get("Content-Length", "0"))
        _Manejador.recibido = {
            "ruta": self.path,
            "cuerpo": self.rfile.read(largo),
            "firma": self.headers.get("X-Ingesta-Signature"),
        }
        if self.path.endswith("/redirige"):
            self.send_response(302)
            self.send_header("Location", "https://otro.example/")
            self.end_headers()
            return
        status = 422 if self.path.endswith("/malo") else 201
        contenido = json.dumps({"message": "ok" if status == 201 else "Malo."}).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(contenido)))
        self.end_headers()
        self.wfile.write(contenido)

    def log_message(self, *args):
        pass


class EnviarHttpLocalTest(unittest.TestCase):
    """El transporte real contra un servidor en 127.0.0.1 (sin salir del equipo)."""

    @classmethod
    def setUpClass(cls):
        cls.servidor = http.server.HTTPServer(("127.0.0.1", 0), _Manejador)
        cls.hilo = threading.Thread(target=cls.servidor.serve_forever, daemon=True)
        cls.hilo.start()
        cls.base = f"http://127.0.0.1:{cls.servidor.server_address[1]}"

    @classmethod
    def tearDownClass(cls):
        cls.servidor.shutdown()
        cls.servidor.server_close()

    def test_devuelve_codigo_y_cuerpo(self):
        status, cuerpo = http_sink.enviar_http(
            f"{self.base}/api/ingesta/cartera", b"hola", {"X-Ingesta-Signature": "s"}, 5
        )
        self.assertEqual(status, 201)
        self.assertEqual(json.loads(cuerpo)["message"], "ok")
        self.assertEqual(_Manejador.recibido["cuerpo"], b"hola")
        self.assertEqual(_Manejador.recibido["firma"], "s")

    def test_un_error_http_no_lanza_y_trae_el_cuerpo(self):
        status, cuerpo = http_sink.enviar_http(f"{self.base}/malo", b"{}", {}, 5)
        self.assertEqual(status, 422)
        self.assertEqual(json.loads(cuerpo)["message"], "Malo.")

    def test_no_sigue_redirecciones(self):
        status, _ = http_sink.enviar_http(f"{self.base}/redirige", b"{}", {}, 5)
        self.assertEqual(status, 302)

    def test_puerto_cerrado_es_error_de_conexion(self):
        with socket.socket() as libre:
            libre.bind(("127.0.0.1", 0))
            puerto = libre.getsockname()[1]
        with self.assertRaises(ErrorConexion):
            http_sink.enviar_http(f"http://127.0.0.1:{puerto}/x", b"{}", {}, 5)


if __name__ == "__main__":
    unittest.main()
