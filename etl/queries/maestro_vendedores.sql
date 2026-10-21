-- Maestro de vendedores SEGUREX (SAP Business One sobre HANA)
-- Lote "maestro_vendedores" del gestor de cartera (Comisiones, Parte B
-- reporte 6): altas, bajas y cambios de codigos de vendedor.
--
-- Una fila por empleado de ventas (OSLP) con "SlpCode" >= 0; el -1 es el
-- "Ningun empleado de ventas" de SAP. Llave natural: "SlpCode".
-- El codigo de comision es el prefijo numerico de "Nombre"
-- ("14 MONICA RIVERA AREVALO" -> 14); Comisiones lo extrae.
-- El correo va en minusculas, igual que en asesores.sql.
--
-- Valores de "Estado": activo | inactivo (OSLP."Active"; vacio cuenta como activo)
SELECT
    T0."SlpCode"                                                AS "SlpCode",
    T0."SlpName"                                                AS "Nombre",
    CASE WHEN IFNULL(T0."Active", 'Y') = 'Y'
         THEN 'activo' ELSE 'inactivo' END                      AS "Estado",
    LOWER(NULLIF(TRIM(T0."Email"), ''))                         AS "Correo",
    -- VALIDAR CON DATOS REALES: el canal del vendedor no tiene una columna
    -- conocida en OSLP (posible campo de usuario U_). Va en NULL; hoy lo
    -- parametriza Comisiones con los equipos.
    CAST(NULL AS NVARCHAR(100))                                 AS "Canal",
    -- La zona va en NULL a proposito: SAP no la maneja (confirmado por Miguel).
    CAST(NULL AS NVARCHAR(100))                                 AS "Zona",
    -- VALIDAR CON DATOS REALES: OSLP no trae una fecha de modificacion
    -- confirmada (revisar si la version de B1 tiene "UpdateDate" en OSLP o su
    -- historial AHLP). Va en NULL.
    CAST(NULL AS DATE)                                          AS "Fecha modificación"
FROM "OSLP" T0
WHERE T0."SlpCode" >= 0
ORDER BY T0."SlpCode"
