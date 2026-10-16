-- Ventas detalladas SEGUREX (SAP Business One sobre HANA)
-- Lote "ventas" del gestor de cartera (Comisiones, Parte B reporte 1): base de
-- la comision por venta y del cumplimiento de presupuesto.
--
-- Una fila por linea de:
--   * factura de clientes (OINV/INV1, objeto 13); "DocSubType" = 'DN' es una
--     nota debito, que en B1 tambien es una factura de clientes;
--   * nota credito de clientes (ORIN/RIN1, objeto 14), con signo NEGATIVO en
--     cantidad, costo total, total e IVA: la suma de "Total" es la venta neta.
-- Se incluyen los documentos anulados (CANCELED = 'Y') y los documentos de
-- anulacion (CANCELED = 'C') con su estado: un original y su anulacion suman
-- cero y Comisiones los puede excluir o netear.
--
-- Llave natural: "Tipo objeto" + "DocEntry" + "Línea".
--
-- Ventana sin parametros (para que corra igual en el Query Manager): el mes en
-- curso mas los ultimos 7 dias del mes anterior, hasta hoy, por fecha de
-- contabilizacion (DocDate) o de documento (TaxDate).
--   ADD_DAYS(CURRENT_DATE, -6 - DAYOFMONTH(CURRENT_DATE))
--     = CURRENT_DATE - (DAYOFMONTH - 1)   -> primer dia del mes en curso
--                    - 7                  -> menos 7 dias
--   Ej: el 16/10 resta 22 dias -> 24/09; el 01/10 resta 7 dias -> 24/09.
--   CURRENT_DATE es la fecha del servidor HANA.
--
-- Montos en moneda local (COP). "Total" (base de comision) = LineTotal (antes
-- de IVA, ya con el descuento de la linea) menos el descuento global del
-- documento (DiscPrcnt), redondeado a 2 decimales y con signo.
-- Precios y costos unitarios van sin signo; solo los totales llevan signo.
--
-- Valores de texto:
--   "Clase documento":  factura | nota_debito | nota_credito
--   "Estado documento": anulado | anulacion | cerrado | abierto
SELECT
    X."ObjType"                                                AS "Tipo objeto",
    X."DocEntry"                                               AS "DocEntry",
    X."DocNum"                                                 AS "Número documento",
    X."LineNum"                                                AS "Línea",
    CASE
        WHEN X."ObjType" = 14 THEN 'nota_credito'
        WHEN X."DocSubType" = 'DN' THEN 'nota_debito'
        ELSE 'factura'
    END                                                        AS "Clase documento",
    CASE
        WHEN X."CANCELED" = 'Y' THEN 'anulado'
        WHEN X."CANCELED" = 'C' THEN 'anulacion'
        WHEN X."DocStatus" = 'C' THEN 'cerrado'
        ELSE 'abierto'
    END                                                        AS "Estado documento",
    -- VALIDAR CON DATOS REALES: la especificacion asigna la venta al periodo
    -- por "fecha de documento". En B1 TaxDate es la fecha del documento y
    -- DocDate la de contabilizacion (la de la factura electronica). Confirmar
    -- cual usa hoy el informe de ventas; Comisiones recibe las dos.
    X."TaxDate"                                                AS "Fecha documento",
    X."DocDate"                                                AS "Fecha contabilización",
    IFNULL(X."SlpCode", -1)                                    AS "SlpCode",
    T3."SlpName"                                               AS "Vendedor",
    -- VALIDAR CON DATOS REALES: no se sabe de donde sale el canal (grupo del
    -- cliente OCRG."GroupName", un campo de usuario U_ de OCRD u OSLP, o los
    -- equipos que parametriza Comisiones). Va en NULL hasta confirmarlo.
    CAST(NULL AS NVARCHAR(100))                                AS "Canal",
    X."CardCode"                                               AS "Código cliente",
    X."CardName"                                               AS "Cliente",
    T2."LicTradNum"                                            AS "Nit",
    T2."City"                                                  AS "Ciudad",
    -- VALIDAR CON DATOS REALES: pedido = documento base de la linea cuando es
    -- un pedido de cliente (BaseType 17; BaseRef es el DocNum del pedido). Si
    -- la factura sale de una entrega (BaseType 15), el pedido queda un paso
    -- atras y aqui sale NULL.
    CASE WHEN X."BaseType" = 17 THEN X."BaseRef" END           AS "Pedido",
    -- VALIDAR CON DATOS REALES: el id del pedido de la app de ventas no tiene
    -- un campo conocido en SAP (posible campo de usuario U_ del pedido ORDR).
    CAST(NULL AS NVARCHAR(100))                                AS "Id pedido app",
    X."NumAtCard"                                              AS "OC cliente",
    -- VALIDAR CON DATOS REALES: la ciudad de envio no esta separada en el
    -- encabezado (Address2 es la direccion completa). Candidata: INV12/RIN12
    -- columna "CityS". Va en NULL hasta confirmarla.
    CAST(NULL AS NVARCHAR(100))                                AS "Ciudad envío",
    X."Address2"                                               AS "Dirección envío",
    T6."FirmName"                                              AS "Marca",
    T5."ItmsGrpNam"                                            AS "Familia",
    X."ItemCode"                                               AS "Código artículo",
    X."Dscription"                                             AS "Descripción",
    IFNULL(X."Quantity", 0) * X."Signo"                        AS "Cantidad",
    -- VALIDAR CON DATOS REALES: costo = StockPrice de la linea (costo de
    -- inventario al contabilizar). Confirmar contra el margen del informe
    -- actual; en lineas de servicio puede venir en 0 o NULL.
    ROUND(X."StockPrice", 2)                                   AS "Costo unitario",
    ROUND(X."StockPrice" * X."Quantity", 2) * X."Signo"        AS "Costo total",
    -- VALIDAR CON DATOS REALES: precio lista = precio antes del descuento de
    -- la linea (PriceBefDi), no el de la lista de precios del articulo (ITM1).
    ROUND(X."PriceBefDi", 2)                                   AS "Precio lista",
    ROUND(X."Price", 2)                                        AS "Precio unitario",
    ROUND(IFNULL(X."LineTotal", 0)
          * (1 - IFNULL(X."DiscPrcnt", 0) / 100), 2) * X."Signo" AS "Total",
    ROUND(X."VatSum", 2) * X."Signo"                           AS "IVA",
    X."DocCur"                                                 AS "Moneda",
    X."DocRate"                                                AS "TRM",
    X."Comments"                                               AS "Comentarios"
