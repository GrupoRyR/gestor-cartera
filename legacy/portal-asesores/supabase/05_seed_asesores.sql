-- ===========================================================================
--  Alta de los 15 asesores comerciales restantes
--  Ejecutar DESPUES de 03_seed_usuarios.sql
-- ===========================================================================
--
--  Los correos NO estan en SAP (OSLP.Email vacio en los 27 asesores). Se
--  confirmaron con el negocio el 2026-09-03. Dos de ellos son buzones por
--  zona, no personales:
--      Jorge Fabian Mantilla (44) -> distribucion.norte@segurex.com
--      Monica Rivera Arevalo (31) -> distribucion.boyaca@segurex.com
--
--  Los codigos van agrupados por PERSONA: cuatro asesores tienen dos codigos
--  en SAP y deben quedar en un unico usuario, o la mitad de su cartera no
--  aparece en su pantalla.
-- ===========================================================================

INSERT INTO "usuarios" ("email", "nombre", "rol", "slp_codes", "creado_por")
VALUES
    ('Paola.Vargas@segurex.com',        'Paola Andrea Vargas Marín',            'asesor', '{32}',     'alta 2026-09-03'),
    ('Adriana.Russi@segurex.com',       'Adriana Russi Parra',                  'asesor', '{43,54}',  'alta 2026-09-03'),
    ('Carolina.Ramirez@segurex.com',    'Carolina Ramírez Pedraza',             'asesor', '{33}',     'alta 2026-09-03'),
    ('Lina.Chacon@segurex.com',         'Lina Constanza Chacón Moque',          'asesor', '{35}',     'alta 2026-09-03'),
    ('Jessika.Quintero@segurex.com',    'Jessika del Socorro Quintero Álvarez', 'asesor', '{55}',     'alta 2026-09-03'),
    ('Luis.Daza@segurex.com',           'Luis Alberto Daza Cuartas',            'asesor', '{46,47}',  'alta 2026-09-03'),
    ('Maria.Pena@segurex.com',          'María del Carmen Peña Ariza',          'asesor', '{37}',     'alta 2026-09-03'),
    ('distribucion.boyaca@segurex.com', 'Mónica Rivera Arévalo',                'asesor', '{31}',     'alta 2026-09-03'),
    ('Jenny.Buitrago@segurex.com',      'Jenny Rocío Buitrago Ibáñez',          'asesor', '{42,45}',  'alta 2026-09-03'),
    ('distribucion.norte@segurex.com',  'Jorge Fabián Mantilla',                'asesor', '{44}',     'alta 2026-09-03'),
    ('Maria.Rodriguez@segurex.com',     'María Cristina Rodríguez Palomino',    'asesor', '{41}',     'alta 2026-09-03'),
    ('Andrea.Velasquez@segurex.com',    'Andrea Velásquez Robledo',             'asesor', '{50}',     'alta 2026-09-03'),
    ('Jhony.Tordecilla@segurex.com',    'Jhony Luis Tordecilla Doria',          'asesor', '{36}',     'alta 2026-09-03'),
    ('Andrea.Hincapie@segurex.com',     'Andrea Hincapié Ramírez',              'asesor', '{53,111}', 'alta 2026-09-03'),
    ('Liliam.Herrera@segurex.com',      'Liliam Haideidi Herrera Ramírez',      'asesor', '{56}',     'alta 2026-09-03')
ON CONFLICT (lower("email")) DO UPDATE
    SET "nombre"    = EXCLUDED."nombre",
        "rol"       = EXCLUDED."rol",
        "slp_codes" = EXCLUDED."slp_codes",
        "activo"    = true;
