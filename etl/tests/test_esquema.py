"""Pruebas del contrato de lotes (esquema.py) y de sus consultas SQL."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

import esquema
from esquema import ENTERO, NUMERO, TASA, TEXTO, Columna, Lote

QUERIES_DIR = Path(esquema.__file__).resolve().parent / "queries"
ALIAS = re.compile(r'\bAS\s+"([^"]+)"', re.IGNORECASE)
VENTANA = "ADD_DAYS(CURRENT_DATE, -6 - DAYOFMONTH(CURRENT_DATE))"

# Contrato acordado con Laravel (Ingesta): columnas, obligatorias, llave y si
# admite 0 filas. Si cambia aqui, cambia tambien en app/Modules/Ingesta.
CONTRATO = {
    "contactos": (
        10, {"codigo_sn", "principal", "activo"}, ("codigo_sn", "contacto_id"), False,
    ),
    "ventas": (
        34,
        {"tipo_objeto", "doc_entry", "numero_documento", "linea", "clase_documento",
         "estado_documento", "fecha_documento", "fecha_contabilizacion", "slp_code",
         "codigo_cliente", "cantidad", "total"},
        ("tipo_objeto", "doc_entry", "linea"),
        True,
    ),
    "recaudos": (
        23,
        {"doc_entry_pago", "numero_pago", "linea", "fecha_pago", "estado_pago",
         "tipo_documento", "doc_entry_factura", "codigo_cliente", "slp_code",
         "valor_aplicado", "valor_pagado_sin_iva"},
        ("doc_entry_pago", "linea"),
        True,
    ),
    "cruces": (
        21,
        {"numero_cruce", "linea", "fecha_cruce", "tipo_cruce", "tipo_conciliacion",
         "estado", "codigo_cliente", "doc_entry_factura", "slp_code_factura",
         "valor_compensado", "valor_compensado_sin_iva"},
        ("numero_cruce", "linea"),
        True,
    ),
    "maestro_clientes": (
        10, {"codigo_cliente", "slp_code", "estado"}, ("codigo_cliente",), False,
    ),
    "maestro_vendedores": (
        7, {"slp_code", "nombre", "estado"}, ("slp_code",), False,
    ),
}


def _sql(lote: Lote) -> str:
    return (QUERIES_DIR / lote.consulta).read_text(encoding="utf-8")


def _sin_comentarios(sql: str) -> str:
    return re.sub(r"--[^\n]*", "", sql)


class LotesTest(unittest.TestCase):
    def test_la_llave_de_cada_lote_coincide_con_su_nombre(self):
        for nombre, lote in esquema.LOTES.items():
            with self.subTest(lote=nombre):
                self.assertIsInstance(lote, Lote)
                self.assertEqual(lote.nombre, nombre)
                self.assertTrue(lote.consulta.endswith(".sql"))

    def test_los_nombres_pg_son_unicos_en_cada_lote(self):
        for nombre, lote in esquema.LOTES.items():
            with self.subTest(lote=nombre):
                nombres = [c.pg for c in lote.columnas]
                self.assertEqual(len(nombres), len(set(nombres)))
                alias = [c.hana for c in lote.columnas]
                self.assertEqual(len(alias), len(set(alias)))

    def test_la_llave_natural_usa_columnas_del_lote(self):
        for nombre, lote in esquema.LOTES.items():
            with self.subTest(lote=nombre):
                nombres = {c.pg for c in lote.columnas}
                for campo in lote.llave:
                    self.assertIn(campo, nombres)

    def test_los_tipos_son_los_del_protocolo(self):
        validos = {esquema.TEXTO, esquema.ENTERO, esquema.NUMERO, esquema.TASA, esquema.FECHA}
        for nombre, lote in esquema.LOTES.items():
            for columna in lote.columnas:
                with self.subTest(lote=nombre, columna=columna.pg):
                    self.assertIn(columna.tipo, validos)

    def test_obligatorias_de_cartera_y_asesores(self):
        def obligatorias(lote: str) -> set[str]:
            return {c.pg for c in esquema.LOTES[lote].columnas if c.requerida}

        self.assertEqual(
            obligatorias("cartera"),
            {"codigo_sn", "documento", "fecha_vencimiento", "dias", "saldo"},
        )
        self.assertEqual(obligatorias("asesores"), {"slp_code", "nombre"})
        self.assertEqual(esquema.LOTES["asesores"].llave, ("slp_code",))
        self.assertEqual(esquema.LOTES["cartera"].llave, ())
        self.assertFalse(esquema.LOTES["cartera"].permite_vacio)

    def test_cartera_conserva_sus_38_columnas(self):
        self.assertEqual(len(esquema.CARTERA), 38)
        self.assertEqual(esquema.CARTERA[0].pg, "slp_code")
        self.assertEqual(esquema.CARTERA[-1].pg, "rango")

    def test_lotes_nuevos_cumplen_el_contrato_con_laravel(self):
        self.assertEqual(
            set(esquema.LOTES), {"cartera", "asesores", *CONTRATO},
        )
        for nombre, (columnas, obligatorias, llave, vacio) in CONTRATO.items():
            lote = esquema.LOTES[nombre]
            with self.subTest(lote=nombre):
                self.assertEqual(len(lote.columnas), columnas)
                self.assertEqual({c.pg for c in lote.columnas if c.requerida}, obligatorias)
                self.assertEqual(lote.llave, llave)
                self.assertEqual(lote.permite_vacio, vacio)
        self.assertEqual(esquema.LOTES["contactos"].consulta, "contactos_clientes.sql")


class ConsultasTest(unittest.TestCase):
    def test_cada_lote_tiene_su_archivo_sql(self):
        for nombre, lote in esquema.LOTES.items():
            with self.subTest(lote=nombre):
                self.assertTrue((QUERIES_DIR / lote.consulta).is_file())

    def test_cada_alias_del_esquema_sale_en_su_consulta(self):
        for nombre, lote in esquema.LOTES.items():
            alias = set(ALIAS.findall(_sql(lote)))
            for columna in lote.columnas:
                with self.subTest(lote=nombre, alias=columna.hana):
                    self.assertIn(columna.hana, alias)

    def test_las_consultas_corren_tal_cual_en_el_query_manager(self):
        for nombre, lote in esquema.LOTES.items():
            sql = _sin_comentarios(_sql(lote))
            with self.subTest(lote=nombre):
                self.assertNotIn("?", sql)               # sin parametros
                self.assertNotRegex(sql, r"(?im)^\s*WITH\b")  # sin CTE
                self.assertNotIn('"SEGUREX".', sql)      # sin prefijo de esquema

    def test_los_movimientos_usan_la_ventana_del_mes_mas_siete_dias(self):
        for nombre in ("ventas", "recaudos", "cruces"):
            sql = _sin_comentarios(_sql(esquema.LOTES[nombre]))
            with self.subTest(lote=nombre):
                self.assertIn(VENTANA, sql)
                self.assertIn("<= CURRENT_DATE", sql)

    def test_las_consultas_nuevas_marcan_lo_que_falta_validar(self):
        for nombre in CONTRATO:
            with self.subTest(lote=nombre):
                self.assertIn("VALIDAR CON DATOS REALES", _sql(esquema.LOTES[nombre]))


class ColumnaTest(unittest.TestCase):
    def test_la_forma_posicional_sigue_valiendo(self):
        columna = Columna("Saldo", "saldo", NUMERO)
        self.assertFalse(columna.requerida)
        self.assertTrue(Columna("Saldo", "saldo", NUMERO, requerida=True).requerida)

    def test_ddl_usa_el_tipo_postgres(self):
        sql = esquema.ddl("prueba", [Columna("TRM", "trm", TASA), Columna("N", "n", ENTERO)])
        self.assertIn('"trm" numeric(19,6)', sql)
        self.assertIn('"n" integer', sql)

    def test_indices_falla_si_falta_una_columna(self):
        destino = [Columna("A", "a", TEXTO), Columna("B", "b", TEXTO)]
        self.assertEqual(esquema.indices(["B", "X", "A"], destino), [2, 0])
        with self.assertRaises(esquema.ColumnasInesperadas):
            esquema.indices(["A"], destino)


if __name__ == "__main__":
    unittest.main()
