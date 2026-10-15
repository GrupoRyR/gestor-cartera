-- ===========================================================================
--  Usuarios, roles y permisos de la web de cartera
--  Ejecutar en Supabase -> SQL Editor, DESPUES de 01_tablas_etl.sql
-- ===========================================================================
--
--  Modelo: un usuario -> VARIOS codigos de asesor (slp_codes).
--  Es imprescindible porque en SAP hay personas con dos codigos
--  (Luis Daza 46/47, Jenny Buitrago 42/45, Adriana Russi 54/43,
--  Andrea Hincapie 53/111) y porque a algunos asesores se les asignaron
--  zonas que en SAP figuran como VACANTE.
--
--  La seguridad se aplica con Row Level Security: aunque alguien manipule
--  la interfaz, Postgres no le devuelve filas de otro asesor.
-- ===========================================================================

-- ---------------------------------------------------------------- usuarios
CREATE TABLE IF NOT EXISTS "usuarios" (
    "id"          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "email"       text        NOT NULL,
    "nombre"      text        NOT NULL,
    "rol"         text        NOT NULL DEFAULT 'asesor'
                              CHECK ("rol" IN ('asesor', 'admin')),
    "slp_codes"   integer[]   NOT NULL DEFAULT '{}',
    "activo"      boolean     NOT NULL DEFAULT true,
    "creado_en"   timestamptz NOT NULL DEFAULT now(),
    "creado_por"  text
);

-- El correo es la llave del login: unico sin distinguir mayusculas.
CREATE UNIQUE INDEX IF NOT EXISTS "ux_usuarios_email"
    ON "usuarios" (lower("email"));

-- ------------------------------------------------------- funciones de apoyo
-- SECURITY DEFINER: se consultan dentro de las politicas de otras tablas,
-- asi que no pueden quedar sujetas a las politicas de "usuarios".

CREATE OR REPLACE FUNCTION "es_admin"() RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT EXISTS (
        SELECT 1 FROM "usuarios"
        WHERE lower("email") = lower(auth.email())
          AND "rol" = 'admin'
          AND "activo"
    );
$$;

CREATE OR REPLACE FUNCTION "mis_slp_codes"() RETURNS integer[]
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE(
        (SELECT "slp_codes" FROM "usuarios"
         WHERE lower("email") = lower(auth.email()) AND "activo"),
        '{}'::integer[]
    );
$$;

-- ----------------------------------------------------------------- politicas
ALTER TABLE "usuarios" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "cartera"  ENABLE ROW LEVEL SECURITY;
ALTER TABLE "asesores" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "etl_runs" ENABLE ROW LEVEL SECURITY;

-- usuarios: cada quien ve su ficha; el admin ve y administra todas.
DROP POLICY IF EXISTS "usuarios_ver_propio" ON "usuarios";
CREATE POLICY "usuarios_ver_propio" ON "usuarios" FOR SELECT TO authenticated
    USING (lower("email") = lower(auth.email()) OR "es_admin"());

DROP POLICY IF EXISTS "usuarios_admin_escribe" ON "usuarios";
CREATE POLICY "usuarios_admin_escribe" ON "usuarios" FOR ALL TO authenticated
    USING ("es_admin"()) WITH CHECK ("es_admin"());

-- cartera: el asesor solo ve los documentos de SUS codigos.
DROP POLICY IF EXISTS "cartera_por_asesor" ON "cartera";
CREATE POLICY "cartera_por_asesor" ON "cartera" FOR SELECT TO authenticated
    USING ("es_admin"() OR "slp_code" = ANY ("mis_slp_codes"()));

-- asesores y etl_runs: lectura para cualquier usuario autenticado
-- (etl_runs alimenta el aviso de "datos desactualizados").
DROP POLICY IF EXISTS "asesores_lectura" ON "asesores";
CREATE POLICY "asesores_lectura" ON "asesores" FOR SELECT TO authenticated
    USING (true);

DROP POLICY IF EXISTS "etl_runs_lectura" ON "etl_runs";
CREATE POLICY "etl_runs_lectura" ON "etl_runs" FOR SELECT TO authenticated
    USING (true);

-- ---------------------------------------------------------------- vistas
-- SAP guarda el nombre con un prefijo numerico interno ("84 JESSIKA ...").
-- Esta vista lo limpia para mostrarlo y marca las carteras sin persona real.
CREATE OR REPLACE VIEW "v_asesores" AS
SELECT
    "slp_code",
    "nombre"                                          AS "nombre_sap",
    regexp_replace("nombre", '^\s*\d+\s+', '')        AS "nombre",
    "email",
    "activo",
    "clientes",
    (regexp_replace("nombre", '^\s*\d+\s+', '') IN
        ('VACANTE', 'OFICINA - VARIOS', 'E-COMMERCE', 'OTROS INGRESOS'))
                                                      AS "sin_titular"
FROM "asesores";

-- Ultima ejecucion del robot: la web la usa para avisar si el dato no es de hoy.
CREATE OR REPLACE VIEW "v_ultima_carga" AS
SELECT "terminado_en", "estado", "filas_cartera", "duracion_seg",
       "etapa_fallida", "mensaje_error"
FROM "etl_runs"
ORDER BY "terminado_en" DESC NULLS LAST
LIMIT 1;

-- Detecta un codigo de asesor asignado a dos usuarios distintos, o ninguno.
CREATE OR REPLACE VIEW "v_conflictos_asignacion" AS
WITH asignados AS (
    SELECT unnest("slp_codes") AS "slp_code", "email"
    FROM "usuarios" WHERE "activo"
)
SELECT
    c."slp_code",
    max(c."asesor")                       AS "asesor",
    count(DISTINCT a."email")             AS "usuarios_asignados",
    string_agg(DISTINCT a."email", ', ')  AS "emails"
FROM "cartera" c
LEFT JOIN asignados a ON a."slp_code" = c."slp_code"
GROUP BY c."slp_code"
HAVING count(DISTINCT a."email") <> 1;
