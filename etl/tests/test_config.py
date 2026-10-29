"""Pruebas de la configuracion: lotes y destinos (sin leer ningun .env)."""
from __future__ import annotations

import os
import sys
import unittest
from pathlib import Path
from unittest import mock

import config
from config import (
    AlertaConfig, Config, ConfigError, EmpresaConfig, HanaConfig, IngestaConfig, VpnConfig,
)


def _config(supabase_url: str = "", empresas=(), **ingesta) -> Config:
    return Config(
        # Un archivo que existe seguro, para no depender de Sophos instalado.
        vpn=VpnConfig(Path(sys.executable), "vpn", "usuario", "clave", 90, True),
        hana=HanaConfig("10.0.0.1", 30015, "usuario", "clave", "SEGUREX", 600),
        supabase_url=supabase_url,
        alertas=AlertaConfig("", 587, "", ""),
        ingesta=IngestaConfig(**{"url": "", "key_id": "", "secreto": "", **ingesta}),
        lotes=("cartera", "asesores"),
        # (codigo, esquema[, usuario, contrasena]); sin ellos, credenciales validas.
        empresas=tuple(EmpresaConfig(*e) if len(e) > 2 else EmpresaConfig(*e, "u", "p")
                       for e in empresas),
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


class EmpresasTest(unittest.TestCase):
    def test_sin_configurar_corre_solo_la_empresa_de_hoy(self):
        self.assertEqual(
            _config().empresas_efectivas,
            (EmpresaConfig("SGX", "SEGUREX", "usuario", "clave"),),
        )
        self.assertEqual(
            _config(empresa="SDC").empresas_efectivas,
            (EmpresaConfig("SDC", "SEGUREX", "usuario", "clave"),),
        )

    def test_las_empresas_configuradas_salen_en_su_orden(self):
        entorno = {
            "ETL_EMPRESAS": " sgx, SDC,sgx ",
            "HANA_SCHEMA_SGX": "SEGUREX",
            "HANA_SCHEMA_SDC": "HBT_SDERIVADOS",
        }
        with mock.patch.dict(os.environ, entorno, clear=True):
            self.assertEqual(
                config.leer_empresas(),
                (EmpresaConfig("SGX", "SEGUREX"), EmpresaConfig("SDC", "HBT_SDERIVADOS")),
            )

    def test_sin_etl_empresas_no_lee_nada(self):
        with mock.patch.dict(os.environ, {"HANA_SCHEMA_SGX": "SEGUREX"}):
            os.environ.pop("ETL_EMPRESAS", None)
            self.assertEqual(config.leer_empresas(), ())

    def test_varias_empresas_no_necesitan_hana_schema(self):
        cfg = _config(
            **LARAVEL, empresas=(("SGX", "SEGUREX"), ("SDC", "HBT_SDERIVADOS"))
        )
        cfg = Config(**{**cfg.__dict__, "hana": HanaConfig("h", 30015, "u", "p", "", 600)})
        cfg.validar()

    def test_cada_empresa_necesita_su_esquema(self):
        with self.assertRaises(ConfigError) as error:
            _config(**LARAVEL, empresas=(("SGX", "SEGUREX"), ("SDC", ""))).validar()
        self.assertIn("HANA_SCHEMA_SDC", str(error.exception))

    def test_una_empresa_desconocida_o_repetida_es_un_error(self):
        with self.assertRaises(ConfigError) as error:
            _config(**LARAVEL, empresas=(("XYZ", "A"),)).validar()
        self.assertIn("ETL_EMPRESAS", str(error.exception))
        with self.assertRaises(ConfigError) as error:
            _config(**LARAVEL, empresas=(("SGX", "A"), ("SGX", "B"))).validar()
        self.assertIn("ETL_EMPRESAS", str(error.exception))

    def test_el_diagnostico_exige_los_esquemas_de_cada_empresa_solo_si_se_piden(self):
        _config(empresas=(("SGX", ""), ("SDC", ""))).validar(
            requiere_destino=False, requiere_esquema=False
        )


class CredencialesPorEmpresaTest(unittest.TestCase):
    """HANA_USER_<EMPRESA> / HANA_PASSWORD_<EMPRESA> con respaldo a las sueltas."""

    def _entorno(self, **entorno: str):
        return mock.patch.dict(os.environ, entorno, clear=True)

    def test_cada_empresa_lee_su_usuario_y_su_contrasena(self):
        with self._entorno(
            ETL_EMPRESAS="SGX,SDC",
            HANA_USER_SGX="u_sgx", HANA_PASSWORD_SGX="p_sgx", HANA_SCHEMA_SGX="SEGUREX",
            HANA_USER_SDC="u_sdc", HANA_PASSWORD_SDC="p_sdc", HANA_SCHEMA_SDC="HBT_SDERIVADOS",
        ):
            self.assertEqual(
                config.leer_empresas(),
                (EmpresaConfig("SGX", "SEGUREX", "u_sgx", "p_sgx"),
                 EmpresaConfig("SDC", "HBT_SDERIVADOS", "u_sdc", "p_sdc")),
            )

    def test_sin_las_de_la_empresa_usa_hana_user_y_hana_password(self):
        with self._entorno(
            ETL_EMPRESAS="SGX,SDC", HANA_USER="comun", HANA_PASSWORD="clave",
            HANA_USER_SDC="u_sdc", HANA_PASSWORD_SDC="p_sdc",
            HANA_SCHEMA_SGX="SEGUREX", HANA_SCHEMA_SDC="HBT_SDERIVADOS",
        ):
            sgx, sdc = config.leer_empresas()
        self.assertEqual((sgx.usuario, sgx.password), ("comun", "clave"))
        self.assertEqual((sdc.usuario, sdc.password), ("u_sdc", "p_sdc"))

    def test_las_credenciales_no_se_mezclan_entre_empresas(self):
        with self._entorno(
            ETL_EMPRESAS="SGX,SDC", HANA_USER_SGX="u_sgx", HANA_PASSWORD_SGX="p_sgx",
            HANA_SCHEMA_SGX="SEGUREX", HANA_SCHEMA_SDC="HBT_SDERIVADOS",
        ):
            _, sdc = config.leer_empresas()
        self.assertEqual((sdc.usuario, sdc.password), ("", ""))

    def test_una_sola_empresa_lee_las_de_ingesta_empresa(self):
        with self._entorno(
            HANA_USER_SDC="u_sdc", HANA_PASSWORD_SDC="p_sdc", HANA_SCHEMA_SDC="HBT_SDERIVADOS",
            HANA_USER="otro", HANA_PASSWORD="otra", HANA_SCHEMA="SEGUREX",
        ):
            hana = config.leer_hana("SDC")
        self.assertEqual(
            (hana.usuario, hana.password, hana.esquema), ("u_sdc", "p_sdc", "HBT_SDERIVADOS")
        )

    def test_una_sola_empresa_cae_a_las_sueltas(self):
        with self._entorno(HANA_USER="u", HANA_PASSWORD="p", HANA_SCHEMA="SEGUREX"):
            hana = config.leer_hana("SGX")
        self.assertEqual((hana.usuario, hana.password, hana.esquema), ("u", "p", "SEGUREX"))

    def test_la_empresa_efectiva_de_una_sola_lleva_sus_credenciales(self):
        cfg = _config()
        self.assertEqual(
            cfg.empresas_efectivas, (EmpresaConfig("SGX", "SEGUREX", "usuario", "clave"),)
        )

    def test_faltan_el_usuario_y_la_contrasena_de_una_empresa(self):
        with self.assertRaises(ConfigError) as error:
            _config(
                **LARAVEL,
                empresas=(("SGX", "SEGUREX", "u", "p"), ("SDC", "HBT_SDERIVADOS", "", "")),
            ).validar()
        mensaje = str(error.exception)
        self.assertIn("HANA_USER_SDC", mensaje)
        self.assertIn("HANA_PASSWORD_SDC", mensaje)
        self.assertNotIn("HANA_USER_SGX", mensaje)
        self.assertNotIn("HANA_PASSWORD_SGX", mensaje)

    def test_nombra_la_variable_exacta_de_cada_faltante(self):
        with self.assertRaises(ConfigError) as error:
            _config(
                **LARAVEL, empresas=(("SGX", "", "", "p"), ("SDC", "HBT_SDERIVADOS", "u", "")),
            ).validar()
        mensaje = str(error.exception)
        for esperada in ("HANA_USER_SGX", "HANA_SCHEMA_SGX", "HANA_PASSWORD_SDC"):
            self.assertIn(esperada, mensaje)
        for sobrante in ("HANA_PASSWORD_SGX", "HANA_USER_SDC", "HANA_SCHEMA_SDC"):
            self.assertNotIn(sobrante, mensaje)

    def test_una_sola_empresa_sin_credenciales_nombra_las_dos_variables(self):
        cfg = Config(**{
            **_config(**LARAVEL, empresa="SDC").__dict__,
            "hana": HanaConfig("h", 30015, "", "", "", 600),
        })
        with self.assertRaises(ConfigError) as error:
            cfg.validar()
        mensaje = str(error.exception)
        for esperada in ("HANA_USER_SDC", "HANA_PASSWORD_SDC", "HANA_SCHEMA_SDC o HANA_SCHEMA"):
            self.assertIn(esperada, mensaje)

    def test_el_mensaje_no_incluye_las_contrasenas(self):
        with self.assertRaises(ConfigError) as error:
            _config(
                **{**LARAVEL, "url": "ftp://x"},
                empresas=(("SGX", "SEGUREX", "u", "clave_sgx"), ("SDC", "", "u", "clave_sdc")),
            ).validar()
        self.assertNotIn("clave_sgx", str(error.exception))
        self.assertNotIn("clave_sdc", str(error.exception))


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
