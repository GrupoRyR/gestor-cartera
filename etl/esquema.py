"""Contrato de columnas de cada lote: consulta HANA -> Laravel / CSV / Postgres.

Este archivo es la fuente de verdad del contrato con el modulo Ingesta de
Laravel: nombres "pg", tipos, columnas obligatorias, llave natural y si el lote
admite cargas vacias. Laravel lo replica tal cual; si cambias algo aqui,
cambialo tambien alla.
"""
from __future__ import annotations

from dataclasses import dataclass

TEXTO, ENTERO, NUMERO, FECHA = "text", "integer", "numeric(19,2)", "date"
# Tasas y cantidades: 6 decimales (TRM, cantidades fraccionadas).
TASA = "numeric(19,6)"


@dataclass(frozen=True)
class Columna:
    hana: str   # nombre exacto del alias en la consulta HANA
    pg: str     # nombre de la columna en Postgres y en Laravel
    tipo: str
    # Obligatoria: Laravel rechaza el lote si llega nula o vacia.
    requerida: bool = False


@dataclass(frozen=True)
class Lote:
    """Un lote = una consulta de etl/queries publicada en /api/ingesta/{nombre}."""

    nombre: str                    # segmento {lote} de la URL (Dataset de Laravel)
    consulta: str                  # archivo dentro de etl/queries
    columnas: list[Columna]        # el ORDEN define el orden del CSV y del envio
    llave: tuple[str, ...] = ()    # llave natural (nombres pg); vacia = sin llave
    permite_vacio: bool = False    # False: 0 filas es un error y no se envia


# El ORDEN de esta lista define el orden de las columnas en Postgres y en el CSV.
CARTERA: list[Columna] = [
    Columna("SlpCode",                    "slp_code",              ENTERO),
    Columna("Email asesor",               "email_asesor",          TEXTO),
    Columna("Código SN",                  "codigo_sn",             TEXTO, requerida=True),
    Columna("Nombre SN",                  "nombre_sn",             TEXTO),
    Columna("Nit",                        "nit",                   TEXTO),
    Columna("Grupo",                      "grupo",                 TEXTO),
    Columna("Municipio",                  "municipio",             TEXTO),
    Columna("Cuenta visible",             "cuenta_visible",        TEXTO),
    Columna("Tipo doc",                   "tipo_doc",              TEXTO),
    Columna("Documento",                  "documento",             TEXTO, requerida=True),
    Columna("Documento base",             "documento_base",        TEXTO),
    Columna("Referencia 1",               "referencia_1",          TEXTO),
    Columna("No. referencia",             "no_referencia",         TEXTO),
    Columna("Empleado ventas / compras",  "asesor",                TEXTO),
    Columna("Total plazos",               "total_plazos",          ENTERO),
    Columna("No. plazo",                  "no_plazo",              ENTERO),
    Columna("Condicion pago documento",   "condicion_pago",        TEXTO),
    Columna("Fecha contabilización",      "fecha_contabilizacion", FECHA),
    Columna("Fecha vencimiento",          "fecha_vencimiento",     FECHA, requerida=True),
    Columna("Fecha documento",            "fecha_documento",       FECHA),
    Columna("Días",                       "dias",                  ENTERO, requerida=True),
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
    Columna("Saldo",                      "saldo",                 NUMERO, requerida=True),
    Columna("Rango",                      "rango",                 TEXTO),
]

ASESORES: list[Columna] = [
    Columna("SlpCode",  "slp_code", ENTERO, requerida=True),
    Columna("Nombre",   "nombre",   TEXTO, requerida=True),
    Columna("Email",    "email",    TEXTO),
    Columna("Activo",   "activo",   TEXTO),
    Columna("Clientes", "clientes", ENTERO),
]

# Una fila por contacto (OCPR) mas una fila general por cliente (OCRD) con
# contacto_id y nombre nulos. Lo consume Core.
CONTACTOS: list[Columna] = [
    Columna("Código SN",    "codigo_sn",   TEXTO, requerida=True),
    Columna("Nit",          "nit",         TEXTO),
    Columna("Id contacto",  "contacto_id", ENTERO),
    Columna("Nombre",       "nombre",      TEXTO),
    Columna("Cargo",        "cargo",       TEXTO),
    Columna("Correo",       "correo",      TEXTO),
    Columna("Teléfono",     "telefono",    TEXTO),
    Columna("Celular",      "celular",     TEXTO),
    Columna("Principal",    "principal",   TEXTO, requerida=True),
    Columna("Activo",       "activo",      TEXTO, requerida=True),
]

