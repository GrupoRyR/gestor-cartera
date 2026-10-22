# Comisiones

Liquidación mensual de comisiones de Segurex (SGX): el analista calcula con las ventas, recaudos y cruces de SAP, el presupuesto y las capturas manuales; el aprobador revisa y aprueba; se cierra el período y salen el consolidado, los PDF de recaudo y el archivo de nómina. Todo queda en la bitácora del módulo (`/comisiones/auditoria`).

## Dónde está cada cosa

| Qué | Dónde |
|---|---|
| Reglas de negocio (fuente de verdad) | [docs/especificaciones/liquidacion-comisiones.md](../../../docs/especificaciones/liquidacion-comisiones.md) |
| Motor de cálculo, puertos y casos de uso | [Application/README.md](Application/README.md) (en inglés) |
| Configuración | `config/comisiones.php`, leída como `config('comisiones.*')` |

## Correo al aprobar

Cuando el aprobador aprueba una liquidación (`POST /comisiones/periodos/{periodo}/aprobar`), sale un correo con el consolidado de la versión aprobada.

| Qué | Cómo |
|---|---|
| Cuándo | Solo al aprobar. Ni al calcular, ni al cerrar, ni cuando la aprobación se rechaza. |
| Desde | El buzón de Segurex (`COBRO_MAILBOX_SGX`), por el contrato `MailSender` que implementa Cobro. |
| Para | `config('comisiones.approval_mail.recipients')`: hoy Marcela Sanabria y Mónica Pinzón. Para cambiarlos, edita esa lista en `config/comisiones.php`. |
| Asunto | «Liquidación de comisiones aprobada – julio de 2026». |
| Cuerpo | Período, versión y revisión, quién aprobó y cuándo, total del equipo regular y total para nómina, y un enlace a la liquidación. |
| Adjunto | El mismo PDF que descarga `GET …/consolidado.pdf`, por ejemplo `Consolidado_Comisiones_2026-07_v1.pdf`. Lo arma `Infrastructure/Report/ConsolidatedPdf`. |
| Si falla | La aprobación **no** se deshace. El aprobador ve un aviso («La liquidación quedó aprobada, pero no pudimos enviar el consolidado por correo…») y debe descargar el consolidado y compartirlo. El fallo queda en el registro y en la bitácora como `comisiones.settlement.mail_failed`, con el motivo en `fallo`: `sin_destinatarios`, `sin_mail_sender`, `no_enviado` (Graph lo rechazó) o `error` (falló el PDF o la vista; queda reportado). |

Un envío correcto queda en la bitácora como `comisiones.settlement.mailed`, con los destinatarios.
