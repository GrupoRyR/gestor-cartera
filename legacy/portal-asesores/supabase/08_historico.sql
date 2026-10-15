-- ===========================================================================
--  Historico diario de cartera por asesor
-- ===========================================================================
--
--  La tabla "cartera" es una foto: el robot la borra y la vuelve a escribir
--  en cada corrida, asi que el sistema no tiene memoria y no puede responder
--  la pregunta que siempre se hace el negocio: "voy mejor o peor que antes".
--
--  Esta tabla guarda una fila por asesor y por dia con los totales. Cuesta
--  una sentencia por corrida y no crece: 27 asesores x 365 dias son 10.000
--  filas al ano.
--
--  Clave por (fecha, slp_code): el robot corre dos veces al dia, y la
--  segunda corrida simplemente corrige la foto del dia.
-- ===========================================================================

CREATE TABLE IF NOT EXISTS "historico_cartera" (
    "fecha"            date    NOT NULL,
    "slp_code"         integer NOT NULL,
    "documentos"       integer NOT NULL,
    "clientes"         integer NOT NULL,
    "saldo"            numeric(19,2) NOT NULL,
    "saldo_base"       numeric(19,2),
    "sin_vencer"       numeric(19,2) NOT NULL,
    "vencido"          numeric(19,2) NOT NULL,
    "vencido_critico"  numeric(19,2) NOT NULL,
    "actualizado_en"   timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY ("fecha", "slp_code")
);

CREATE INDEX IF NOT EXISTS "ix_historico_fecha"
    ON "historico_cartera" ("fecha" DESC);

ALTER TABLE "historico_cartera" ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "historico_lectura" ON "historico_cartera";
CREATE POLICY "historico_lectura" ON "historico_cartera" FOR SELECT TO authenticated
    USING ("es_admin"() OR "slp_code" = ANY ("mis_slp_codes"()));

-- Solo el robot escribe aqui (se conecta con el rol de servicio, que no pasa
-- por RLS). Ningun usuario de la web puede insertar ni modificar historia.
