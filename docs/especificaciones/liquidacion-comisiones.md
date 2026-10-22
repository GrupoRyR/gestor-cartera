# PROMPT — Módulo "Liquidación de Comisiones Comerciales"
**Aplicativo de Gestión de Cartera · SEGUREX LATAM SAS (SGX)**

> Cómo usar este documento: la **Parte A** es el prompt para el equipo de desarrollo o la herramienta de IA que construye el módulo. La **Parte B** es la especificación de los reportes que SAP debe entregar de forma automática. Los **Anexos** contienen la tabla de reglas por código de vendedor y los casos de prueba con cifras reales.

---

# PARTE A — PROMPT DEL MÓDULO

## 1. Rol y objetivo

Actúa como arquitecto y desarrollador senior de software financiero. Construye, dentro del aplicativo de gestión de cartera existente, el módulo **"Liquidación de Comisiones Comerciales"**.

El módulo reemplaza un proceso que hoy se ejecuta manualmente en un libro de Excel de varias pestañas. Cada mes, el coordinador de crédito y cobranza liquida las comisiones de los comerciales por **venta** y por **recaudo**. El módulo debe ejecutar ese cálculo de forma automática, trazable y auditable, a partir de los datos que SAP entrega cada mañana, y debe generar el consolidado de comisiones y un informe PDF de recaudo por cada comercial.

Resultado esperado: el usuario pasa de "pegar datos y arrastrar fórmulas" a "revisar, aprobar y descargar".

## 2. Principios de diseño

1. Todas las tasas, mínimos, tramos, presupuestos y listas de equipos son **parámetros editables con vigencia**. El código no contiene valores fijos de negocio.
2. Cada liquidación es **reproducible**: guarda una instantánea de los datos y de los parámetros usados.
3. Cada cifra del consolidado permite **abrir el detalle** que la originó (drill-down hasta la factura y el pago).
4. Los datos de SAP son de **solo lectura** en el módulo. Los ajustes manuales viven en tablas separadas, con usuario, fecha y soporte.
5. El módulo aprovecha el informe de cartera que ya se extrae de SAP cada mañana. No duplica esa integración.

## 3. Alcance

**Incluye:**
- Carga automática diaria de ventas, recaudos, cruces/compensaciones y maestros desde SAP.
- Cálculo de comisión por venta, por recaudo, de casos especiales y de directores de canal.
- Captura manual de presupuesto, comisión OBR/Escudos, reintegros y descuentos.
- Consolidado final, informes PDF por comercial, exportación a Excel y resumen por persona para nómina.
- Controles de cuadre, bitácora de auditoría, cierre de período y roles de acceso.
- Proyección de comisión sobre la cartera abierta (Fase 2).

**No incluye:** el pago de nómina, la contabilización de la comisión ni el cálculo de prestaciones.

## 4. Conceptos y modelo de datos

Define como mínimo estas entidades (nombres orientativos):

| Entidad | Contenido |
|---|---|
| `Persona` | Persona que cobra la comisión. Una persona puede tener **varios códigos** (ej.: Harold Marín = 32 y 34; Jenny Rocío Buitrago = 41 y 44; Adriana Russi = 42 y 82). |
| `CodigoVendedor` | Código numérico del comercial en SAP, persona asociada, canal, estado y parámetros de comisión con vigencia. |
| `ParametroComision` | Por código y vigencia: tasa de venta, tasa de recaudo, mínimo de cumplimiento, si participa o no del consolidado regular. |
| `TramoRecaudo` | Tabla desde-días / hasta-días / % de la tasa, con vigencia. |
| `Equipo` | Listas de códigos por canal para las comisiones de director, con tasa y vigencia. |
| `Presupuesto` | Período + código + valor. Carga manual mensual. |
| `VentaSAP` | Una fila por línea de factura o nota (staging inmutable). |
| `RecaudoSAP` | Una fila por pago aplicado a factura (staging inmutable). |
| `CruceSAP` / `CruceManual` | Compensaciones cliente-proveedor y anticipos aplicados. |
| `AjusteManual` | OBR/Escudos, reintegros y descuentos, con soporte adjunto. |
| `Liquidacion` | Cabecera por período, con estado, versión, usuario y fecha. |
| `LiquidacionDetalleVenta` / `LiquidacionDetalleRecaudo` | Resultado línea a línea, con los parámetros aplicados congelados. |
| `LiquidacionConsolidado` | Resultado por código y por persona. |
| `BitacoraAuditoria` | Todo cambio de parámetro, carga, ajuste, aprobación y descarga. |

