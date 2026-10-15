-- Asesores comerciales activos con cartera asignada.
-- Alimenta la tabla de usuarios de la web (login por correo + contraseña).
SELECT
    T3."SlpCode"                        AS "SlpCode",
    T3."SlpName"                        AS "Nombre",
    LOWER(IFNULL(T3."Email", ''))       AS "Email",
    IFNULL(T3."Active", 'Y')            AS "Activo",
    COUNT(DISTINCT T1."CardCode")       AS "Clientes"
FROM "OSLP" T3
LEFT JOIN "OCRD" T1
       ON T1."SlpCode" = T3."SlpCode"
      AND T1."CardType" = 'C'
WHERE T3."SlpCode" >= 0
GROUP BY T3."SlpCode", T3."SlpName", T3."Email", T3."Active"
ORDER BY T3."SlpName"