# Comisiones, reporte 1: una fila por linea de factura (13) o nota credito
# (14); las notas credito llegan con signo negativo. El periodo de la venta es
# fecha_contabilizacion (DocDate, la fecha de la factura); fecha_documento es
# TaxDate y va como referencia.
VENTAS: list[Columna] = [
    Columna("Tipo objeto",           "tipo_objeto",           ENTERO, requerida=True),
    Columna("DocEntry",              "doc_entry",             ENTERO, requerida=True),
    Columna("Número documento",      "numero_documento",      ENTERO, requerida=True),
    Columna("Línea",                 "linea",                 ENTERO, requerida=True),
    Columna("Clase documento",       "clase_documento",       TEXTO,  requerida=True),
    Columna("Estado documento",      "estado_documento",      TEXTO,  requerida=True),
    Columna("Fecha documento",       "fecha_documento",       FECHA,  requerida=True),
    Columna("Fecha contabilización", "fecha_contabilizacion", FECHA,  requerida=True),
    Columna("SlpCode",               "slp_code",              ENTERO, requerida=True),
    Columna("Vendedor",              "vendedor",              TEXTO),
    Columna("Canal",                 "canal",                 TEXTO),
    Columna("Código cliente",        "codigo_cliente",        TEXTO,  requerida=True),
    Columna("Cliente",               "cliente",               TEXTO),
    Columna("Nit",                   "nit",                   TEXTO),
    Columna("Ciudad",                "ciudad",                TEXTO),
    Columna("Pedido",                "pedido",                TEXTO),
    Columna("Id pedido app",         "id_pedido_app",         TEXTO),
    Columna("OC cliente",            "oc_cliente",            TEXTO),
    Columna("Ciudad envío",          "ciudad_envio",          TEXTO),
    Columna("Dirección envío",       "direccion_envio",       TEXTO),
    Columna("Marca",                 "marca",                 TEXTO),
    Columna("Familia",               "familia",               TEXTO),
    Columna("Código artículo",       "codigo_articulo",       TEXTO),
    Columna("Descripción",           "descripcion",           TEXTO),
    Columna("Cantidad",              "cantidad",              TASA,   requerida=True),
    Columna("Costo unitario",        "costo_unitario",        NUMERO),
    Columna("Costo total",           "costo_total",           NUMERO),
    Columna("Precio lista",          "precio_lista",          NUMERO),
    Columna("Precio unitario",       "precio_unitario",       NUMERO),
    Columna("Total",                 "total",                 NUMERO, requerida=True),
    Columna("IVA",                   "iva",                   NUMERO),
    Columna("Moneda",                "moneda",                TEXTO),
    Columna("TRM",                   "trm",                   TASA),
    Columna("Comentarios",           "comentarios",           TEXTO),
]

# Comisiones, reporte 2: una fila por aplicacion de un pago recibido a una
# factura (ORCT + RCT2), incluidos los pagos anulados.
RECAUDOS: list[Columna] = [
    Columna("DocEntry pago",         "doc_entry_pago",        ENTERO, requerida=True),
    Columna("Número pago",           "numero_pago",           ENTERO, requerida=True),
    Columna("Línea",                 "linea",                 ENTERO, requerida=True),
    Columna("Fecha pago",            "fecha_pago",            FECHA,  requerida=True),
    Columna("Estado pago",           "estado_pago",           TEXTO,  requerida=True),
    Columna("Tipo documento",        "tipo_documento",        ENTERO, requerida=True),
    Columna("DocEntry factura",      "doc_entry_factura",     ENTERO, requerida=True),
    Columna("Número factura",        "numero_factura",        ENTERO),
    Columna("Fecha factura",         "fecha_factura",         FECHA),
    Columna("Fecha vencimiento",     "fecha_vencimiento",     FECHA),
    Columna("Código cliente",        "codigo_cliente",        TEXTO,  requerida=True),
    Columna("Nombre cliente",        "nombre_cliente",        TEXTO),
    Columna("SlpCode",               "slp_code",              ENTERO, requerida=True),
    Columna("Vendedor",              "vendedor",              TEXTO),
    Columna("Valor factura",         "valor_factura",         NUMERO),
    Columna("Base gravable",         "base_gravable",         NUMERO),
    Columna("IVA factura",           "iva_factura",           NUMERO),
    Columna("Descuento factura",     "descuento_factura",     NUMERO),
    Columna("Moneda",                "moneda",                TEXTO),
    Columna("TRM",                   "trm",                   TASA),
    Columna("Valor aplicado",        "valor_aplicado",        NUMERO, requerida=True),
    Columna("Valor pagado sin IVA",  "valor_pagado_sin_iva",  NUMERO, requerida=True),
    Columna("Comisión SAP",          "comision_sap",          NUMERO),
]