El código del vendedor se deriva del texto de SAP (ej.: `"14 MONICA RIVERA AREVALO"` → código `14`). Valida que el código exista en el maestro y alerta cuando llegue uno nuevo.

## 5. Reglas de negocio (fuente de verdad)

### 5.1 Período
Un período es un mes calendario. Todo valor está en COP, con formato `$ 1.234.567` y fechas `DD/MM/AAAA`.

| Línea | Fecha que la pone en el período |
|---|---|
| **Venta** | La fecha de la factura: `fecha_contabilizacion` (SAP `DocDate`), como confirmó el dueño de SAP. `fecha_documento` (SAP `TaxDate`) puede caer en otro mes y se guarda solo como referencia. |
| **Recaudo** | La fecha de pago (`fecha_pago`). |
| **Cruce** | La fecha de cruce (`fecha_cruce`). |

Los días de un recaudo o de un cruce (§5.3) se cuentan desde la fecha de la factura. Si la línea no trae fecha de factura, se toma la `fecha_contabilizacion` de la factura cargada (`tipo_objeto` 13, mismo `doc_entry`), la misma `DocDate` que el robot envía como `fecha_factura`. Si tampoco está esa factura, se usa la fecha de pago o de cruce (0 días); Controles cuenta esos recaudos en «Recaudos sin fecha de factura».

### 5.2 Comisión por venta (por código)

```
venta        = SUM(total de líneas de venta del código en el período)   # neta de notas crédito y devoluciones
cumplimiento = venta / presupuesto            # 0 si no hay presupuesto
si cumplimiento >= minimo_cumplimiento(código):
    comision_venta = venta * tasa_venta(código)    # se paga sobre la venta TOTAL, no sobre el excedente
sino:
    comision_venta = 0
```

- Mínimo de cumplimiento: **90 %** por defecto; **80 %** para el código 16; **0 %** (sin mínimo, comisiona desde el primer peso) para los códigos 34, 44, 50, 53, 67, 69, 81, 84, 85 y 90.
- Un cumplimiento superior al 110 % es **solo informativo**. No genera bono ni doble comisión.
- Si un código con mínimo mayor que 0 no tiene presupuesto cargado, el cumplimiento es 0, no se paga y el módulo muestra la alerta "Sin presupuesto".
- Tasa base de venta: **0,75 %**. Excepciones en el Anexo 1.

### 5.3 Comisión por recaudo (por línea de pago)

```
dias        = MAX(fecha_pago - fecha_factura, 0)       # se cuenta desde la FECHA DE LA FACTURA
pct_tramo   = 100 % si código == 85, de lo contrario lookup(dias en TramoRecaudo)
comision    = valor_pagado * tasa_recaudo(código) * pct_tramo
```

| Días (fecha pago − fecha factura) | % de la tasa |
|---|---|
| 0 – 65 | 100 % |
| 66 – 80 | 90 % |
| 81 – 95 | 80 % |
| 96 – 125 | 50 % |
| 126 o más | 0 % |

- `valor_pagado` es el valor **sin IVA** (base proporcional al pago). Para los recaudos de SAP, el campo `Vlr Total Pagado` ya viene en esa base.
- Cada pago parcial es una línea independiente con sus propios días. Una misma factura puede tener varios pagos.
- Tasa base de recaudo: **0,75 %**. Excepciones en el Anexo 1.
- **Código 85 (Liliam Herrera):** tasa de recaudo **1,5 %**, **sin tramos de días** (se liquida completa sin importar cuándo pague el cliente) y **sin comisión de venta**.
- Los códigos 50, 53, 81, 84 y 90 no generan comisión de recaudo.
- Los códigos 80 (Oficina–Varios) y 100 (Otros ingresos) **no generan comisión**. Se cargan solo para que el total de ventas del mes cuadre contra SAP.

### 5.4 Cruces de anticipos y compensaciones cliente-proveedor
El reporte de recaudos de SAP **no incluye** los pagos que resultan de cruces de anticipos ni de compensaciones de cartera entre un tercero que es a la vez cliente y proveedor. Esos cruces **sí generan comisión** para el comercial asignado al cliente.

