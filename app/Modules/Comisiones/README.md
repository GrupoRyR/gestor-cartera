# Comisiones

Liquidación mensual de comisiones de Segurex (SGX): el analista calcula con las ventas, recaudos y cruces de SAP, el presupuesto y las capturas manuales; el aprobador revisa y aprueba; se cierra el período y salen el consolidado, los PDF de recaudo y el archivo de nómina. Todo queda en la bitácora del módulo (`/comisiones/auditoria`).

## Dónde está cada cosa

| Qué | Dónde |
|---|---|
| Reglas de negocio (fuente de verdad) | [docs/especificaciones/liquidacion-comisiones.md](../../../docs/especificaciones/liquidacion-comisiones.md) |
| Motor de cálculo, puertos y casos de uso | [Application/README.md](Application/README.md) (en inglés) |
| Configuración | `config/comisiones.php`, leída como `config('comisiones.*')` |

## Al desplegar

Dale a Tatiana el rol que aprueba. La pantalla Equipo de Core todavía no asigna los roles del módulo, así que se hace por consola en el servidor:

```bash
php artisan comisiones:rol tatiana.munoz@segurex.com comisiones_aprobador
```

1. **Antes**, el usuario debe existir y estar activo. Se crea solo la primera vez que entra con su cuenta de Microsoft (Entra ID), sin rol; también lo puede registrar un administrador en `/equipo/nuevo`. Si no existe o está desactivado, el comando responde «No hay un usuario activo con el correo …» y no cambia nada.
2. Corre el comando. Responde «Asignaste el rol comisiones_aprobador a tatiana.munoz@segurex.com.» y deja `comisiones.role.assigned` en la bitácora.
3. Pídele que entre por `/comisiones`. Con solo un rol del módulo, la página de inicio (`/`) la manda a «sin acceso», porque `core.access` solo lo tienen los roles de Core.

| Para | Comando |
|---|---|
| Quitar el rol | `php artisan comisiones:rol tatiana.munoz@segurex.com comisiones_aprobador --quitar` (bitácora: `comisiones.role.removed`) |
| Dar acceso de solo consulta | `php artisan comisiones:rol <correo> comisiones_consulta` |

El comando solo acepta `comisiones_aprobador` y `comisiones_consulta`; los roles de Core (por ejemplo `gerente_cartera`) se asignan en Equipo.

## Quién hace qué

Permisos de `ComisionesPermissionSeeder`. Quien calcula o captura nunca aprueba (§10).

| Permiso | Qué permite | Roles |
|---|---|---|
| `comisiones.view` | Tablero, liquidaciones, consolidado y controles | `gerente_cartera`, `comisiones_aprobador`, `comisiones_consulta` |
| `comisiones.calculate` | Calcular, recalcular y registrar complementarias | `gerente_cartera` |
| `comisiones.capture` | Presupuesto, OBR, reintegros y descuentos, cruces manuales y reasignaciones | `gerente_cartera` |
| `comisiones.approve` | Aprobar liquidaciones y capturas | `comisiones_aprobador` |
| `comisiones.close` | Cerrar el período | `gerente_cartera`, `comisiones_aprobador` |
| `comisiones.parameters` | Agregar versiones de parámetros | `admin`, `gerente_cartera` |
| `comisiones.reports` | PDF de recaudo, ZIP y archivo de nómina | `gerente_cartera`, `comisiones_aprobador` |
| `comisiones.audit` | La bitácora | `admin`, `gerente_cartera`, `comisiones_aprobador` |

Además de los permisos, `ApprovalGuard` no deja aprobar a quien cargó capturas o cambió parámetros del período, y el motor no deja aprobar a quien calculó la versión.

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