# Comisiones, reporte 4: una fila por linea de factura de una reconciliacion
# interna manual, automatica o semiautomatica (OITR + ITR1) que sea un cruce de
# anticipo o una compensacion cliente-proveedor.
CRUCES: list[Columna] = [
    Columna("Número cruce",             "numero_cruce",             ENTERO, requerida=True),
    Columna("Línea",                    "linea",                    ENTERO, requerida=True),
    Columna("Fecha cruce",              "fecha_cruce",              FECHA,  requerida=True),
    Columna("Tipo cruce",               "tipo_cruce",               TEXTO,  requerida=True),
    Columna("Tipo conciliación",        "tipo_conciliacion",        ENTERO, requerida=True),
    Columna("Estado",                   "estado",                   TEXTO,  requerida=True),
    Columna("Nit",                      "nit",                      TEXTO),
    Columna("Código cliente",           "codigo_cliente",           TEXTO,  requerida=True),
    Columna("Código proveedor",         "codigo_proveedor",         TEXTO),
    Columna("Razón social",             "razon_social",             TEXTO),
    Columna("DocEntry factura",         "doc_entry_factura",        ENTERO, requerida=True),
    Columna("Número factura",           "numero_factura",           ENTERO),
    Columna("Fecha factura",            "fecha_factura",            FECHA),
    Columna("SlpCode factura",          "slp_code_factura",         ENTERO, requerida=True),
    Columna("Vendedor factura",         "vendedor_factura",         TEXTO),
    Columna("Valor factura",            "valor_factura",            NUMERO),
    Columna("Base gravable",            "base_gravable",            NUMERO),
    Columna("IVA factura",              "iva_factura",              NUMERO),
    Columna("Valor compensado",         "valor_compensado",         NUMERO, requerida=True),
    Columna("Valor compensado sin IVA", "valor_compensado_sin_iva", NUMERO, requerida=True),
    Columna("Usuario",                  "usuario",                  TEXTO),
]

# Comisiones, reporte 5: todos los clientes (OCRD, CardType 'C').
MAESTRO_CLIENTES: list[Columna] = [
    Columna("Código cliente",     "codigo_cliente",     TEXTO,  requerida=True),
    Columna("Nit",                "nit",                TEXTO),
    Columna("Razón social",       "razon_social",       TEXTO),
    Columna("SlpCode",            "slp_code",           ENTERO, requerida=True),
    Columna("Vendedor",           "vendedor",           TEXTO),
    Columna("Canal",              "canal",              TEXTO),
    Columna("Ciudad",             "ciudad",             TEXTO),
    Columna("Código proveedor",   "codigo_proveedor",   TEXTO),
    Columna("Estado",             "estado",             TEXTO,  requerida=True),
    Columna("Fecha modificación", "fecha_modificacion", FECHA),
]

# Comisiones, reporte 6: todos los vendedores (OSLP, SlpCode >= 0).
MAESTRO_VENDEDORES: list[Columna] = [
    Columna("SlpCode",            "slp_code",           ENTERO, requerida=True),
    Columna("Nombre",             "nombre",             TEXTO,  requerida=True),
    Columna("Estado",             "estado",             TEXTO,  requerida=True),
    Columna("Correo",             "correo",             TEXTO),
    Columna("Canal",              "canal",              TEXTO),
    Columna("Zona",               "zona",               TEXTO),
    Columna("Fecha modificación", "fecha_modificacion", FECHA),
]

# Etiqueta legible por rango, en el orden en que debe mostrarse la web.
ORDEN_RANGOS = [
    "Sin vencer", "0-30", "31-60", "61-90",
    "91-120", "121-150", "151-180", "> 180",
]


# Lotes que el robot sabe publicar, por el segmento {lote} de la URL.
# La cartera no tiene llave natural: la consulta no trae un id unico de linea.
# Ventas, recaudos y cruces admiten 0 filas: un dia sin movimientos es valido.
LOTES: dict[str, Lote] = {
    lote.nombre: lote
    for lote in (
        Lote("cartera", "cartera.sql", CARTERA),
        Lote("asesores", "asesores.sql", ASESORES, llave=("slp_code",)),
        Lote("contactos", "contactos_clientes.sql", CONTACTOS,
             llave=("codigo_sn", "contacto_id")),
        Lote("ventas", "ventas.sql", VENTAS,
             llave=("tipo_objeto", "doc_entry", "linea"), permite_vacio=True),
        Lote("recaudos", "recaudos.sql", RECAUDOS,
             llave=("doc_entry_pago", "linea"), permite_vacio=True),
        Lote("cruces", "cruces.sql", CRUCES,
             llave=("numero_cruce", "linea"), permite_vacio=True),
        Lote("maestro_clientes", "maestro_clientes.sql", MAESTRO_CLIENTES,
             llave=("codigo_cliente",)),
        Lote("maestro_vendedores", "maestro_vendedores.sql", MAESTRO_VENDEDORES,
             llave=("slp_code",)),
    )
}


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
