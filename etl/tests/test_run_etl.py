"""Pruebas del orquestador: dos fases, lotes base y lotes de mejor esfuerzo.

VPN, HANA, CSV, Laravel, Supabase y el correo se reemplazan por dobles que
anotan cada paso en una lista de eventos; no se toca la red ni el disco.
"""
from __future__ import annotations

import logging
import sys
import unittest
from contextlib import ExitStack, contextmanager
from datetime import date
from decimal import Decimal
from pathlib import Path
from unittest import mock

import esquema
import hana
import notificar
import registro
import run_etl
from config import AlertaConfig, Config, EmpresaConfig, HanaConfig, IngestaConfig, VpnConfig
from esquema import ENTERO, FECHA, NUMERO, TASA, Columna, Lote
from sinks import csv_sink, http_sink, postgres_sink
from vpn import VpnError

MUESTRA = {
    esquema.TEXTO: "x",
    ENTERO: 1,
    NUMERO: Decimal("10.50"),
    TASA: Decimal("1"),
    FECHA: date(2026, 10, 1),
}

LOTE_PRUEBA = Lote(
    "prueba", "prueba.sql", [Columna("Valor", "valor", NUMERO, requerida=True)],
    llave=(), permite_vacio=False,
)


def _resultado(nombre: str, filas: int = 2):
    """(alias HANA, filas) como los devolveria hana.ejecutar."""
    columnas = esquema.LOTES[nombre].columnas
    fila = tuple(MUESTRA[c.tipo] for c in columnas)
    return [c.hana for c in columnas], [fila] * filas