- El módulo importa estos cruces desde SAP (Reporte 4 de la Parte B). Debe permitir además la **captura manual** como respaldo, con los campos: vendedor, tipo de cruce, factura, fecha factura, fecha de cruce, código y razón social del cliente, tercero cruzado, importe original y valor cruzado.
- El módulo asigna el cruce al **vendedor vigente del cliente** según el maestro de clientes (Reporte 5). El usuario puede corregir la asignación con soporte.
- **Vendedor de un cruce.** Se toma, en este orden:
  1. la reasignación aprobada más reciente del cruce (captura con soporte);
  2. el vendedor digitado en un cruce manual;
  3. el vendedor que el cliente tenía asignado en la **fecha del cruce**. Una opción de los parámetros («Vendedor a la fecha de la factura») usa la fecha de la factura en su lugar.

  Si el cliente no tenía vendedor en esa fecha, el cruce no se asigna y sale la alerta «Cliente sin vendedor asignado».
- **Cambio de vendedor del cliente.** Cada maestro de clientes que llega de SAP alimenta el historial `comisiones_asignaciones`, que **nunca se reescribe**:

  | Caso | Qué se guarda |
  |---|---|
  | Cliente visto por primera vez | Su vendedor, vigente desde el 01/01/2000, para cubrir también el pasado. |
  | Mismo vendedor que el vigente | Nada. |
  | Vendedor distinto | Una fila nueva, vigente desde la `fecha_modificacion` de SAP, o desde la fecha de corte de la carga cuando SAP no la trae. |
  | Vendedor distinto con fecha igual o anterior al inicio de la asignación vigente | La fila nueva empieza en la fecha mayor entre el día siguiente a ese inicio y el corte de la carga. Así el vendedor vigente conserva sus cruces hasta el día en que se vio el cambio. Queda en la bitácora como «Cambio de vendedor con fecha ajustada» (`comisiones.assignment.start_moved`), con la fecha de SAP y la aplicada. |

  Cualquier otro reparto se corrige a mano con una reasignación del cruce, con soporte.
- El cálculo es idéntico al de un recaudo: la fecha de cruce actúa como fecha de pago y el valor cruzado, **sin IVA**, como valor pagado.
- Hoy el valor cruzado se digita ya sin IVA (ej.: importe $2.713.450 → valor $2.280.210, es decir, ÷ 1,19). El módulo calcula la base sin IVA a partir del IVA de la factura y no depende de la digitación.
- En informes, cada cruce se marca con **"(cruce)"** junto a la razón social.

### 5.5 Casos especiales

| Concepto | Regla |
|---|---|
| **81.1 — Sergio Caballero (venta)** | 0,1 % sobre las ventas del código 81. Fila propia e independiente en el consolidado. |
| **85.1 — Sergio Caballero (recaudo)** | 1 % sobre el recaudo del código 85 (incluidos sus cruces), tasa plana, sin tramos. Fila propia e independiente. |
| **Código 84 — Director Retail** (Jessika Quintero) | 1 % solo sobre venta, sin mínimo. Se liquida **aparte** del consolidado regular. |
| **Código 90 — Director E-commerce** (Anderson Avendaño) | 2 % solo sobre venta, sin mínimo. Se liquida **aparte**. |
| **Código 81 — Director Cuentas Clave** (Andrea Hincapié) | 0,35 % solo sobre venta, sin presupuesto ni mínimo. Se liquida **aparte**. |
| **Códigos 50 y 53** | 1 % solo sobre venta, sin mínimo. Permanecen en el consolidado regular. |
| **Código 16 y 21** | Tasa de 1,25 % en venta y en recaudo. El código 16 usa mínimo de 80 %. |
| **OBR / Escudos** | Comisión por venta de obsoletos. Aplica aproximadamente cada 6 meses. Captura manual por asesor (descripción, valor vendido, tasa). El total por código se suma al consolidado solo cuando existe. |
| **Reintegros y descuentos** | Captura manual por código y período. Los reintegros suman y los descuentos restan. |

### 5.6 Comisión de directores de canal
Cada director recibe una comisión **adicional** a su comisión individual, calculada sobre el resultado de todo su equipo:

```
comision_director = tasa * (SUM(venta del equipo) + SUM(recaudo del equipo))
```

