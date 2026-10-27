"""Pruebas de la configuracion: lotes y destinos (sin leer ningun .env)."""
from __future__ import annotations

import os
import sys
import unittest
from pathlib import Path
from unittest import mock

import config
from config import AlertaConfig, Config, ConfigError, HanaConfig, IngestaConfig, VpnConfig


def _config(supabase_url: str = "", **ingesta) -> Config:
    return Config(
        # Un archivo que existe seguro, para no depender de Sophos instalado.
        vpn=VpnConfig(Path(sys.executable), "vpn", "usuario", "clave", 90, True),
        hana=HanaConfig("10.0.0.1", 30015, "usuario", "clave", "SEGUREX", 600),
        supabase_url=supabase_url,
        alertas=AlertaConfig("", 587, "", ""),
        ingesta=IngestaConfig(**{"url": "", "key_id": "", "secreto": "", **ingesta}),
        lotes=("cartera", "asesores"),
    )


LARAVEL = {"url": "https://cartera.example.com", "key_id": "k2026a", "secreto": "s3cr3t0"}


class ParsearLotesTest(unittest.TestCase):
    def test_separa_limpia_y_quita_repetidos(self):
        self.assertEqual(
            config.parsear_lotes(" Cartera, asesores ,cartera,"), ("cartera", "asesores")
        )

    def test_por_defecto_son_cartera_y_asesores(self):
        self.assertEqual(
            config.parsear_lotes(config.LOTES_POR_DEFECTO), ("cartera", "asesores")
        )

    def test_un_lote_desconocido_lista_los_validos(self):
        with self.assertRaises(ConfigError) as error:
            config.parsear_lotes("cartera,ventitas")
        self.assertIn("ventitas", str(error.exception))
        self.assertIn("asesores", str(error.exception))

    def test_vacio_es_un_error(self):
        with self.assertRaises(ConfigError):
            config.parsear_lotes(" , ")


class ValidarTest(unittest.TestCase):
    def test_exige_al_menos_un_destino(self):
        with self.assertRaises(ConfigError) as error:
            _config().validar()
        self.assertIn("Un destino", str(error.exception))

    def test_el_diagnostico_no_exige_destino(self):
        _config().validar(requiere_destino=False, requiere_esquema=False)

    def test_basta_laravel_o_supabase(self):
        _config(**LARAVEL).validar()
        _config(supabase_url="postgresql://x").validar()
        self.assertTrue(_config(**LARAVEL).ingesta.habilitada)
        self.assertFalse(_config(supabase_url="postgresql://x").ingesta.habilitada)

    def test_laravel_a_medio_configurar_falla_aunque_haya_supabase(self):
        with self.assertRaises(ConfigError) as error:
            _config(supabase_url="postgresql://x", url=LARAVEL["url"]).validar()
        self.assertIn("INGESTA_KEY_ID", str(error.exception))
        self.assertIn("INGESTA_SECRET", str(error.exception))

    def test_exige_https_salvo_en_local(self):
        with self.assertRaises(ConfigError):
            _config(**{**LARAVEL, "url": "http://cartera.example.com"}).validar()
        for url in ("http://localhost:8000", "http://127.0.0.1", "http://gestor.test"):
            with self.subTest(url=url):
                _config(**{**LARAVEL, "url": url}).validar()

    def test_la_empresa_debe_ser_sgx_o_sdc(self):
        _config(**LARAVEL, empresa="SDC").validar()
        with self.assertRaises(ConfigError) as error:
            _config(**LARAVEL, empresa="XYZ").validar()
        self.assertIn("INGESTA_EMPRESA", str(error.exception))

    def test_el_mensaje_no_incluye_el_secreto(self):
        with self.assertRaises(ConfigError) as error:
            _config(**{**LARAVEL, "url": "ftp://x"}).validar()
        self.assertNotIn(LARAVEL["secreto"], str(error.exception))


class ConfigHanaTest(unittest.TestCase):
    """Variables HANA_* leidas del entorno (patch.dict), sin cargar ningun .env."""

    def _hana(self, **entorno: str) -> HanaConfig:
        with mock.patch.dict(os.environ, entorno, clear=True):
            return config.leer_hana()

    def test_por_defecto_cifra_y_valida_sin_truststore(self):
        hana = self._hana(HANA_HOST="10.0.0.1")
        self.assertTrue(hana.encrypt)
        self.assertTrue(hana.validar_certificado)
        self.assertEqual(hana.truststore, "")

    def test_se_puede_apagar_el_cifrado_y_la_validacion(self):
        for apagado in ("false", "FALSE", "0", "no", "No"):
            with self.subTest(valor=apagado):
                hana = self._hana(HANA_ENCRYPT=apagado, HANA_SSL_VALIDATE=apagado)
                self.assertFalse(hana.encrypt)
                self.assertFalse(hana.validar_certificado)

    def test_acepta_true_1_y_si(self):
        for encendido in ("true", "True", "1", "si", "SI"):
            with self.subTest(valor=encendido):
                hana = self._hana(HANA_ENCRYPT=encendido, HANA_SSL_VALIDATE=encendido)
                self.assertTrue(hana.encrypt)
                self.assertTrue(hana.validar_certificado)

    def test_un_valor_que_no_se_entiende_deja_el_cifrado_encendido(self):
        # Un error de tipeo no debe apagar el cifrado sin querer.
        for raro in ("ture", "flase", "apagado", "off?"):
            with self.subTest(valor=raro):
                hana = self._hana(HANA_ENCRYPT=raro, HANA_SSL_VALIDATE=raro)
                self.assertTrue(hana.encrypt)
                self.assertTrue(hana.validar_certificado)

    def test_lee_la_ruta_del_truststore(self):
        hana = self._hana(HANA_SSL_TRUSTSTORE=r"  C:\robot\certs\hana.pem ")
        self.assertEqual(hana.truststore, r"C:\robot\certs\hana.pem")

    def test_los_constructores_posicionales_siguen_funcionando(self):
        hana = HanaConfig("10.0.0.1", 30015, "u", "p", "SEGUREX", 600)
        self.assertTrue(hana.encrypt)
        self.assertTrue(hana.validar_certificado)


if __name__ == "__main__":
    unittest.main()
