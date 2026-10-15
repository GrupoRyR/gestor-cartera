-- ===========================================================================
--  Rol "gerente": ve la cartera de VARIOS asesores, sin ser administrador
-- ===========================================================================
--
--  Caso real: Jessika y Andrea son gerentes de ventas y tienen asesores a
--  cargo. Necesitan ver la cartera de su equipo, pero NO la de toda la
--  empresa (eso es del administrador).
--
--  Se resuelve con el mismo mecanismo que ya existe: un usuario tiene varios
--  slp_codes. La unica diferencia es que a un gerente SI se le permite
--  compartir codigos con los asesores de su equipo, mientras que entre
--  asesores los codigos siguen siendo exclusivos (dos asesores con el mismo
--  codigo casi siempre es un error de digitacion).
--
--  Las politicas RLS no cambian: "mis_slp_codes()" ya funciona para cualquier
--  rol. Solo hay que admitir el nuevo valor en la restriccion.
-- ===========================================================================

DO $$
DECLARE
    nombre_restriccion text;
BEGIN
    SELECT conname INTO nombre_restriccion
    FROM pg_constraint
    WHERE conrelid = '"usuarios"'::regclass
      AND contype = 'c'
      AND pg_get_constraintdef(oid) ILIKE '%rol%';

    IF nombre_restriccion IS NOT NULL THEN
        EXECUTE format('ALTER TABLE "usuarios" DROP CONSTRAINT %I', nombre_restriccion);
    END IF;
END $$;

ALTER TABLE "usuarios"
    ADD CONSTRAINT "usuarios_rol_check"
    CHECK ("rol" IN ('asesor', 'gerente', 'admin'));

-- La vista de conflictos solo debe alertar de codigos duplicados ENTRE
-- ASESORES. Que un gerente comparta el codigo de su asesor es lo esperado.
-- Se recrea (no CREATE OR REPLACE): cambia el nombre de una columna.
DROP VIEW IF EXISTS "v_conflictos_asignacion";

CREATE VIEW "v_conflictos_asignacion"
WITH (security_invoker = true) AS
WITH asignados AS (
    SELECT unnest("slp_codes") AS "slp_code", "email"
    FROM "usuarios"
    WHERE "activo" AND "rol" = 'asesor'
)
SELECT
    c."slp_code",
    max(c."asesor")                       AS "asesor",
    count(DISTINCT a."email")             AS "asesores_asignados",
    string_agg(DISTINCT a."email", ', ')  AS "emails"
FROM "cartera" c
LEFT JOIN asignados a ON a."slp_code" = c."slp_code"
GROUP BY c."slp_code"
HAVING count(DISTINCT a."email") <> 1;