| Director | Canal | Códigos del equipo | Tasa |
|---|---|---|---|
| Jessika Quintero | Distribución | 14, 15, 16, 17, 18, 23, 32, 34, 41, 43, 51, 53, 67, 69 | 0,2 % |
| Andrea Hincapié | Construcción Residencial | 21, 33, 36, 42, 44, 50, 52, 61, 66, 82, 86 | 1 % |
| Andrea Hincapié | Construcción Institucional | 85 | 1 % |

Los directores se asocian a los códigos 53 (Jessika Quintero) y 50 (Andrea Hincapié). Los equipos y las tasas son parámetros editables con vigencia.

### 5.7 Total a pagar

```
total_codigo  = comision_venta + comision_recaudo + comision_OBR + reintegros - descuentos
total_persona = SUMA de todos los roles de la persona (código regular, director, especiales, 81.1/85.1)
```

El módulo entrega ambos totales. El **total por persona** es el valor que se reporta a nómina ("valor pago nómina").

## 6. Flujo mensual

1. **Diario (automático):** SAP deja los archivos en la carpeta de integración. El módulo los valida, los carga y muestra el acumulado del mes en curso (MTD) por comercial.
2. **Cierre contable:** SAP entrega la extracción definitiva del período.
3. El analista carga o confirma el **presupuesto** del mes, los **ajustes manuales** (OBR, reintegros, descuentos) y revisa las alertas.
4. El analista ejecuta **"Calcular liquidación"**. El sistema genera el borrador, que se puede recalcular las veces necesarias mientras el período esté abierto.
5. Un segundo usuario con rol de aprobador revisa los cuadres y **aprueba**.
6. El sistema **cierra el período**: bloquea datos y parámetros, congela la versión y habilita la generación de los PDF y del archivo para nómina.
7. Las correcciones posteriores se registran como **liquidación complementaria** (nueva versión) con motivo y aprobación. Nunca se sobrescribe una liquidación cerrada.

## 7. Pantallas y funcionalidades

1. **Tablero del período:** estado de las cargas SAP (hora, filas, errores), alertas pendientes, acumulado de venta y recaudo por comercial y avance contra presupuesto.
2. **Parámetros:** tasas, mínimos, tramos, equipos y directores, con vigencia y bitácora. Es la única pantalla donde se cambian reglas.
3. **Presupuesto:** carga por plantilla Excel/CSV o edición en pantalla. Muestra los códigos sin presupuesto.
4. **Ajustes manuales y cruces:** formularios con adjunto de soporte y estado de aprobación.
5. **Liquidación por código:** venta, presupuesto, cumplimiento, comisión de venta, líneas de recaudo con días, tramo y comisión. Filtros por comercial, cliente, factura y rango de fechas.
6. **Consolidado final**, con tres secciones (ver sección 8).
7. **Informes PDF:** generación individual o por lote, descarga en ZIP y envío opcional por correo.
8. **Controles y cuadres:** panel con las validaciones de la sección 9.
9. **Auditoría:** consulta de la bitácora por usuario, fecha y tipo de evento.

## 8. Informes de salida

### 8.1 Consolidado final (pantalla, Excel y PDF)
Encabezado: `INFORME DE COMISIONES — PERIODO CALCULADO DEL 01 AL [último día] DE [MES] DE [AÑO]`.

- **Sección 1 — Equipo comercial regular.** Columnas: Código, Vendedor, Comisión Venta, Comisión Recaudo, Comisión OBR/Escudos, Reintegros, Descuentos, Total a Pagar. Incluye las filas **81.1** y **85.1** (Sergio Caballero) y una fila de total. Excluye los códigos 81, 84 y 90.
- **Sección 2 — Comisiones especiales.** Un bloque por rol (Retail 084, E-commerce 090, Cuentas Clave 081) con Nombre, Venta, Comisión de venta y Total a pagar.
- **Sección 3 — Directores de canal.** Un bloque por director y canal, con el detalle recaudo y venta por código del equipo, el total, la comisión (recaudo y venta) y el **valor pago nómina**.
- **Anexo — Resumen por persona** para nómina.

