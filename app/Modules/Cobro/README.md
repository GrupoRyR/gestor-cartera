# Cobro

Correo de cobro por Microsoft Graph desde el buzón de cada empresa: cobro semanal de facturas vencidas (4.1), motor de la circularización mensual (4.2, formato provisional), lista de exclusión, plantilla editable, informe de entrega y bandeja de respuestas. Implementa los contratos `MailSender` y `MailboxReader` (`docs/arquitectura.md` §6.10 y §6.11).

## Pantallas

| Ruta | Nombre | Permiso | Qué hace |
|---|---|---|---|
| `GET /cobro/semanal` | `cobro.semanal.show` | `cobro.view` | Vista previa del próximo envío: quién lo recibe, quién no tiene correo, quién está excluido y cuándo sale. |
| `GET /cobro/semanal/{empresa}/correo/{cliente}` | `cobro.semanal.correo` | `cobro.view` | El correo de un cliente tal como saldría. |
| `POST /cobro/semanal/{empresa}/prueba` | `cobro.semanal.prueba` | `cobro.manage` | Envía el correo de un cliente, marcado como prueba, solo al usuario. |
| `GET /cobro/circularizacion` (y las mismas subrutas) | `cobro.circularizacion.*` | igual | Lo mismo para la circularización. |
| `GET /cobro/respuestas` | `cobro.respuestas.index` | `cobro.replies` | Respuestas y rebotes, pendientes o leídos; se marcan con casillas. |
| `GET /cobro/lotes` | `cobro.lotes.index` | `cobro.view` | Informes de envío: enviados, rebotados, sin correo, excluidos y no enviados. |
| `GET /cobro/lotes/{run}` | `cobro.lotes.show` | `cobro.view` | Un envío cliente por cliente, con el motivo del rebote leído del buzón. |
| `GET /cobro/exclusiones` | `cobro.exclusiones.index` | `cobro.manage` | Lista de exclusión con casillas, motivo y fecha de vencimiento opcional. |
| `GET /cobro/plantillas` | `cobro.plantillas.edit` | `cobro.manage` | Asunto y textos del cobro semanal por empresa, con datos como `{cliente}`. |

Permisos (`CobroPermissionSeeder`): `cobro.view` para administrador, gerente y analista de cartera; `cobro.manage` y `cobro.replies` para gerente y analista.

## Tareas programadas y colas

| Comando | Cuándo (hora de Colombia) | Qué hace |
|---|---|---|
| `cobro:sincronizar-buzones` | Cada 5 minutos | Lee con una consulta delta de Graph los correos nuevos de la bandeja de entrada de cada buzón, los une al envío y al cliente, y registra la respuesta en la bitácora. Sin credenciales de Graph solo avisa. |
| `cobro:cobro-semanal` | Miércoles 17:00 (`cobro.weekly`) | Prepara el envío y pone en cola un trabajo `SendRunItem` por cliente. Correrlo de nuevo el mismo día no envía nada. |
| `cobro:circularizacion` | Día 1 a las 09:00 (`cobro.circularizacion`) | Igual, una vez al mes. Mientras el formato sea borrador no envía nada; `--aun-en-borrador` permite probar el motor. |
| `cobro:cerrar-envios-interrumpidos` | Cada 10 minutos | Marca «No se pudo enviar» a los clientes que un proceso interrumpido dejó «enviando» más de 30 minutos, para que el envío se cierre y el informe lo muestre. |

Cada trabajo reclama su cliente antes de enviar (pendiente → enviando), así que un reintento nunca le manda dos correos al mismo cliente. Los trabajos salen escalonados a `cobro.send_per_minute` correos por minuto (25 por defecto; Exchange Online acepta 30 por minuto por buzón) y el limitador `cobro-mail` queda de respaldo: 300 clientes tardan unos 12 minutos en salir. El informe muestra en español por qué no salió un correo; el detalle técnico queda en el registro.

## Cómo se unen las respuestas

`GraphMailSender` crea un borrador en el buzón de la empresa y lo envía, porque `sendMail` no devuelve identificadores. Así guarda el `internetMessageId` y el `conversationId` de cada envío en `cobro_sent_messages`. La sincronización une cada mensaje recibido, en este orden:

1. por la misma conversación de Graph;
2. si es un rebote, por el `Message-ID` del envío que cita en su texto;
3. si es una respuesta, por el remitente, al último envío a esa dirección en los últimos 90 días.

