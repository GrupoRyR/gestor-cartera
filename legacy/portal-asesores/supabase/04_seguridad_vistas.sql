-- ===========================================================================
--  Las vistas de Postgres se ejecutan por defecto con los permisos de su
--  propietario, saltandose el RLS de las tablas que consultan. La vista de
--  conflictos lee "cartera", asi que sin esto cualquier asesor autenticado
--  podria ver totales de la cartera completa a traves de ella.
--  security_invoker = true hace que la vista respete el RLS de quien consulta.
-- ===========================================================================
ALTER VIEW "v_conflictos_asignacion" SET (security_invoker = true);

-- Estas dos leen tablas que cualquier autenticado ya puede consultar,
-- pero se marcan igual para no depender de ese detalle en el futuro.
ALTER VIEW "v_asesores"     SET (security_invoker = true);
ALTER VIEW "v_ultima_carga" SET (security_invoker = true);