FROM (
    -- Facturas y notas debito: signo positivo.
    SELECT
        13 AS "ObjType", 1 AS "Signo",
        T0."DocEntry", T0."DocNum", T0."DocSubType", T0."CANCELED", T0."DocStatus",
        T0."DocDate", T0."TaxDate", T0."SlpCode", T0."CardCode", T0."CardName",
        T0."NumAtCard", T0."Address2", T0."DiscPrcnt", T0."DocCur", T0."DocRate",
        T0."Comments",
        T1."LineNum", T1."ItemCode", T1."Dscription", T1."Quantity", T1."Price",
        T1."PriceBefDi", T1."StockPrice", T1."LineTotal", T1."VatSum",
        T1."BaseType", T1."BaseRef"
    FROM "OINV" T0
    INNER JOIN "INV1" T1 ON T1."DocEntry" = T0."DocEntry"
    WHERE (T0."DocDate" >= ADD_DAYS(CURRENT_DATE, -6 - DAYOFMONTH(CURRENT_DATE))
           AND T0."DocDate" <= CURRENT_DATE)
       OR (T0."TaxDate" >= ADD_DAYS(CURRENT_DATE, -6 - DAYOFMONTH(CURRENT_DATE))
           AND T0."TaxDate" <= CURRENT_DATE)

    UNION ALL

    -- Notas credito: signo negativo.
    SELECT
        14 AS "ObjType", -1 AS "Signo",
        T0."DocEntry", T0."DocNum", T0."DocSubType", T0."CANCELED", T0."DocStatus",
        T0."DocDate", T0."TaxDate", T0."SlpCode", T0."CardCode", T0."CardName",
        T0."NumAtCard", T0."Address2", T0."DiscPrcnt", T0."DocCur", T0."DocRate",
        T0."Comments",
        T1."LineNum", T1."ItemCode", T1."Dscription", T1."Quantity", T1."Price",
        T1."PriceBefDi", T1."StockPrice", T1."LineTotal", T1."VatSum",
        T1."BaseType", T1."BaseRef"
    FROM "ORIN" T0
    INNER JOIN "RIN1" T1 ON T1."DocEntry" = T0."DocEntry"
    WHERE (T0."DocDate" >= ADD_DAYS(CURRENT_DATE, -6 - DAYOFMONTH(CURRENT_DATE))
           AND T0."DocDate" <= CURRENT_DATE)
       OR (T0."TaxDate" >= ADD_DAYS(CURRENT_DATE, -6 - DAYOFMONTH(CURRENT_DATE))
           AND T0."TaxDate" <= CURRENT_DATE)
) X
LEFT JOIN "OCRD" T2 ON T2."CardCode"   = X."CardCode"
LEFT JOIN "OSLP" T3 ON T3."SlpCode"    = X."SlpCode"
LEFT JOIN "OITM" T4 ON T4."ItemCode"   = X."ItemCode"
LEFT JOIN "OITB" T5 ON T5."ItmsGrpCod" = T4."ItmsGrpCod"
LEFT JOIN "OMRC" T6 ON T6."FirmCode"   = T4."FirmCode"
ORDER BY X."DocDate", X."ObjType", X."DocEntry", X."LineNum"
