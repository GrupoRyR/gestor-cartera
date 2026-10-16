"""Pruebas del contrato de lotes (esquema.py)."""
from __future__ import annotations

import unittest

import esquema
from esquema import ENTERO, NUMERO, TASA, TEXTO, Columna, Lote


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
