-- ===========================================================================
--  Usuarios iniciales
--  Ejecutar DESPUES de 02_usuarios_y_permisos.sql
-- ===========================================================================
--
--  Solo se siembran los usuarios cuyo correo esta confirmado. Los 24 asesores
--  restantes se dan de alta desde el panel de administracion de la web, que
--  ya lista los nombres tal como vienen de SAP: basta escribir el correo.
--
--  NO se inventan correos: asignar el correo equivocado significa que una
--  persona vea la cartera de otra.
-- ===========================================================================

INSERT INTO "usuarios" ("email", "nombre", "rol", "slp_codes", "creado_por")
VALUES
    -- Gerencia: ve la cartera completa, incluidas OFICINA - VARIOS,
    -- E-COMMERCE y OTROS INGRESOS, que no tienen titular.
    ('leonardo.herrera@segurex.com', 'Leonardo Herrera', 'admin',
     '{}', 'seed inicial'),

    -- Zona "53 VACANTE" (SlpCode 48) asignada a Maria Uribe,
    -- que ademas conserva su cartera propia (SlpCode 34).
    ('Maria.Uribe@segurex.com', 'Maria Monica Uribe Buendia', 'asesor',
     '{34,48}', 'seed inicial'),

    -- Zona "34 VACANTE" (SlpCode 40) asignada a Harold Salazar,
    -- que ademas conserva su cartera propia (SlpCode 38).
    ('Harold.Salazar@segurex.com', 'Harold Marin Salazar Munoz', 'asesor',
     '{38,40}', 'seed inicial'),

    -- Zona "69 VACANTE" (SlpCode 51): Construccion Antioquia.
    ('ConstruccionAntioquia@segurex.com', 'Construccion Antioquia', 'asesor',
     '{51}', 'seed inicial')
ON CONFLICT (lower("email")) DO UPDATE
    SET "nombre"    = EXCLUDED."nombre",
        "rol"       = EXCLUDED."rol",
        "slp_codes" = EXCLUDED."slp_codes";

-- ---------------------------------------------------------------------------
-- CUENTA DE PRUEBA. Permite entrar al panel y dar de alta a los demas
-- asesores sin tener aun acceso al correo corporativo.
-- >>> ELIMINALA antes de poner la web en produccion:
--     DELETE FROM "usuarios" WHERE "email" = 'leohm010@gmail.com';
-- ---------------------------------------------------------------------------
INSERT INTO "usuarios" ("email", "nombre", "rol", "slp_codes", "creado_por")
VALUES ('leohm010@gmail.com', 'Leonardo Herrera (pruebas)', 'admin',
        '{}', 'seed inicial - cuenta de prueba')
ON CONFLICT (lower("email")) DO NOTHING;
