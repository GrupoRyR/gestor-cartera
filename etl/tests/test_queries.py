"""Pruebas de la lectura de consultas: las cuentas por cobrar son de cada empresa."""
from __future__ import annotations

import tempfile
import unittest
from pathlib import Path
from unittest import mock

import config
from config import ConfigError, leer_query

SEGUREX = ("13050505", "13051005")
DERIVADOS = ("13050501", "13051005")


def _cuentas_del_filtro(sql: str) -> list[str]:
    """Cuentas dentro del IN que lleva el marcador @cuentas_por_cobrar."""
    linea = next(l for l in sql.splitlines() if "@cuentas_por_cobrar" in l)
    entre_parentesis = linea[linea.index("(") + 1:linea.index(")")]
    return [c.strip().strip("'") for c in entre_parentesis.split(",")]


class CuentasPorCobrarTest(unittest.TestCase):
    def test_segurex_no_cambia_la_consulta(self):
        crudo = (config.QUERIES_DIR / "cartera.sql").read_text(encoding="utf-8")
        self.assertEqual(leer_query("cartera.sql", "SGX"), crudo)
        self.assertEqual(leer_query("cartera.sql"), crudo)

    def test_derivados_usa_sus_cuentas(self):
        sql = leer_query("cartera.sql", "SDC")
        self.assertEqual(_cuentas_del_filtro(sql), list(DERIVADOS))
        self.assertNotIn("13050505", sql)

    def test_derivados_solo_cambia_la_linea_del_filtro(self):
        crudo = leer_query("cartera.sql", "SGX").splitlines()
        derivados = leer_query("cartera.sql", "SDC").splitlines()
        self.assertEqual(len(crudo), len(derivados))
        distintas = [i for i, (a, b) in enumerate(zip(crudo, derivados)) if a != b]
        self.assertEqual(len(distintas), 1)

    def test_el_archivo_corre_tal_cual_en_el_query_manager_con_cuentas_de_segurex(self):
        crudo = (config.QUERIES_DIR / "cartera.sql").read_text(encoding="utf-8")
        self.assertEqual(crudo.count("@cuentas_por_cobrar"), 1)
        self.assertEqual(_cuentas_del_filtro(crudo), list(SEGUREX))

    def test_cada_empresa_valida_tiene_cuentas(self):
        for empresa in config.EMPRESAS:
            with self.subTest(empresa=empresa):
                self.assertTrue(config.CUENTAS_POR_COBRAR[empresa])

    def test_una_empresa_desconocida_es_un_error(self):
        with self.assertRaises(ConfigError) as error:
            leer_query("cartera.sql", "XYZ")
        self.assertIn("XYZ", str(error.exception))

    def test_cartera_sin_marcador_falla_en_vez_de_usar_otras_cuentas(self):
        with tempfile.TemporaryDirectory() as carpeta:
            (Path(carpeta) / "cartera.sql").write_text(
                "SELECT 1 WHERE \"Account\" IN ('13050505')\n", encoding="utf-8"
            )
            with mock.patch.object(config, "QUERIES_DIR", Path(carpeta)):
                for empresa in config.EMPRESAS:
                    with self.subTest(empresa=empresa):
                        with self.assertRaises(ConfigError) as error:
                            leer_query("cartera.sql", empresa)
                        self.assertIn("@cuentas_por_cobrar", str(error.exception))

    def test_las_empresas_validas_salen_de_las_cuentas(self):
        self.assertEqual(config.EMPRESAS, tuple(config.CUENTAS_POR_COBRAR))

    def test_las_consultas_sin_marcador_no_se_tocan(self):
        crudo = (config.QUERIES_DIR / "asesores.sql").read_text(encoding="utf-8")
        self.assertEqual(leer_query("asesores.sql", "SDC"), crudo)


if __name__ == "__main__":
    unittest.main()