class Escenario:
    def __init__(self, resultados, lotes, *, laravel=True, supabase=False,
                 fallos_laravel=(), argv=(), empresa="SGX", empresas=None,
                 vpn_falla=False):
        self.vpn_falla = vpn_falla
        self.resultados = resultados
        self.empresa = empresa
        # empresas: ((codigo, esquema), ...) como ETL_EMPRESAS con dos o mas.
        self.multi = bool(empresas) and len(empresas) > 1
        self.por_esquema = {esq: cod for cod, esq in empresas or ()}
        self.esquemas_conectados: list[str] = []
        self.fallos_laravel = set(fallos_laravel)
        self.eventos: list[str] = []
        self.consultas: list[tuple[str, str]] = []
        self.avisos: list[tuple] = []
        self.estados: list[dict] = []
        self.cfg = Config(
            vpn=VpnConfig(Path(sys.executable), "vpn", "u", "p", 90, True),
            hana=HanaConfig("10.0.0.1", 30015, "u", "p", "SEGUREX", 600),
            supabase_url="postgresql://respaldo" if supabase else "",
            alertas=AlertaConfig("", 587, "", ""),
            ingesta=IngestaConfig(
                "https://cartera.example.com" if laravel else "",
                "k2026a" if laravel else "",
                "s3cr3t0" if laravel else "",
                empresa=empresa,
            ),
            lotes=tuple(lotes),
            empresas=tuple(EmpresaConfig(*e) for e in empresas or ()),
        )
        self.argv = ["run_etl.py", *argv]
        self.hoy = lambda: date(2026, 10, 16)

    # -- dobles ------------------------------------------------------------
    def _vpn(self, _cfg):
        eventos = self.eventos
        falla = self.vpn_falla

        class VpnFalsa:
            def conectar(self):
                if falla:
                    raise VpnError("Sophos no conecto")
                eventos.append("vpn:arriba")

            def desconectar(self):
                eventos.append("vpn:abajo")

        return VpnFalsa()

    @contextmanager
    def _conectar(self, cfg):
        self.esquemas_conectados.append(cfg.esquema)
        yield "conexion"

    def _leer_query(self, nombre, empresa):
        self.consultas.append((nombre, empresa))
        return f"-- {nombre}"

    def _ejecutar(self, _conn, _sql, etiqueta, esquema_hana):
        empresa = self.por_esquema.get(esquema_hana, self.empresa)
        self.eventos.append(
            f"hana:{empresa}:{etiqueta}" if self.multi else f"hana:{etiqueta}"
        )
        resultado = self.resultados.get((empresa, etiqueta), self.resultados.get(etiqueta))
        if isinstance(resultado, Exception):
            raise resultado
        return resultado

    def _publicar(self, _cfg, lote, empresa, corte, columnas, filas):
        self.eventos.append(f"laravel:{empresa}:{lote}" if self.multi else f"laravel:{lote}")
        if lote in self.fallos_laravel or (empresa, lote) in self.fallos_laravel:
            raise http_sink.HttpSinkError(f"Laravel rechazo el lote '{lote}'", 422)
        assert (self.multi or empresa == self.empresa) and corte == date(2026, 10, 16)
        return {"duplicate": False}

    @contextmanager
    def _pg_conectar(self, _url):
        yield "pg"

    def ejecutar(self) -> int:
        with ExitStack() as pila:
            def parche(objetivo, atributo, nuevo):
                pila.enter_context(mock.patch.object(objetivo, atributo, nuevo))

            parche(sys, "argv", self.argv)
            parche(registro, "configurar", lambda: logging.getLogger("cartera"))
            parche(run_etl, "cargar_config", lambda: self.cfg)
            parche(run_etl, "leer_query", self._leer_query)
            parche(run_etl, "RobotVPN", self._vpn)
            parche(run_etl, "hoy_colombia", lambda: self.hoy())
            parche(run_etl, "guardar_estado", lambda **datos: self.estados.append(datos))
            parche(hana, "conectar", self._conectar)
            parche(hana, "listar_esquemas", lambda _conn: ["SEGUREX"])
            parche(hana, "ejecutar", self._ejecutar)
            parche(csv_sink, "guardar",
                   lambda nombre, *_: self.eventos.append(f"csv:{nombre}"))
            parche(http_sink, "publicar", self._publicar)
            parche(notificar, "avisar_fallo",
                   lambda _cfg, etapa, error, detalle="": self.avisos.append((etapa, error)))
            parche(postgres_sink, "conectar", self._pg_conectar)
            parche(postgres_sink, "preparar", lambda *_: None)
            parche(postgres_sink, "publicar",
                   lambda _pg, tabla, *_: self.eventos.append(f"supabase:{tabla}"))
            parche(postgres_sink, "registrar_historico", lambda _pg: 0)
            parche(postgres_sink, "registrar_run", lambda *_, **__: None)
            pila.enter_context(mock.patch.dict(esquema.LOTES, {"prueba": LOTE_PRUEBA}))
            return run_etl.main()


