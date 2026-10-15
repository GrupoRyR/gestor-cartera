-- ===========================================================================
--  Bitacora de cobro: notas y compromisos de pago
-- ===========================================================================
--
--  Es la primera tabla que ESCRIBE la web (todo lo demas viene de SAP y es
--  de solo lectura). Convierte el portal en herramienta de trabajo: el asesor
--  registra "hable con tesoreria, pagan el 25" y la agenda se lo recuerda.
--
--  Permisos: exactamente los mismos que la cartera. Una gestion pertenece a
--  un codigo de asesor (slp_code), asi que el asesor ve las suyas, el gerente
--  las de su equipo y el administrador todas. Nadie puede escribir una
--  gestion sobre una cartera que no le corresponde: eso lo impone el
--  WITH CHECK de la politica, no la interfaz.
--
--  El estado "incumplido" NO se guarda: se deduce comparando la fecha
--  prometida con hoy. Guardarlo obligaria a un proceso que lo actualice cada
--  noche y podria quedar desfasado; calcularlo nunca miente.
-- ===========================================================================

CREATE TABLE IF NOT EXISTS "gestiones" (
    "id"                bigserial PRIMARY KEY,
    -- A quien se le cobra
    "codigo_sn"         text        NOT NULL,
    "nombre_sn"         text,
    -- Factura concreta (opcional): una gestion puede ser del cliente completo
    "documento"         text,
    -- Dueno de la cartera: es la llave de permisos
    "slp_code"          integer     NOT NULL,
    "tipo"              text        NOT NULL DEFAULT 'nota'
                                    CHECK ("tipo" IN ('nota', 'compromiso')),
    "nota"              text        NOT NULL,
    -- Solo para tipo = 'compromiso'
    "fecha_compromiso"  date,
    "valor_compromiso"  numeric(19,2),
    "estado"            text        NOT NULL DEFAULT 'abierto'
                                    CHECK ("estado" IN ('abierto', 'cumplido', 'cancelado')),
    "creado_por"        text        NOT NULL,
    "creado_en"         timestamptz NOT NULL DEFAULT now(),
    "actualizado_en"    timestamptz NOT NULL DEFAULT now(),

    -- Un compromiso sin fecha no sirve para nada: no se puede agendar.
    CONSTRAINT "gestiones_compromiso_con_fecha"
        CHECK ("tipo" <> 'compromiso' OR "fecha_compromiso" IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS "ix_gestiones_cliente"
    ON "gestiones" ("codigo_sn", "creado_en" DESC);
CREATE INDEX IF NOT EXISTS "ix_gestiones_slp"
    ON "gestiones" ("slp_code");
-- La agenda pregunta siempre por compromisos abiertos ordenados por fecha.
CREATE INDEX IF NOT EXISTS "ix_gestiones_agenda"
    ON "gestiones" ("fecha_compromiso")
    WHERE "tipo" = 'compromiso' AND "estado" = 'abierto';

ALTER TABLE "gestiones" ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "gestiones_lectura" ON "gestiones";
CREATE POLICY "gestiones_lectura" ON "gestiones" FOR SELECT TO authenticated
    USING ("es_admin"() OR "slp_code" = ANY ("mis_slp_codes"()));

-- Al insertar se exige ademas que el autor sea quien dice ser: asi la
-- bitacora sirve como registro de quien gestiono.
DROP POLICY IF EXISTS "gestiones_insertar" ON "gestiones";
CREATE POLICY "gestiones_insertar" ON "gestiones" FOR INSERT TO authenticated
    WITH CHECK (
        ("es_admin"() OR "slp_code" = ANY ("mis_slp_codes"()))
        AND lower("creado_por") = lower(auth.email())
    );

-- Editar (marcar cumplido, corregir la nota) dentro de la propia cartera.
DROP POLICY IF EXISTS "gestiones_actualizar" ON "gestiones";
CREATE POLICY "gestiones_actualizar" ON "gestiones" FOR UPDATE TO authenticated
    USING ("es_admin"() OR "slp_code" = ANY ("mis_slp_codes"()))
    WITH CHECK ("es_admin"() OR "slp_code" = ANY ("mis_slp_codes"()));

-- No se permite borrar: la bitacora es historia de la gestion de cobro.
-- Un compromiso equivocado se marca 'cancelado'.

CREATE OR REPLACE FUNCTION "gestiones_tocar"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    NEW."actualizado_en" := now();
    RETURN NEW;
END $$;

DROP TRIGGER IF EXISTS "tg_gestiones_tocar" ON "gestiones";
CREATE TRIGGER "tg_gestiones_tocar" BEFORE UPDATE ON "gestiones"
    FOR EACH ROW EXECUTE FUNCTION "gestiones_tocar"();
