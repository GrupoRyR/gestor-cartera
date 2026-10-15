"""Mapeo entre las columnas de la consulta HANA y las tablas de Postgres."""
from __future__ import annotations

from dataclasses import dataclass

TEXTO, ENTERO, NUMERO, FECHA = "text", "integer", "numeric(19,2)", "date"


@dataclass(frozen=True)
class Columna:
    hana: str   # nombre exacto del alias en la consulta HANA
    pg: str     # nombre de la columna en Postgres
    tipo: str


# El ORDEN de esta lista define el orden de las columnas en Postgres y en el CSV.
CARTERA: list[Columna] = [
    Columna("SlpCode",                    "slp_code",              ENTERO),
    Columna("Email asesor",               "email_asesor",          TEXTO),
    Columna("Código SN",                  "codigo_sn",             TEXTO),
    Columna("Nombre SN",                  "nombre_sn",             TEXTO),
    Columna("Nit",                        "nit",                   TEXTO),
    Columna("Grupo",                      "grupo",                 TEXTO),
    Columna("Municipio",                  "municipio",             TEXTO),
    Columna("Cuenta visible",             "cuenta_visible",        TEXTO),
    Columna("Tipo doc",                   "tipo_doc",              TEXTO),
    Columna("Documento",                  "documento",             TEXTO),
    Columna("Documento base",             "documento_base",        TEXTO),
    Columna("Referencia 1",               "referencia_1",          TEXTO),
    Columna("No. referencia",             "no_referencia",         TEXTO),
    Columna("Empleado ventas / compras",  "asesor",                TEXTO),
    Columna("Total plazos",               "total_plazos",          ENTERO),
    Columna("No. plazo",                  "no_plazo",              ENTERO),
    Columna("Condicion pago documento",   "condicion_pago",        TEXTO),
    Columna("Fecha contabilización",      "fecha_contabilizacion", FECHA),
    Columna("Fecha vencimiento",          "fecha_vencimiento",     FECHA),
    Columna("Fecha documento",            "fecha_documento",       FECHA),
    Columna("Días",                       "dias",                  ENTERO),
    Columna("Límite crédito",             "limite_credito",        NUMERO),
    Columna("Importe original",           "importe_original",      NUMERO),
    Columna("Base gravable",              "base_gravable",         NUMERO),
    Columna("Saldo base",                 "saldo_base",            NUMERO),
    Columna("Saldo vencido",              "saldo_vencido",         NUMERO),
    Columna("Valor abonado",              "valor_abonado",         NUMERO),
    Columna("Sin vencer",                 "sin_vencer",            NUMERO),
    Columna("Valor cheques aplicados",    "valor_cheques",         NUMERO),
    Columna("0-30",                       "d_0_30",                NUMERO),
    Columna("31-60",                      "d_31_60",               NUMERO),
    Columna("61-90",                      "d_61_90",               NUMERO),
    Columna("91-120",                     "d_91_120",              NUMERO),
    Columna("121-150",                    "d_121_150",             NUMERO),
    Columna("151-180",                    "d_151_180",             NUMERO),
    Columna("> 180",                      "d_mas_180",             NUMERO),
    Columna("Saldo",                      "saldo",                 NUMERO),
    Columna("Rango",                      "rango",                 TEXTO),
]

ASESORES: list[Columna] = [
    Columna("SlpCode",  "slp_code", ENTERO),
    Columna("Nombre",   "nombre",   TEXTO),
    Columna("Email",    "email",    TEXTO),
    Columna("Activo",   "activo",   TEXTO),
    Columna("Clientes", "clientes", ENTERO),
]

# Etiqueta legible por rango, en el orden en que debe mostrarse la web.
ORDEN_RANGOS = [
    "Sin vencer", "0-30", "31-60", "61-90",
    "91-120", "121-150", "151-180", "> 180",
]


class ColumnasInesperadas(RuntimeError):
    """La consulta HANA no devolvio las columnas que el ETL espera."""


def indices(columnas_hana: list[str], destino: list[Columna]) -> list[int]:
    """Posicion de cada columna esperada dentro del resultado de HANA.

    Falla ruidosamente si alguien edita el SQL y rompe el contrato: es
    preferible a cargar datos desalineados en produccion.
    """
    faltantes = [c.hana for c in destino if c.hana not in columnas_hana]
    if faltantes:
        raise ColumnasInesperadas(
            "La consulta no devolvio estas columnas esperadas: "
            + ", ".join(faltantes)
            + "\nColumnas recibidas: "
            + ", ".join(columnas_hana)
            + "\nRevisa el archivo .sql o actualiza etl/esquema.py."
        )
    return [columnas_hana.index(c.hana) for c in destino]


def ddl(tabla: str, destino: list[Columna]) -> str:
    cuerpo = ",\n    ".join(f'"{c.pg}" {c.tipo}' for c in destino)
    return f'CREATE TABLE IF NOT EXISTS "{tabla}" (\n    {cuerpo}\n);'