class RunEtlTest(unittest.TestCase):
    def test_publica_todos_los_lotes_despues_de_bajar_la_vpn(self):
        escenario = Escenario(
            {"cartera": _resultado("cartera"), "asesores": _resultado("asesores")},
            ["asesores", "cartera"],
        )
        self.assertEqual(escenario.ejecutar(), 0)
        eventos = escenario.eventos
        self.assertEqual(
            eventos,
            ["vpn:arriba", "hana:cartera", "hana:asesores", "vpn:abajo",
             "csv:cartera", "csv:asesores", "laravel:cartera", "laravel:asesores"],
        )
        self.assertEqual(escenario.avisos, [])
        estado = escenario.estados[-1]
        self.assertEqual(estado["estado"], "OK")
        self.assertEqual(estado["lotes"]["cartera"]["laravel"], "nuevo")
        self.assertEqual(estado["filas_cartera"], 2)

    def test_cada_consulta_se_lee_para_la_empresa_configurada(self):
        for empresa in ("SGX", "SDC"):
            with self.subTest(empresa=empresa):
                escenario = Escenario(
                    {"cartera": _resultado("cartera"),
                     "asesores": _resultado("asesores")},
                    ["cartera", "asesores"],
                    empresa=empresa,
                )
                self.assertEqual(escenario.ejecutar(), 0)
                self.assertEqual(
                    escenario.consultas,
                    [("cartera.sql", empresa), ("asesores.sql", empresa)],
                )

    def test_un_lote_nuevo_que_falla_en_hana_no_frena_la_cartera(self):
        escenario = Escenario(
            {
                "cartera": _resultado("cartera"),
                "asesores": _resultado("asesores"),
                "prueba": hana.HanaError("invalid column name"),
            },
            ["prueba", "cartera", "asesores"],
        )
        self.assertEqual(escenario.ejecutar(), 1)
        self.assertIn("laravel:cartera", escenario.eventos)
        self.assertIn("laravel:asesores", escenario.eventos)
        self.assertNotIn("laravel:prueba", escenario.eventos)
        # Los lotes base se consultan primero, aunque ETL_LOTES diga otra cosa.
        self.assertEqual(escenario.eventos[1], "hana:cartera")
        (aviso,) = escenario.avisos
        self.assertEqual(aviso[0], "HANA (prueba)")
        self.assertEqual(escenario.estados[-1]["lotes"]["prueba"]["estado"], "ERROR")
        self.assertEqual(escenario.estados[-1]["lotes"]["cartera"]["estado"], "OK")

    def test_un_lote_sin_filas_que_no_las_admite_no_se_envia(self):
        escenario = Escenario(
            {
                "cartera": _resultado("cartera"),
                "prueba": (["Valor"], []),
            },
            ["cartera", "prueba"],
        )
        self.assertEqual(escenario.ejecutar(), 1)
        self.assertIn("laravel:cartera", escenario.eventos)
        self.assertNotIn("laravel:prueba", escenario.eventos)
        self.assertIn("0 filas", escenario.avisos[0][1])

    def test_cartera_vacia_aborta_sin_publicar_nada(self):
        escenario = Escenario(
            {"cartera": _resultado("cartera", filas=0), "asesores": _resultado("asesores")},
            ["cartera", "asesores"],
        )
        self.assertEqual(escenario.ejecutar(), 1)
        self.assertFalse([e for e in escenario.eventos if e.startswith("laravel:")])
        self.assertEqual(escenario.avisos[0][0], "VALIDACION")

    def test_un_error_de_hana_en_cartera_aborta(self):
        escenario = Escenario(
            {"cartera": hana.HanaError("sin conexion"), "prueba": (["Valor"], [(1,)])},
            ["prueba", "cartera"],
        )
        self.assertEqual(escenario.ejecutar(), 1)
        self.assertNotIn("hana:prueba", escenario.eventos)
        self.assertIn("vpn:abajo", escenario.eventos)
        self.assertFalse([e for e in escenario.eventos if e.startswith("laravel:")])

    def test_intenta_todos_y_reporta_primero_la_cartera(self):
        escenario = Escenario(
            {"cartera": _resultado("cartera"), "prueba": (["Valor"], [(1,)])},
            ["prueba", "cartera"],
            fallos_laravel={"cartera", "prueba"},
        )
        self.assertEqual(escenario.ejecutar(), 1)
        self.assertIn("laravel:prueba", escenario.eventos)
        (aviso,) = escenario.avisos
        self.assertEqual(aviso[0], "LARAVEL (cartera)")
        self.assertLess(aviso[1].index("cartera"), aviso[1].index("prueba"))

    def test_la_fecha_de_corte_es_la_del_inicio_de_la_extraccion(self):
        escenario = Escenario(
            {"cartera": _resultado("cartera"), "asesores": _resultado("asesores")},
            ["cartera", "asesores"],
        )
        pedidas: list[int] = []

        def hoy():
            # Extrae el 16 antes de medianoche y publica ya el 17.
            pedidas.append(len(escenario.eventos))
            if "vpn:abajo" in escenario.eventos:
                return date(2026, 10, 17)
            return date(2026, 10, 16)

        escenario.hoy = hoy
        self.assertEqual(escenario.ejecutar(), 0)  # _publicar exige el 16
        self.assertEqual(pedidas, [0])  # una sola vez, antes de subir la VPN
        self.assertEqual(escenario.avisos, [])

    def test_supabase_sigue_como_respaldo_aunque_falle_laravel(self):
        escenario = Escenario(
            {"cartera": _resultado("cartera"), "asesores": _resultado("asesores")},
            ["cartera", "asesores"],
            supabase=True,
            fallos_laravel={"cartera"},
        )
        self.assertEqual(escenario.ejecutar(), 1)
        self.assertIn("supabase:cartera", escenario.eventos)
        self.assertIn("supabase:asesores", escenario.eventos)
        self.assertLess(
            escenario.eventos.index("laravel:asesores"),
            escenario.eventos.index("supabase:cartera"),
        )

    def test_sin_laravel_publica_solo_en_supabase_como_antes(self):
        escenario = Escenario(
            {"cartera": _resultado("cartera"), "asesores": _resultado("asesores")},
            ["cartera", "asesores"],
            laravel=False,
            supabase=True,
        )
        self.assertEqual(escenario.ejecutar(), 0)
        self.assertFalse([e for e in escenario.eventos if e.startswith("laravel:")])
        self.assertIn("supabase:cartera", escenario.eventos)

    def test_el_diagnostico_valida_el_contrato_pero_no_publica(self):
        escenario = Escenario(
            {"prueba": (["Valor"], [(Decimal("1.5"),), (None,)])},
            [],
            argv=["--diagnostico", "--lotes", "prueba"],
        )
        self.assertEqual(escenario.ejecutar(), 1)  # la fila 1 trae una obligatoria nula
        self.assertEqual(
            escenario.eventos, ["vpn:arriba", "hana:prueba", "vpn:abajo", "csv:prueba"]
        )
        self.assertEqual(escenario.avisos, [])
        self.assertEqual(escenario.estados[-1]["estado"], "DIAGNOSTICO_CON_FALLOS")

    def test_el_diagnostico_correcto_termina_en_cero(self):
        escenario = Escenario(
            {"prueba": (["Valor"], [(Decimal("1.5"),)])},
            [],
            supabase=True,
            argv=["--diagnostico", "--lotes", "prueba"],
        )
        self.assertEqual(escenario.ejecutar(), 0)
        self.assertNotIn("supabase:cartera", escenario.eventos)
        self.assertEqual(escenario.estados[-1]["estado"], "DIAGNOSTICO_OK")

    def test_un_lote_desconocido_en_la_linea_de_comandos_es_error_de_configuracion(self):
        escenario = Escenario({}, ["cartera"], argv=["--lotes", "ventitas"])
        self.assertEqual(escenario.ejecutar(), 2)
        self.assertEqual(escenario.eventos, [])


