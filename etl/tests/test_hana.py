"""Pruebas de la conexion a HANA con un hdbcli falso: nunca toca SAP ni la VPN."""
from __future__ import annotations

import sys
import types
import unittest
from dataclasses import replace
from unittest import mock

import hana
from config import HanaConfig

BASE = HanaConfig("10.0.0.1", 30015, "usuario", "clave-hana", "SEGUREX", 600)


class ConectarTest(unittest.TestCase):
    def setUp(self):
        self.dbapi = types.ModuleType("hdbcli.dbapi")
        self.dbapi.connect = mock.MagicMock(name="connect")
        hdbcli = types.ModuleType("hdbcli")
        hdbcli.dbapi = self.dbapi
        for parche in (
            mock.patch.dict(sys.modules, {"hdbcli": hdbcli, "hdbcli.dbapi": self.dbapi}),
            mock.patch.object(hana, "_esperar_puerto", return_value=True),
            mock.patch.object(hana.time, "sleep"),
        ):
            parche.start()
            self.addCleanup(parche.stop)

    def _conectar(self, cfg: HanaConfig) -> dict:
        with hana.conectar(cfg):
            pass
        self.dbapi.connect.assert_called_once()
        return self.dbapi.connect.call_args.kwargs

    def test_por_defecto_cifra_y_valida_el_certificado(self):
        with self.assertLogs("cartera.hana", level="INFO") as registro:
            argumentos = self._conectar(BASE)

        self.assertIs(argumentos["encrypt"], True)
        self.assertIs(argumentos["sslValidateCertificate"], True)
        self.assertNotIn("sslTrustStore", argumentos)
        self.assertIn("Conexion a HANA cifrada (TLS)", "\n".join(registro.output))

    def test_hana_encrypt_false_conecta_sin_cifrar_y_avisa(self):
        with self.assertLogs("cartera.hana", level="WARNING") as registro:
            argumentos = self._conectar(replace(BASE, encrypt=False))

        self.assertIs(argumentos["encrypt"], False)
        self.assertIn("sin cifrar", "\n".join(registro.output))

    def test_sin_validar_certificado_lo_deja_en_el_log(self):
        with self.assertLogs("cartera.hana", level="WARNING") as registro:
            argumentos = self._conectar(replace(BASE, validar_certificado=False))

        self.assertIs(argumentos["sslValidateCertificate"], False)
        self.assertIn("HANA_SSL_VALIDATE=false", "\n".join(registro.output))

    def test_pasa_el_truststore_cuando_esta_definido(self):
        ruta = r"C:\robot\certs\hana.pem"
        argumentos = self._conectar(replace(BASE, truststore=ruta))
        self.assertEqual(argumentos["sslTrustStore"], ruta)

    def test_un_error_de_certificado_explica_como_corregirlo(self):
        self.dbapi.connect.side_effect = Exception(
            "(-10709, 'Connection failed (RTE:[300015] SSL certificate validation "
            "failed: host name mismatch')"
        )
        with self.assertRaises(hana.HanaError) as error:
            with hana.conectar(BASE):
                pass

        mensaje = str(error.exception)
        self.assertIn("HANA_SSL_TRUSTSTORE", mensaje)
        self.assertIn("HANA_SSL_VALIDATE=false", mensaje)
        self.assertIn("HANA_ENCRYPT=false", mensaje)

    def test_un_error_que_no_es_de_tls_no_sugiere_desactivar_el_cifrado(self):
        self.dbapi.connect.side_effect = Exception("Connection refused")
        with self.assertRaises(hana.HanaError) as error:
            with hana.conectar(BASE):
                pass

        self.assertEqual(self.dbapi.connect.call_count, 3)
        self.assertNotIn("HANA_ENCRYPT", str(error.exception))

    def test_no_reintenta_un_usuario_rechazado(self):
        self.dbapi.connect.side_effect = Exception("authentication failed")
        with self.assertRaises(hana.HanaError) as error:
            with hana.conectar(BASE):
                pass

        self.assertEqual(self.dbapi.connect.call_count, 1)
        self.assertIn("HANA_USER", str(error.exception))


class DescribirCifradoTest(unittest.TestCase):
    def test_resume_el_modo_para_el_diagnostico(self):
        self.assertEqual(
            hana.describir_cifrado(BASE),
            "cifrado si, validacion de certificado si",
        )
        self.assertEqual(
            hana.describir_cifrado(replace(BASE, validar_certificado=False)),
            "cifrado si, validacion de certificado no",
        )
        self.assertEqual(
            hana.describir_cifrado(replace(BASE, encrypt=False)),
            "cifrado no, validacion de certificado no",
        )


if __name__ == "__main__":
    unittest.main()
