-- Recaudos SEGUREX (SAP Business One sobre HANA)
-- Lote "recaudos" del gestor de cartera (Comisiones, Parte B reporte 2): base
-- de la comision por recaudo.
--
-- Una fila por aplicacion de un pago recibido (ORCT) a una factura de
-- clientes (RCT2 con InvType '13'; las notas debito tambien son OINV). Un pago
-- puede aplicar varias facturas, y una factura puede tener varios pagos.
-- Se incluyen los pagos anulados (ORCT."Canceled" = 'Y') con su estado.
--
-- Cuidado con los nombres de SAP: RCT2."DocNum" es el DocEntry del pago
-- (ORCT), RCT2."DocEntry" es el DocEntry de la factura aplicada y
-- RCT2."InvoiceId" es la linea dentro del pago. RCT2."InvType" es texto en
-- B1: se compara como '13' y se entrega convertido a entero.
--
-- Llave natural: "DocEntry pago" + "Línea".
-- Ventana: fecha del pago (ORCT."DocDate") desde el primer dia del mes en curso
-- menos 7 dias hasta hoy; la aritmetica esta explicada en ventas.sql.
--
-- Montos en moneda local (COP). "Valor aplicado" es RCT2."SumApplied" (con
-- IVA). "Valor pagado sin IVA" (base de comision) = valor aplicado por la
-- proporcion sin IVA de la factura (1 - VatSum / DocTotal), igual que la base
-- gravable de cartera.sql; si la factura no tiene total, el valor aplicado.
--
-- VALIDAR CON DATOS REALES: solo se toman aplicaciones a facturas (InvType 13).
--   Quedan fuera las aplicaciones a facturas de anticipo (203), asientos (30)
--   y notas credito (14); confirmar que el informe actual tampoco las cuenta.
--
-- Valores de "Estado pago": anulado | vigente
SELECT
    T0."DocEntry"                                               AS "DocEntry pago",
    T0."DocNum"                                                 AS "Número pago",
    T1."InvoiceId"                                              AS "Línea",
    T0."DocDate"                                                AS "Fecha pago",
    CASE WHEN T0."Canceled" = 'Y' THEN 'anulado' ELSE 'vigente' END AS "Estado pago",
    CAST(T1."InvType" AS INTEGER)                               AS "Tipo documento",
    T1."DocEntry"                                               AS "DocEntry factura",
    T2."DocNum"                                                 AS "Número factura",
    -- VALIDAR CON DATOS REALES: los dias de la comision se cuentan desde la
    -- "fecha de la factura". Se usa DocDate (contabilizacion); confirmar que no
    -- sea TaxDate (fecha del documento) y que coincida con ventas.sql.
    T2."DocDate"                                                AS "Fecha factura",
    T2."DocDueDate"                                             AS "Fecha vencimiento",
    IFNULL(T2."CardCode", T0."CardCode")                        AS "Código cliente",
    IFNULL(T2."CardName", T0."CardName")                        AS "Nombre cliente",
    -- VALIDAR CON DATOS REALES: el vendedor es el de la factura (OINV."SlpCode"),
    -- no el asignado hoy al cliente; confirmar que asi se liquida el recaudo.
    IFNULL(T2."SlpCode", -1)                                    AS "SlpCode",
    T3."SlpName"                                                AS "Vendedor",
    T2."DocTotal"                                               AS "Valor factura",
    T2."DocTotal" - T2."VatSum"                                 AS "Base gravable",
    T2."VatSum"                                                 AS "IVA factura",
    T2."DiscSum"                                                AS "Descuento factura",
    T2."DocCur"                                                 AS "Moneda",
    T2."DocRate"                                                AS "TRM",
    IFNULL(T1."SumApplied", 0)                                  AS "Valor aplicado",
    CASE WHEN IFNULL(T2."DocTotal", 0) <> 0
         THEN ROUND(IFNULL(T1."SumApplied", 0)
                    * (1 - T2."VatSum" / T2."DocTotal"), 2)
         ELSE IFNULL(T1."SumApplied", 0)
    END                                                         AS "Valor pagado sin IVA",
    -- VALIDAR CON DATOS REALES: el informe trae una comision calculada por SAP
    -- como control ("Vlr Comisión Asesor"), pero no se conoce su origen en B1
    -- (OSLP."Commission" es un porcentaje, no un valor). Va en NULL.
    CAST(NULL AS DECIMAL(19,2))                                 AS "Comisión SAP"
FROM "ORCT" T0
INNER JOIN "RCT2" T1 ON T1."DocNum"   = T0."DocEntry"
LEFT  JOIN "OINV" T2 ON T2."DocEntry" = T1."DocEntry"
LEFT  JOIN "OSLP" T3 ON T3."SlpCode"  = T2."SlpCode"
WHERE T1."InvType" = '13'
  AND T0."DocDate" >= ADD_DAYS(CURRENT_DATE, -6 - DAYOFMONTH(CURRENT_DATE))
  AND T0."DocDate" <= CURRENT_DATE
ORDER BY T0."DocDate", T0."DocEntry", T1."InvoiceId"
