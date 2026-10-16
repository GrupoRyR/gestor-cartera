-- Contactos de los clientes SEGUREX (SAP Business One sobre HANA)
-- Lote "contactos" del gestor de cartera: reemplaza la carga manual de
-- contactos. Lo consume Core (tabla contacts).
--
-- Dos tipos de fila, unidas con UNION ALL:
--   1. Una por persona de contacto del cliente (OCPR).
--   2. Una de "datos generales" por cliente (OCRD): el correo y los telefonos
--      de la ficha del cliente, con "Id contacto" y "Nombre" en NULL.
-- Llave natural: "Código SN" + "Id contacto" (la fila general lleva NULL).
--
-- Los textos opcionales pasan por NULLIF(TRIM(x), ''): SAP guarda los vacios
-- como cadena vacia y la plataforma necesita NULL para saber que el dato falta.
-- Telefono: el primero que tenga valor entre Tel1 y Tel2 (Phone1 y Phone2 en
-- la fila general). OCPR."Cellolar" es el nombre real de la columna en SAP.
-- Activo: OCPR."Active" en los contactos; en la fila general, 'N' si el
-- cliente esta congelado (OCRD."frozenFor" = 'Y').
--
-- VALIDAR CON DATOS REALES: "Principal" compara OCPR."Name" con
--   OCRD."CntctPrsn", donde SAP guarda el NOMBRE (no el codigo) del contacto
--   por defecto. Revisar en clientes con varios contactos que coincida
--   exactamente (mayusculas, espacios) y que cada cliente tenga un solo 'Y'.
-- VALIDAR CON DATOS REALES: la fila general sale para todos los clientes,
--   aunque no tenga correo ni telefonos. Confirmar con Core si la quiere asi o
--   solo cuando trae algun dato.
SELECT
    T0."CardCode"                                                      AS "Código SN",
    NULLIF(TRIM(T0."LicTradNum"), '')                                  AS "Nit",
    T1."CntctCode"                                                     AS "Id contacto",
    NULLIF(TRIM(T1."Name"), '')                                        AS "Nombre",
    NULLIF(TRIM(T1."Position"), '')                                    AS "Cargo",
    NULLIF(TRIM(T1."E_MailL"), '')                                     AS "Correo",
    IFNULL(NULLIF(TRIM(T1."Tel1"), ''), NULLIF(TRIM(T1."Tel2"), ''))   AS "Teléfono",
    NULLIF(TRIM(T1."Cellolar"), '')                                    AS "Celular",
    CASE WHEN T1."Name" = T0."CntctPrsn" THEN 'Y' ELSE 'N' END         AS "Principal",
    IFNULL(T1."Active", 'Y')                                           AS "Activo"
FROM "OCRD" T0
INNER JOIN "OCPR" T1 ON T1."CardCode" = T0."CardCode"
WHERE T0."CardType" = 'C'

UNION ALL

SELECT
    T0."CardCode",
    NULLIF(TRIM(T0."LicTradNum"), ''),
    CAST(NULL AS INTEGER),
    CAST(NULL AS NVARCHAR(100)),
    CAST(NULL AS NVARCHAR(100)),
    NULLIF(TRIM(T0."E_Mail"), ''),
    IFNULL(NULLIF(TRIM(T0."Phone1"), ''), NULLIF(TRIM(T0."Phone2"), '')),
    NULLIF(TRIM(T0."Cellular"), ''),
    'N',
    CASE WHEN T0."frozenFor" = 'Y' THEN 'N' ELSE 'Y' END
FROM "OCRD" T0
WHERE T0."CardType" = 'C'

ORDER BY "Código SN", "Id contacto"
