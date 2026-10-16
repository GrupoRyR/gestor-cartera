-- Maestro de clientes SEGUREX (SAP Business One sobre HANA)
-- Lote "maestro_clientes" del gestor de cartera (Comisiones, Parte B
-- reporte 5): asigna cada cruce al vendedor vigente del cliente y permite
-- validar que ningun cliente quede sin vendedor.
--
-- Una fila por cliente (OCRD."CardType" = 'C'), activo o no.
-- Llave natural: "Código cliente".
--
-- "SlpCode" es el vendedor asignado hoy en la ficha del cliente (-1 si no
-- tiene). El codigo de comision es el prefijo numerico del nombre del
-- vendedor ("14 MONICA RIVERA AREVALO"); Comisiones lo extrae de "Vendedor".
-- "Código proveedor" es el socio de negocios vinculado (OCRD."ConnBP"): el
-- codigo del mismo tercero como proveedor, si lo es.
-- "Fecha modificación" es OCRD."UpdateDate": la ultima modificacion de
-- cualquier campo de la ficha, no solo del vendedor.
--
-- Valores de "Estado": activo | inactivo
SELECT
    T0."CardCode"                                               AS "Código cliente",
    T0."LicTradNum"                                             AS "Nit",
    T0."CardName"                                               AS "Razón social",
    IFNULL(T0."SlpCode", -1)                                    AS "SlpCode",
    T1."SlpName"                                                AS "Vendedor",
    -- VALIDAR CON DATOS REALES: no se sabe de donde sale el canal (grupo del
    -- cliente OCRG."GroupName" o un campo de usuario U_). Va en NULL.
    CAST(NULL AS NVARCHAR(100))                                 AS "Canal",
    T0."City"                                                   AS "Ciudad",
    NULLIF(TRIM(T0."ConnBP"), '')                               AS "Código proveedor",
    -- VALIDAR CON DATOS REALES: inactivo = congelado (frozenFor = 'Y'); no se
    -- miran los rangos de fechas de activo/congelado (validFrom, frozenFrom...).
    CASE WHEN T0."frozenFor" = 'Y' THEN 'inactivo' ELSE 'activo' END AS "Estado",
    T0."UpdateDate"                                             AS "Fecha modificación"
FROM "OCRD" T0
LEFT JOIN "OSLP" T1 ON T1."SlpCode" = T0."SlpCode"
WHERE T0."CardType" = 'C'
ORDER BY T0."CardCode"
