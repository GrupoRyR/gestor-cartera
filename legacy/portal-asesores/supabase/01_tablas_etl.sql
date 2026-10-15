-- Tablas que escribe el robot ETL. Generadas desde etl/esquema.py
-- para que coincidan exactamente. NO editar a mano.

CREATE TABLE IF NOT EXISTS "cartera" (
    "slp_code" integer,
    "email_asesor" text,
    "codigo_sn" text,
    "nombre_sn" text,
    "nit" text,
    "grupo" text,
    "municipio" text,
    "cuenta_visible" text,
    "tipo_doc" text,
    "documento" text,
    "documento_base" text,
    "referencia_1" text,
    "no_referencia" text,
    "asesor" text,
    "total_plazos" integer,
    "no_plazo" integer,
    "condicion_pago" text,
    "fecha_contabilizacion" date,
    "fecha_vencimiento" date,
    "fecha_documento" date,
    "dias" integer,
    "limite_credito" numeric(19,2),
    "importe_original" numeric(19,2),
    "base_gravable" numeric(19,2),
    "saldo_base" numeric(19,2),
    "saldo_vencido" numeric(19,2),
    "valor_abonado" numeric(19,2),
    "sin_vencer" numeric(19,2),
    "valor_cheques" numeric(19,2),
    "d_0_30" numeric(19,2),
    "d_31_60" numeric(19,2),
    "d_61_90" numeric(19,2),
    "d_91_120" numeric(19,2),
    "d_121_150" numeric(19,2),
    "d_151_180" numeric(19,2),
    "d_mas_180" numeric(19,2),
    "saldo" numeric(19,2),
    "rango" text
);

CREATE TABLE IF NOT EXISTS "asesores" (
    "slp_code" integer,
    "nombre" text,
    "email" text,
    "activo" text,
    "clientes" integer
);
CREATE TABLE IF NOT EXISTS "etl_runs" (
    "id"              bigserial PRIMARY KEY,
    "iniciado_en"     timestamptz NOT NULL,
    "terminado_en"    timestamptz,
    "estado"          text NOT NULL,
    "filas_cartera"   integer,
    "filas_asesores"  integer,
    "duracion_seg"    numeric(10,1),
    "etapa_fallida"   text,
    "mensaje_error"   text
);

CREATE INDEX IF NOT EXISTS "ix_cartera_slp"    ON "cartera" ("slp_code");
CREATE INDEX IF NOT EXISTS "ix_cartera_email"  ON "cartera" ("email_asesor");
CREATE INDEX IF NOT EXISTS "ix_cartera_cliente" ON "cartera" ("codigo_sn");
CREATE INDEX IF NOT EXISTS "ix_cartera_venc"   ON "cartera" ("fecha_vencimiento");
CREATE INDEX IF NOT EXISTS "ix_asesores_email" ON "asesores" ("email");
CREATE INDEX IF NOT EXISTS "ix_etl_runs_fin"   ON "etl_runs" ("terminado_en" DESC);