DOS_EMPRESAS = (("SGX", "SEGUREX"), ("SDC", "HBT_SDERIVADOS"))


def _base(*empresas: str) -> dict:
    """Cartera y asesores correctos de cada empresa."""
    return {
        (e, lote): _resultado(lote) for e in empresas for lote in ("cartera", "asesores")
    }


class DosEmpresasTest(unittest.TestCase):
    def _escenario(self, resultados=None, **opciones):
        return Escenario(
            resultados if resultados is not None else _base("SGX", "SDC"),
            ["cartera", "asesores"],
            empresas=DOS_EMPRESAS,
            **opciones,
        )

    def test_una_sola_vpn_primero_segurex_y_luego_derivados(self):
        escenario = self._escenario()
        self.assertEqual(escenario.ejecutar(), 0)
        self.assertEqual(
            escenario.eventos,
            ["vpn:arriba",
             "hana:SGX:cartera", "hana:SGX:asesores",
             "hana:SDC:cartera", "hana:SDC:asesores",
             "vpn:abajo",
             "csv:SGX_cartera", "csv:SGX_asesores",
             "laravel:SGX:cartera", "laravel:SGX:asesores",
             "csv:SDC_cartera", "csv:SDC_asesores",
             "laravel:SDC:cartera", "laravel:SDC:asesores"],
        )
        self.assertEqual(escenario.avisos, [])
        estado = escenario.estados[-1]
        self.assertEqual(estado["estado"], "OK")
        self.assertEqual(estado["empresas"]["SDC"]["lotes"]["cartera"]["laravel"], "nuevo")

    def test_cada_empresa_usa_su_esquema_y_sus_consultas(self):
        escenario = self._escenario()
        escenario.ejecutar()
        self.assertEqual(escenario.esquemas_conectados, ["SEGUREX", "HBT_SDERIVADOS"])
        self.assertEqual(
            escenario.consultas,
            [("cartera.sql", "SGX"), ("asesores.sql", "SGX"),
             ("cartera.sql", "SDC"), ("asesores.sql", "SDC")],
        )

    def test_si_falla_segurex_derivados_igual_se_publica(self):
        resultados = {**_base("SGX", "SDC"),
                      ("SGX", "cartera"): hana.HanaError("sin conexion")}
        escenario = self._escenario(resultados)
        self.assertEqual(escenario.ejecutar(), 1)
        self.assertFalse([e for e in escenario.eventos if e.startswith("laravel:SGX")])
        self.assertIn("laravel:SDC:cartera", escenario.eventos)
        self.assertIn("laravel:SDC:asesores", escenario.eventos)
        (aviso,) = escenario.avisos
        self.assertIn("SGX", aviso[0])
        self.assertNotIn("SDC", aviso[1])
        estado = escenario.estados[-1]
        self.assertEqual(estado["estado"], "ERROR")
        self.assertEqual(estado["empresas"]["SGX"]["estado"], "ERROR")
        self.assertEqual(estado["empresas"]["SDC"]["estado"], "OK")

    def test_si_falla_derivados_segurex_ya_estaba_publicado(self):
        resultados = {**_base("SGX", "SDC"), ("SDC", "asesores"): (["Otra"], [])}
        escenario = self._escenario(resultados)
        self.assertEqual(escenario.ejecutar(), 1)
        self.assertIn("laravel:SGX:cartera", escenario.eventos)
        self.assertIn("laravel:SGX:asesores", escenario.eventos)
        (aviso,) = escenario.avisos
        self.assertIn("SDC", aviso[0])
        self.assertNotIn("SGX", aviso[1])

    def test_el_rechazo_de_laravel_a_una_empresa_no_frena_a_la_otra(self):
        escenario = self._escenario(fallos_laravel={("SGX", "cartera")})
        self.assertEqual(escenario.ejecutar(), 1)
        self.assertIn("laravel:SGX:asesores", escenario.eventos)
        self.assertIn("laravel:SDC:cartera", escenario.eventos)
        (aviso,) = escenario.avisos
        self.assertEqual(aviso[0], "LARAVEL (SGX/cartera)")

    def test_el_aviso_nombra_a_las_dos_empresas_si_fallan_las_dos(self):
        escenario = self._escenario(fallos_laravel={("SGX", "cartera"), ("SDC", "cartera")})
        self.assertEqual(escenario.ejecutar(), 1)
        (aviso,) = escenario.avisos
        self.assertIn("SGX/cartera", aviso[1])
        self.assertIn("SDC/cartera", aviso[1])

    def test_el_diagnostico_hace_las_dos_con_csv_por_empresa_y_no_publica(self):
        escenario = self._escenario(argv=["--diagnostico"], supabase=True)
        self.assertEqual(escenario.ejecutar(), 0)
        self.assertEqual(escenario.eventos.count("vpn:arriba"), 1)
        for csv in ("SGX_cartera", "SGX_asesores", "SDC_cartera", "SDC_asesores"):
            self.assertIn(f"csv:{csv}", escenario.eventos)
        self.assertFalse(
            [e for e in escenario.eventos if e.startswith(("laravel", "supabase"))]
        )
        self.assertEqual(escenario.estados[-1]["estado"], "DIAGNOSTICO_OK")

    def test_el_diagnostico_falla_si_una_empresa_falla(self):
        resultados = {**_base("SGX", "SDC"), ("SDC", "cartera"): hana.HanaError("x")}
        escenario = self._escenario(resultados, argv=["--diagnostico"])
        self.assertEqual(escenario.ejecutar(), 1)
        self.assertIn("csv:SGX_cartera", escenario.eventos)
        self.assertEqual(escenario.estados[-1]["estado"], "DIAGNOSTICO_CON_FALLOS")

    def test_si_cae_la_vpn_no_se_publica_nada_y_el_estado_trae_las_dos_empresas(self):
        escenario = self._escenario(vpn_falla=True)
        self.assertEqual(escenario.ejecutar(), 1)
        self.assertEqual(escenario.eventos, [])
        (aviso,) = escenario.avisos
        self.assertEqual(aviso[0], "VPN")
        estado = escenario.estados[-1]
        self.assertEqual(estado["estado"], "ERROR")
        self.assertEqual(estado["etapa"], "VPN")
        self.assertEqual(set(estado["empresas"]), {"SGX", "SDC"})
        self.assertNotIn("lotes", estado)

    def test_un_lote_no_base_que_falla_en_derivados_no_frena_nada(self):
        resultados = {
            **_base("SGX", "SDC"),
            ("SGX", "prueba"): (["Valor"], [(1,)]),
            ("SDC", "prueba"): hana.HanaError("invalid column name"),
        }
        escenario = Escenario(
            resultados, ["cartera", "asesores", "prueba"], empresas=DOS_EMPRESAS
        )
        self.assertEqual(escenario.ejecutar(), 1)
        for evento in ("laravel:SGX:cartera", "laravel:SGX:prueba",
                       "laravel:SDC:cartera", "laravel:SDC:asesores"):
            self.assertIn(evento, escenario.eventos)
        self.assertNotIn("laravel:SDC:prueba", escenario.eventos)
        (aviso,) = escenario.avisos
        self.assertEqual(aviso[0], "HANA (SDC/prueba)")
        empresas = escenario.estados[-1]["empresas"]
        self.assertEqual(empresas["SGX"]["estado"], "OK")
        self.assertEqual(empresas["SDC"]["estado"], "ERROR")
        self.assertEqual(empresas["SDC"]["lotes"]["cartera"]["estado"], "OK")
        self.assertEqual(empresas["SDC"]["lotes"]["prueba"]["estado"], "ERROR")

    def test_supabase_es_el_respaldo_solo_de_segurex(self):
        escenario = self._escenario(supabase=True)
        self.assertEqual(escenario.ejecutar(), 0)
        self.assertEqual(
            [e for e in escenario.eventos if e.startswith("supabase:")],
            ["supabase:cartera", "supabase:asesores"],
        )

    def test_una_sola_empresa_en_etl_empresas_se_porta_como_hoy(self):
        escenario = Escenario(
            {"cartera": _resultado("cartera"), "asesores": _resultado("asesores")},
            ["cartera", "asesores"],
            empresas=(("SDC", "HBT_SDERIVADOS"),),
            empresa="SDC",
        )
        self.assertEqual(escenario.ejecutar(), 0)
        self.assertEqual(escenario.eventos[1], "hana:cartera")
        self.assertIn("csv:cartera", escenario.eventos)
        self.assertEqual(escenario.esquemas_conectados, ["HBT_SDERIVADOS"])


class FechaDeCorteTest(unittest.TestCase):
    def test_usa_la_hora_de_colombia(self):
        self.assertEqual(run_etl.ZONA_COLOMBIA.utcoffset(None).total_seconds(), -5 * 3600)
        self.assertIsInstance(run_etl.hoy_colombia(), date)

    def test_ordenar_lotes_pone_primero_la_base(self):
        self.assertEqual(
            run_etl.ordenar_lotes(("ventas", "asesores", "cartera")),
            ["cartera", "asesores", "ventas"],
        )


if __name__ == "__main__":
    unittest.main()