### 8.2 Informe PDF de recaudo por comercial
- Un PDF por cada código con movimiento en el período. Los códigos sin recaudos no generan PDF.
- Hoja carta **horizontal**. Título: "Informe de Comisión por Recaudo". Subtítulo con **Comercial**, **Período** y fecha de generación.
- Columnas, en este orden: **Vendedor, Código, Factura, Código Cliente, Razón Social, Fecha Factura, Fecha Pago, Importe Original, Días, Valor Pagado, Comisión**.
- Los recaudos normales y los **cruces** aparecen en la misma tabla, ordenados por fecha de pago. Los cruces llevan "(cruce)".
- Fila de **TOTAL** (importe original, valor pagado y comisión) y "Número de transacciones".
- Encabezado de tabla repetido en cada página. Montos como `$ 1.234.567`.
- Nombre de archivo: `Informe_Recaudo_<código>_<NOMBRE>.pdf`.
- **Informe especial 85.1 Sergio Caballero:** mismo formato, con la nota "Comisión especial sobre el recaudo del código 85 (Liliam Herrera) — tasa fija 1,00 %, no aplican tramos de días" y la columna Días con "—".
- El total de cada PDF **debe coincidir exactamente** con la comisión de recaudo del consolidado para ese código. Si no coincide, el sistema bloquea la generación y muestra la diferencia.
- Opcional: informe equivalente de **ventas** por comercial, con cumplimiento de presupuesto.

## 9. Controles y validaciones

1. Total de ventas cargadas = total de ventas por código, **incluidos los códigos 80 y 100**.
2. Total de recaudos cargados = total de recaudos liquidados (normales + cruces). Ninguna fila puede quedar fuera por rangos fijos.
3. La suma de los totales por comercial en los PDF = comisión de recaudo del consolidado.
4. Comparación del total cargado contra el archivo de control de SAP (conteo de filas y suma de valores). Si difiere, la carga queda en estado "Pendiente de revisión".
5. Detección de duplicados por llave natural y reprocesos idempotentes.
6. Alertas: código de vendedor desconocido, cliente sin vendedor asignado, código con mínimo mayor que 0 sin presupuesto, pagos con fecha anterior a la factura, comisión negativa, recaudo anulado en el período y cruces sin asignar.
7. Las alertas bloqueantes impiden aprobar la liquidación hasta que se resuelvan o se justifiquen.

## 10. Seguridad, roles y auditoría

- **Roles:** Administrador de parámetros, Analista de liquidación, Aprobador, Consulta (solo lectura) y Comercial (si más adelante se habilita un portal: ve únicamente su propio informe).
- **Segregación de funciones:** quien modifica parámetros o carga ajustes no aprueba la liquidación. El aprobador es un usuario distinto del analista que calculó.
- **Bitácora inmutable** de cambios de parámetros (valor anterior, valor nuevo, usuario, fecha, motivo), cargas, ajustes, cálculos, aprobaciones, cierres y descargas.
- Los **PDF aprobados** son inmutables: guarda su huella (hash) y la versión de la liquidación que los originó.
- Las comisiones son información confidencial de nómina y datos personales. Aplica control de acceso por rol y cumple la **Ley 1581 de 2012** (protección de datos personales).
- Conserva las liquidaciones cerradas durante el plazo de retención documental definido por la compañía.

## 11. Requisitos no funcionales

- Stack: el mismo del aplicativo de cartera existente. No introduzcas tecnologías nuevas sin necesidad.
- El cálculo de un período completo (≈ 1.100 líneas de venta, ≈ 450 líneas de recaudo, ≈ 30 códigos) se ejecuta en menos de 1 minuto. La generación de todos los PDF, en menos de 2 minutos.
- Interfaz en español. Usuarios con nivel básico de Excel: el flujo se guía paso a paso, con mensajes de error claros y plantillas descargables.
- Código con pruebas automatizadas de las reglas de cálculo (ver Anexo 2).
- Documentación breve de usuario y de administración.

## 12. Fases de implementación

1. **Fase 1 (MVP):** cargas desde SAP, parámetros, presupuesto, cálculo de venta y recaudo, cruces, consolidado regular, casos especiales, directores, PDF por comercial, cuadres y cierre.
2. **Fase 2:** proyección de comisión sobre la cartera abierta (ver 13), resumen por persona para nómina, informe de ventas por comercial y envío de PDF por correo.
3. **Fase 3:** portal de consulta para comercial, comparativo mes a mes y exportación contable.

## 13. Valor agregado con el informe de cartera diario (Fase 2)

Con la cartera abierta que SAP entrega cada mañana, el módulo calcula para cada factura pendiente:
- **Comisión potencial** si se recauda hoy (según los días transcurridos desde la fecha de factura).
- **Días restantes para el próximo cambio de tramo** (65, 80, 95 y 125 días) y el valor de comisión que se pierde al cruzarlo.
- Un tablero de **facturas por entrar a un tramo menor**, ordenado por impacto en comisión, para priorizar la gestión de cobro.
- **Provisión de comisión pendiente** por comercial.

