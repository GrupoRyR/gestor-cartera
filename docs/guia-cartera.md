# Guía para el equipo de cartera

Lo que cambió en las pantallas de cartera desde noviembre de 2026. Es para la gerencia y el equipo de cartera, y para los asesores en lo que ven de sus clientes. No necesitas saber de programación.

## Mi cartera y el filtro «Gestor»

En **Cartera › Clientes** hay un filtro **Gestor** con tres opciones:

| Opción | Qué muestra |
|---|---|
| **Toda la cartera** | Todos los clientes de la empresa que estás viendo. |
| **Mi cartera** | Solo los clientes en los que tú eres el gestor. |
| **Sin asignar** | Los clientes que todavía no tienen gestor. |

- Es un filtro, no una restricción: todo el equipo sigue viendo todos los clientes.
- La lista se abre en **Mi cartera** para la analista de cartera y en **Toda la cartera** para el resto. Si eliges otra opción, la pantalla respeta lo que elegiste.
- **Quitar filtros** te lleva a «Toda la cartera».
- La exportación a Excel de la lista aplica el mismo filtro.

## Asignar gestor

Pueden hacerlo la gerencia de cartera, la analista y los administradores. Quien no tiene el permiso no ve los formularios.

**A varios clientes, desde la lista**

1. Filtra la lista como necesites.
2. Marca los clientes en la columna **Elegir**, o usa **Elegir los clientes de esta página**. También puedes marcar **Aplicar a los N clientes filtrados**.
3. En **Nuevo gestor**, elige una persona (o **Sin asignar** para quitar el gestor) y pulsa **Asignar gestor**.

Ten en cuenta:

- Elige filas **o** «los clientes filtrados», no las dos cosas.
- Con «los clientes filtrados», la pantalla te pide confirmar el número de clientes antes de seguir.
- El máximo es de 1.000 clientes por vez. Si hay más, filtra un poco más.
- Solo aparecen como opciones las personas con rol de gerencia o analista de cartera. Si una persona no sale, pide a un administrador que revise su rol y su empresa en **Equipo**.
- Si eliges a alguien que no trabaja en la empresa de alguno de los clientes, la pantalla no cambia nada y te lo explica. Elige otra persona o filtra por empresa.

**A un solo cliente, desde su ficha**

En la tarjeta de datos, junto a **Gestor responsable**, elige la persona en **Cambiar el gestor** y pulsa **Guardar**. Si no cambias nada, verás «El cliente ya tenía ese gestor.».

Cada cambio queda en la gestión del cliente («Gestor asignado», «Gestor cambiado» o «Gestor quitado») y en la auditoría.

**Asignar no es gestionar.** Asignar un gestor no cuenta como una gestión: un cliente vencido que nadie ha contactado sigue en «vencidos sin gestión» hasta que una persona registre una llamada o una nota.

## Datos actualizados

Bajo los filtros del resumen, la lista de clientes, el histórico y el equipo de Asesores, y en el tablero de Cartera, verás dos datos distintos:

- **Cartera al corte del 15/10/2026.** Es la fecha hasta la que llega la cartera.
- **Datos actualizados hoy a las 07:10.** (o «el 11/11/2026 a las 07:10») Es cuándo llegó la última carga desde SAP, en hora de Bogotá.

En la vista de Grupo manda la empresa con el corte más viejo. Si no hay ninguna carga, no se muestra la hora. Si el último corte tiene varios días, un aviso te lo dice; en ese caso, si las cifras no te cuadran, avisa al área de cartera.

## Valores antes de IVA

En la ficha del cliente de Asesores, la tabla de documentos tiene **Valor antes de IVA** y **Saldo antes de IVA**, y hay una ficha de resumen **Saldo antes de IVA**. El estado de cuenta en PDF y la hoja «Facturas» del Excel traen las mismas columnas.

- Si a una factura le falta el dato (cargas anteriores o facturas subidas a mano), se ve «—», nunca 0.
- La ficha de resumen también muestra «—» si falta el dato en algún documento abierto, para no mostrar una suma parcial.

## «En gestión» ya no existe

Ese estado se retiró. Los clientes que lo tenían pasaron a su estado automático según la última carga: **Vencida**, **Por vencer**, **Al día** o, si no tienen saldo, **Pago recibido**. Cada cambio quedó en la gestión del cliente.

Los estados que puedes elegir son siete: **Al día**, **Por vencer**, **Vencida**, **Compromiso**, **Incumplido**, **Pago recibido** y **Jurídico**. El Kanban tiene una columna por cada uno. **Al día** y **Pago recibido** arrancan ocultas; muéstralas con **Mostrar «Al día» y «Pago recibido»**.

## Kanban: desplazarte entre columnas

Si el tablero no cabe en la pantalla, aparecen dos botones encima: **Columnas anteriores** y **Columnas siguientes**. Cada uno desplaza el tablero de a una vista, y se desactiva al llegar al final. También puedes arrastrar los clientes entre columnas o usar el botón **Mover**, que sirve con teclado y en el celular.