Un rebote unido marca su cliente como «Rebotado» en el informe, con el código SMTP y la dirección que falló. Si el informe nombra varias direcciones del envío, cuenta primero la del cliente (Para) y después las copias. Si la dirección que falló es una copia (CC), el cliente sí recibió el correo: queda «Enviado» y la dirección queda en el registro.

## Copias del cobro semanal

Cada correo del cobro semanal lleva en copia (CC), en este orden y sin repetir direcciones ni repetir las del cliente:

1. los correos fijos de la empresa;
2. el asesor del cliente (su correo en SAP, `OSLP.Email`), si `advisor` es `true` y SAP lo tiene activo;
3. los gerentes del grupo de SAP del cliente (`OCRG.GroupName`, por ejemplo `DISTRIBUCION` o `CONS. RESIDENCIAL`). El grupo se compara sin importar mayúsculas, tildes ni espacios; un cliente sin grupo no copia a ningún gerente.

Por ahora se definen en `config('cobro.copias')`, una entrada por empresa:

| Empresa | Fijas | Asesor | Gerentes por grupo |
|---|---|---|---|
| Segurex | Miguel Martínez | Sí | `DISTRIBUCION`: Jessika Quintero; `CONS. RESIDENCIAL`: Andrea Hincapié |
| Derivados | Miguel Martínez, Diana Mora y Servicio al Cliente | No (la única asesora, Diana Mora, ya va fija) | Ninguno |

La vista previa del envío muestra las copias de cada cliente; la prueba que se envía el usuario y la circularización no llevan copias.

## Microsoft Graph: lo que TI debe configurar

Variables de `.env` (`docs/arquitectura.md` §15): `GRAPH_TENANT_ID`, `GRAPH_CLIENT_ID`, `GRAPH_CLIENT_SECRET`, `COBRO_MAILBOX_SGX` y `COBRO_MAILBOX_SDC`.

1. Registrar en Entra ID una aplicación aparte de la de ingreso, de un solo inquilino, con un secreto de cliente. **No** agregarle permisos de Microsoft Graph ni dar consentimiento de administrador: un permiso de Entra ID abre todos los buzones del inquilino, y los de Exchange se suman a él en vez de limitarlo.
2. En Exchange Online PowerShell (rol Organization Management), crear el apuntador a la aplicación con los ids de **Aplicaciones empresariales** (no los de Registros de aplicaciones):

   ```powershell
   New-ServicePrincipal -AppId <id de la aplicación> -ObjectId <id de objeto del service principal> -DisplayName "Gestor de cartera - correo"
   ```

3. Limitar el alcance a los dos buzones, por ejemplo con un grupo de seguridad habilitado para correo que tenga solo a esos dos buzones como miembros directos:

   ```powershell
   New-ManagementScope -Name "Gestor de cartera - buzones" -RecipientRestrictionFilter "MemberOfGroup -eq '<nombre distintivo del grupo>'"
   ```

4. Asignar los dos roles de aplicación con ese alcance:

   ```powershell
   New-ManagementRoleAssignment -App <id de objeto del service principal> -Role "Application Mail.Send" -CustomResourceScope "Gestor de cartera - buzones"
   New-ManagementRoleAssignment -App <id de objeto del service principal> -Role "Application Mail.ReadWrite" -CustomResourceScope "Gestor de cartera - buzones"
   ```

5. Comprobar que solo alcanza esos buzones (`InScope` debe ser `True` para los dos y `False` para cualquier otro):

   ```powershell
   Test-ServicePrincipalAuthorization -Identity <id de la aplicación> -Resource segurex.info@<dominio>
   ```

`Application Mail.Send` permite enviar y `Application Mail.ReadWrite` crear el borrador (de ahí salen los identificadores), borrarlo si el envío falla y leer la bandeja de entrada. Los cambios de permisos tardan entre 30 minutos y 2 horas en aplicarse.

## Circularización (4.2)

`Circularizacion/CircularizationRun.php` es el motor: programación mensual, lote, envío e informe, con la misma lista de exclusión y la misma bandeja de respuestas. La plantilla `resources/views/circularizacion/borrador.blade.php` es **provisional** y se marca «Borrador» en el asunto y en el cuerpo. Cuando llegue el informe que hoy reciben los clientes, la sesión «Circularización» reemplaza esa vista y pone `cobro.circularizacion.draft` en `false`.

## Pruebas

Ninguna prueba envía correo real ni llama a Graph: los flujos usan `FakeMailSender` (`ContractFakes`) y la integración con Graph usa respuestas grabadas en `tests/Modules/Cobro/Fixtures/graph` a través de `tests/Modules/Cobro/Support/GraphFake.php`.