Conserva una **instantánea diaria** de la cartera (no la sobrescribas). Sirve para conciliar los recaudos contra la variación de saldo por factura y para reconstruir el estado de cualquier fecha.

## 14. Puntos por confirmar antes de construir

1. Versión de SAP (Business One, ECC o S/4HANA) y método con el que hoy se extrae el informe de cartera (job programado, consulta, Crystal, API).
2. Si el `Total` de ventas es antes de IVA y cómo se tratan las notas crédito y devoluciones.
3. Si la comisión de directores de canal y el 85.1 deben incluir los cruces (el Excel actual no suma los cruces en 85.1).
4. Tratamiento exacto de las comisiones adicionales de director asociadas a los códigos 50 y 53.
5. ~~Para un cruce: qué vendedor aplica si el cliente cambió de asesor entre la factura y el cruce.~~ **Resuelto (ver §5.4):** el vendedor vigente en la fecha del cruce, según el historial de asignaciones; la opción «Vendedor a la fecha de la factura» de los parámetros cambia esa fecha, y una reasignación aprobada manda sobre ambas.
6. Si el cumplimiento se evalúa por código (como hoy) o por persona cuando una persona tiene varios códigos.
7. Si existen recaudos en moneda extranjera. El campo TRM de SAP hoy llega en 1.

---

# PARTE B — REPORTES AUTOMÁTICOS DE SAP

> Estado actual: el **informe de cartera** ya se extrae automáticamente cada mañana. Los demás reportes se suman a la misma programación.

## B.0 Condiciones generales de entrega

- **Frecuencia:** diaria, entre las 5:30 y las 6:30 a. m. En días de cierre, una extracción adicional **definitiva** del período completo.
- **Formato:** CSV UTF-8 con separador `;` (o XLSX), una fila de encabezado, fechas `AAAA-MM-DD`, decimales con punto y sin separador de miles.
- **Destino:** carpeta compartida o SFTP de integración (o API, si TI lo prefiere).
- **Nombre de archivo:** `SAP_<REPORTE>_<AAAAMMDD>_<HHMM>.csv`.
- **Archivo de control** por cada extracción (`.ctl`): conteo de filas, suma de los campos de valor y fecha-hora de ejecución. El módulo lo usa para validar la carga.
- **Alcance de cada extracción diaria:** todo el mes en curso (acumulado MTD) más los **últimos 7 días del mes anterior**, para capturar movimientos tardíos. La carga es idempotente: actualiza por llave natural y no duplica.
- **Alertas:** si el job falla o llega vacío, SAP/TI notifica por correo y el módulo marca la carga como fallida.
- Todos los reportes incluyen los documentos **anulados o reversados** con su estado, para poder excluirlos o netearlos.

## Reporte 1 — Ventas (facturación detalle)

| Atributo | Detalle |
|---|---|
| Propósito | Base de la comisión por venta y del cumplimiento de presupuesto. |
| Filtros | Sociedad, fecha de documento dentro del período, clases de documento: factura, nota crédito, nota débito y devolución (con signo). |
| Llave natural | Número de documento + posición/línea. Agrega el campo `Posición` si hoy no existe. |
| Campos | `Vendedor` (código y nombre), `Canal`, `Cd Cliente`, `Cliente`, `RUT/NIT`, `Ciudad`, `Pedido`, `ID Pedido App`, `Número de documento`, `Posición`, `Fecha de documento`, `Clase de documento`, `OC Cliente`, `Ciudad envío`, `Dirección envío`, `Marca`, `Familia`, `Cd Artículo`, `Descripción`, `Cantidad`, `Costo Unit`, `Costo Tot`, `Precio Lista`, `Precio Unit`, `Total` (base de comisión), `Moneda`, `TRM`, `Estado del documento`, `Comentarios`. |
| Uso en el módulo | Venta por código; margen informativo; control contra el total de ventas (incluye códigos 80 y 100). |

## Reporte 2 — Recaudos (pagos aplicados a facturas)

