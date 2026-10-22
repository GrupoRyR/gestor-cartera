-- Cruces de anticipos y compensaciones cliente-proveedor SEGUREX
-- (SAP Business One sobre HANA)
-- Lote "cruces" del gestor de cartera (Comisiones, Parte B reporte 4):
-- recaudos que no pasan por un pago aplicado a factura (RCT2) y que tambien
-- generan comision.
--
-- VALIDAR CON DATOS REALES: TODA esta consulta. El reporte 4 se escribio para
--   SAP ECC (BSAD, BSAK, BKPF no existen en Business One) y esta traduccion no
--   se ha contrastado con cruces reales. Compararla con los cruces digitados a
--   mano de un mes cerrado antes de agregarla a ETL_LOTES.
--
-- Fuente: reconciliaciones internas (OITR cabecera, ITR1 lineas). Aqui se ven,
-- por ejemplo, un anticipo recibido a cuenta y despues reconciliado con la
-- factura, o la factura de un cliente compensada con una factura de proveedor
-- del mismo tercero.
--
-- Una fila por linea de factura de clientes (ITR1."SrcObjTyp" = '13') de una
-- reconciliacion manual (0), automatica (1) o semiautomatica (2). Quedan fuera
-- las que SAP crea solo al registrar otros documentos: pago (3), nota credito
-- (4), reversion (5), anulacion (7) y los demas tipos.
-- VALIDAR CON DATOS REALES: que los cruces que hoy se digitan a mano sean
--   justo los ReconType 0, 1 y 2.
--
-- Solo entran los cruces de anticipos y las compensaciones cliente-proveedor
-- (confirmado por Miguel). "Tipo cruce" mira las demas lineas de la misma
-- reconciliacion:
--   anticipo           -> tiene una linea de pago recibido (SrcObjTyp '24')
--   cliente-proveedor  -> tiene una linea de un proveedor (OCRD."CardType" 'S')
--                         o de un documento de compras (18, 19) o pago
--                         efectuado (46)
-- Una reconciliacion sin ninguna de las dos (por ejemplo, factura contra
-- asiento o contra nota credito) no es un cruce comisionable y queda fuera.
-- Si tiene las dos, cuenta como anticipo.
--
-- "SrcObjTyp" es texto (NVARCHAR) en B1 y se compara como texto. "ReconType" se
-- convierte a entero para no depender de su tipo.
--
-- Llave natural: "Número cruce" + "Línea". Incluye los cruces anulados
-- (OITR."Canceled" = 'Y') con su estado.
-- Ventana: fecha de la reconciliacion desde el primer dia del mes en curso
-- menos 7 dias hasta hoy; la aritmetica esta explicada en ventas.sql.
--
-- Montos en moneda local (COP). "Valor compensado" es ITR1."ReconSum" (con
-- IVA); "Valor compensado sin IVA" aplica la proporcion sin IVA de la factura
-- (1 - VatSum / DocTotal), igual que recaudos.sql.
--
-- Valores de "Estado": anulado | vigente
SELECT
    T0."ReconNum"                                               AS "Número cruce",
    T1."LineSeq"                                                AS "Línea",
    T0."ReconDate"                                              AS "Fecha cruce",
    CASE WHEN T4."TienePago" = 1 THEN 'anticipo'
         ELSE 'cliente-proveedor' END                           AS "Tipo cruce",
    CAST(T0."ReconType" AS INTEGER)                             AS "Tipo conciliación",
    CASE WHEN T0."Canceled" = 'Y' THEN 'anulado' ELSE 'vigente' END AS "Estado",
    T3."LicTradNum"                                             AS "Nit",
    IFNULL(T2."CardCode", T1."ShortName")                       AS "Código cliente",
    -- VALIDAR CON DATOS REALES: proveedor = el de una linea de proveedor de la
    -- misma reconciliacion; si no hay, el socio vinculado del cliente
    -- (OCRD."ConnBP"). Confirmar que asi se identifica el tercero cruzado.
    IFNULL(T4."Proveedor", NULLIF(TRIM(T3."ConnBP"), ''))       AS "Código proveedor",
    IFNULL(T2."CardName", T3."CardName")                        AS "Razón social",
    T1."SrcObjAbs"                                              AS "DocEntry factura",
    T2."DocNum"                                                 AS "Número factura",
    -- VALIDAR CON DATOS REALES: fecha de la factura = DocDate, como en
    -- recaudos.sql.
    T2."DocDate"                                                AS "Fecha factura",
    IFNULL(T2."SlpCode", -1)                                    AS "SlpCode factura",
    T5."SlpName"                                                AS "Vendedor factura",
    T2."DocTotal"                                               AS "Valor factura",
    T2."DocTotal" - T2."VatSum"                                 AS "Base gravable",
    T2."VatSum"                                                 AS "IVA factura",
    IFNULL(T1."ReconSum", 0)                                    AS "Valor compensado",
    CASE WHEN IFNULL(T2."DocTotal", 0) <> 0
         THEN ROUND(IFNULL(T1."ReconSum", 0)
                    * (1 - T2."VatSum" / T2."DocTotal"), 2)
         ELSE IFNULL(T1."ReconSum", 0)
    END                                                         AS "Valor compensado sin IVA",
    -- VALIDAR CON DATOS REALES: el usuario que hizo el cruce no tiene una
    -- columna confirmada en OITR (candidata: un "UserSign" unido a OUSR).
    -- Va en NULL.
    CAST(NULL AS NVARCHAR(100))                                 AS "Usuario"
FROM "OITR" T0
INNER JOIN "ITR1" T1 ON T1."ReconNum" = T0."ReconNum"
LEFT  JOIN "OINV" T2 ON T2."DocEntry" = T1."SrcObjAbs"
LEFT  JOIN "OCRD" T3 ON T3."CardCode" = IFNULL(T2."CardCode", T1."ShortName")
-- Resumen de cada reconciliacion de la ventana: con que mas se cruzo la factura.
LEFT  JOIN (
    SELECT
        T6."ReconNum",
        MAX(CASE WHEN T6."SrcObjTyp" = '24' THEN 1 ELSE 0 END)  AS "TienePago",
        MAX(CASE WHEN T8."CardType" = 'S'
                   OR T6."SrcObjTyp" IN ('18', '19', '46')
                 THEN 1 ELSE 0 END)                             AS "TieneProveedor",
        MIN(CASE WHEN T8."CardType" = 'S' THEN T8."CardCode" END) AS "Proveedor"
    FROM "ITR1" T6
    INNER JOIN "OITR" T7 ON T7."ReconNum" = T6."ReconNum"
    LEFT  JOIN "OCRD" T8 ON T8."CardCode" = T6."ShortName"
    WHERE T7."ReconDate" >= ADD_DAYS(CURRENT_DATE, -6 - DAYOFMONTH(CURRENT_DATE))
      AND T7."ReconDate" <= CURRENT_DATE
    GROUP BY T6."ReconNum"
) T4 ON T4."ReconNum" = T0."ReconNum"
LEFT  JOIN "OSLP" T5 ON T5."SlpCode" = T2."SlpCode"
WHERE T1."SrcObjTyp" = '13'
  AND CAST(T0."ReconType" AS INTEGER) IN (0, 1, 2)
  AND T0."ReconDate" >= ADD_DAYS(CURRENT_DATE, -6 - DAYOFMONTH(CURRENT_DATE))
  AND T0."ReconDate" <= CURRENT_DATE
  AND (T4."TienePago" = 1 OR T4."TieneProveedor" = 1)
ORDER BY T0."ReconDate", T0."ReconNum", T1."LineSeq"