| Atributo | Detalle |
|---|---|
| Propósito | Base de la comisión por recaudo. |
| Filtros | Fecha de pago dentro del período; pagos aplicados a facturas de venta; incluir pagos anulados con estado. |
| Llave natural | Número de pago + número de factura + línea. Una misma factura puede tener varios pagos, e incluso dos aplicaciones dentro del mismo pago. |
| Campos | `Nombre Vendedor` (código y nombre), `Número de Pago`, `Fecha Pago`, `Número Factura`, `Fecha Factura`, `Fecha Vencimiento`, `Código Cliente`, `Nombre Cliente`, `Valor Factura` (importe original), `TRM`, `Base Gravable`, `IVA Factura`, `Descuento Factura`, `Valor Aplicado`, `Vlr Total Pagado` (**base de comisión, sin IVA**), `Vlr Comisión Asesor` (control), `Estado Documento`, `Línea`. |
| Uso en el módulo | Cálculo de días, tramo y comisión por línea; PDF por comercial; control contra recaudo total. |

## Reporte 3 — Cartera abierta (ya automatizado)

| Atributo | Detalle |
|---|---|
| Propósito | Conciliación de recaudos y proyección de comisión (Fase 2). |
| Campos mínimos | `Vendedor asignado`, `Código Cliente`, `Cliente`, `NIT`, `Número de factura`, `Fecha factura`, `Fecha vencimiento`, `Valor original`, `Saldo pendiente`, `Días de mora`, `Condición de pago`. |
| Requisito del módulo | Guardar cada extracción diaria como **instantánea con fecha**. Conserva el histórico. |

## Reporte 4 — Cruces de anticipos y compensaciones cliente-proveedor

| Atributo | Detalle |
|---|---|
| Propósito | Recaudos que no aparecen en el Reporte 2 y que generan comisión. |
| Filtros | Documentos de compensación (compensación de partidas) con fecha de compensación dentro del período: anticipos de cliente aplicados a facturas y compensaciones entre cuentas de cliente y de proveedor del mismo tercero. Incluir anulados con estado. |
| Llave natural | Número de documento de compensación + número de factura + línea. |
| Campos | `Número de documento de compensación`, `Fecha de compensación`, `Tipo de cruce` (anticipo / cliente-proveedor / otro), `NIT del tercero`, `Código Cliente`, `Código Proveedor`, `Razón Social`, `Número de factura compensada`, `Fecha Factura`, `Valor Factura`, `Base Gravable`, `IVA Factura`, `Valor Compensado` (con IVA), `Valor Compensado sin IVA`, `Usuario que contabilizó`, `Estado`. |
| Referencia técnica (validar con TI) | Si la compañía usa SAP ECC o S/4HANA: partidas compensadas de clientes (tabla `BSAD`) y de proveedores (tabla `BSAK`), cabecera de documento `BKPF`, vínculo cliente-proveedor por el campo de cliente en el maestro de proveedor (`LFA1-KUNNR`); consulta equivalente a `FBL5N`/`FBL1N` con filtro de partidas compensadas. Si usa Business One, el equivalente son las conciliaciones de pagos recibidos y las asientos de compensación. |
| Uso en el módulo | Alimenta la tabla de cruces. El analista la revisa y la puede complementar con captura manual. |

## Reporte 5 — Maestro de clientes con vendedor asignado

| Atributo | Detalle |
|---|---|
| Propósito | Asignar cada cruce al comercial correcto y validar que ningún cliente quede sin vendedor. |
| Campos | `Código Cliente`, `NIT`, `Razón social`, `Código de vendedor asignado`, `Canal`, `Zona/Ciudad`, `Código Proveedor vinculado` (si el tercero es también proveedor), `Estado`, `Fecha de última modificación`. |
| Frecuencia | Diaria. El módulo guarda el histórico de cambios de asignación con fecha efectiva. |

## Reporte 6 — Maestro de vendedores

| Atributo | Detalle |
|---|---|
| Propósito | Alta, baja y cambios de códigos de vendedor. |
| Campos | `Código`, `Nombre`, `Estado` (activo/inactivo), `Canal`, `Zona`, `Fecha de última modificación`. |
| Frecuencia | Semanal o diaria. |

## Dato que no viene de SAP

- **Presupuesto mensual por código:** hoy no existe un archivo maestro. Se carga manualmente cada mes con la plantilla: `Período; Código de vendedor; Presupuesto; Canal`. Si más adelante existe una fuente sistematizada, el módulo debe ofrecer un conector para reemplazar la carga manual.

---

# ANEXO 1 — Reglas por código de vendedor (valores iniciales de parámetros)

| Código | Tasa venta | Tasa recaudo | Mín. cumplimiento | Consolidado | Observación |
|---|---|---|---|---|---|
| 14, 15, 17, 18, 23, 32, 33, 36, 41, 42, 43, 51, 52, 61, 66, 82, 86 | 0,75 % | 0,75 % | 90 % | Regular | Regla general. |
| 16 | 1,25 % | 1,25 % | 80 % | Regular | Tasa especial. |
| 21 | 1,25 % | 1,25 % | 90 % | Regular | Tasa especial. |
| 34, 44, 69 | 0,75 % | 0,75 % | 0 % | Regular | Sin mínimo. |
| 67 | 0,75 % | 0,75 % | 0 % | Regular | Vacante / freelance. Puede no tener datos en algunos meses. |
| 50, 53 | 1 % | 0 % | 0 % | Regular | Solo venta, sin presupuesto. |
| 85 | 0 % | 1,5 % | 0 % | Regular | Solo recaudo, **sin tramos de días**. |
| 81 | 0,35 % | 0 % | 0 % | Aparte | Cuentas Clave, solo venta. |
| 84 | 1 % | 0 % | 0 % | Aparte | Retail, solo venta. |
| 90 | 2 % | 0 % | 0 % | Aparte | E-commerce, solo venta. |
| 80, 100 | 0 % | 0 % | — | Control | Sin comisión. Solo para cuadrar el total de ventas. |
| 81.1 | 0,1 % sobre ventas del 81 | — | — | Regular | Sergio Caballero (venta). |
| 85.1 | — | 1 % sobre recaudo del 85 | — | Regular | Sergio Caballero (recaudo), sin tramos. |

# ANEXO 2 — Casos de prueba con cifras reales (criterios de aceptación)

**Venta (julio 2026)**

| Código | Venta | Presupuesto | Cumplimiento | Comisión esperada |
|---|---|---|---|---|
| 14 Mónica Rivera | $69.915.134 | $77.241.857 | 90,5 % | $524.364 (0,75 %) |
| 16 Carolina Ramírez | $478.187.568 | $442.043.556 | 108,2 % | $5.977.345 (1,25 %, sin bono por superar 100 %) |
| 15 Paola Vargas | $78.565.924 | $370.321.392 | 21,2 % | $0 (menor al mínimo) |

**Recaudo (agosto 2026, código 14, factura 8225 y 9098)**

| Factura | Fecha factura | Fecha pago | Días | Valor pagado | % tramo | Comisión esperada |
|---|---|---|---|---|---|---|
| 8225 | 28/04/2026 | 05/08/2026 | 99 | $2.100.840 | 50 % | $7.878 |
| 9098 | 30/06/2026 | 11/08/2026 | 42 | $10.560.866 | 100 % | $79.206 |

Total del código 14 en agosto: 10 transacciones, valor pagado **$44.893.026**, comisión **$314.964**.

**Cruce (septiembre 2026, código 41)**
Importe original $2.713.450 → base sin IVA $2.280.210 → 28 días → 100 % → comisión **$17.102**. Aparece en el PDF del código 41 con "(cruce)" y suma a su comisión de recaudo de **$833.014**.

**Código 85 y 85.1 (agosto 2026)**
Cuatro pagos con valor pagado total de $5.548.738. Sin tramos de días. Comisión 85.1 = 1 % = **$55.487**.

**Directores (julio 2026)**
- Jessika Quintero, Distribución: recaudo $1.546.524.797 × 0,2 % = $3.093.050 y venta $1.378.490.620 × 0,2 % = $2.756.981.
- Andrea Hincapié, Construcción Residencial: recaudo $185.904.516 × 1 % = $1.859.045 y venta $395.019.688 × 1 % = $3.950.197.

**Pruebas de borde obligatorias**
- Pago con 65, 66, 80, 81, 95, 96, 125 y 126 días.
- Pago con fecha anterior a la factura (días = 0).
- Código con mínimo mayor que 0 y sin presupuesto.
- Código desconocido en SAP.
- Dos pagos parciales de la misma factura.
- Pago anulado dentro del período.
- Cliente sin vendedor asignado en un cruce.
- Código 85 con pago a más de 125 días (debe pagar 100 % de su tasa).
- Recálculo de un período abierto y registro de liquidación complementaria sobre un período cerrado.
