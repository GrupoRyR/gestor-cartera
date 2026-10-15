# Órdenes para los agentes

Cada bloque se pega tal cual en una **sesión nueva con worktree** de la app de escritorio:

1. Toca **+ Nueva sesión** sobre la carpeta del repositorio.
2. Marca la opción **worktree** junto al nombre de la rama.
3. Elige el modelo en el selector junto al botón de enviar, y el esfuerzo con **Ctrl+Shift+E**.
4. Pega la orden.

**Reglas para ti:**

- **No abras una fase hasta que la anterior esté fusionada en `main` en GitHub.** Los worktrees nuevos parten de `main` remoto; si no está ahí, el agente no lo ve.
- **Cuando el agente abra su PR, dile «fusiona tu PR».** Siguiendo `CLAUDE.md`, el agente:
  1. corre `code-review` y corrige lo que encuentre
  2. se sincroniza con `main` y vuelve a correr las pruebas
  3. espera el CI en verde
  4. ejecuta `gh pr merge`

  La app te pide aprobar la fusión con un clic. Si algo no está bien, no apruebes y díselo.
- **Después de cada fusión, actualiza tu `main` local** con `git -C <carpeta> pull --ff-only origin main` antes de abrir sesiones nuevas.
- Las reglas comunes (idioma, commits, secretos, archivos permitidos) están en `CLAUDE.md`, que cada sesión lee sola. Las órdenes solo dicen lo propio de cada agente.

---

## Fase 0a — Cimientos

**Modelo:** Opus 5.5 · **Esfuerzo:** xhigh · **Abrir:** primero, sola.

```text
Eres el agente «Cimientos» (Fase 0a) del gestor de cartera.

1. Lee completo docs/prompt-gestor-cartera.md. Tu trabajo es la sección C.3 «Fase 0a» y lo que piden C.2, C.4 y C.5.
2. Carga antes las skills ryr-design (solo para entender los tokens; el diseño final lo hace otro agente), cpanel-deploy y work-unit-commits.
3. Crea el proyecto Laravel 13 en la raíz del repositorio con PHP 8.3. composer create-project exige una carpeta vacía: créalo en una carpeta temporal y mueve el contenido sin pisar etl/, legacy/, docs/, scripts/, CLAUDE.md ni README.md. Combina el .gitignore de Laravel con el existente.
4. Deja listos: dependencias de A.3, .env.example completo, cargador de módulos, NavRegistry, registro de programación y seeders, contratos de Core/Contracts con sus fakes en tests/Fakes, stubs de los componentes C.5 con sus props definitivas, esqueleto vacío de cada módulo, Pest + Pint + Larastan, scripts composer lint y composer test, CI de GitHub Actions con PHP 8.3 y MySQL, ruta /dev/componentes solo en local, deploy/deploy.sh y docs/arquitectura.md con las props de cada componente y las reglas de C.2.
5. No construyas lógica de negocio ni diseño final: eso es de las sesiones de la Fase 0b.
6. No incluyas public/build en los commits.

Al terminar: corre composer lint y composer test, sube tu rama, abre el PR contra main con el título «feat(base): cimientos del gestor de cartera» y dame el enlace junto con la lista de lo que quedó pendiente.
```

---

## Fase 0b — Diseño

**Modelo:** Opus 5.5 · **Esfuerzo:** high · **Abrir:** cuando la Fase 0a esté en `main`, junto con «Núcleo».

```text
Eres el agente «Diseño» (Fase 0b) del gestor de cartera.

1. Lee docs/prompt-gestor-cartera.md (A.6, Módulo 0D, C.2 y C.5) y docs/arquitectura.md.
2. Carga antes las skills ryr-design, frontend-design y dataviz.
3. Solo puedes escribir en los archivos de tu fila de la tabla «Fase 0b» (resources/css, resources/js, layouts, componentes, app/View/Components, public/fonts, public/icons, manifiesto, sw.js, offline.html y tests/Feature/Design). Fuera de eso, detente y pregúntame.
4. Implementa los componentes del contrato C.5 sin cambiar sus props. Si una prop es imposible de cumplir, detente y dime por qué.
5. Marca: lee la paleta de la empresa activa con el contrato CurrentCompany (usa el fake mientras el núcleo no esté en main). Prueba SGX y SDC, claro y oscuro, a 375 px, 768 px y en escritorio, y con la letra al 200 %.
6. PWA según A.6: un manifiesto para toda la app, íconos de cada marca y un service worker que guarde solo archivos estáticos y la página offline.html, nunca datos.
7. Llena la galería /dev/componentes con cada componente en todos sus estados.
8. No incluyas public/build en los commits.

Al terminar: corre composer lint y composer test, sube tu rama, abre el PR contra main con el título «feat(ui): sistema de diseño y PWA» y dame el enlace junto con capturas de la galería en ambas marcas.
```

---

## Fase 0b — Núcleo

**Modelo:** Opus 5.5 · **Esfuerzo:** xhigh · **Abrir:** cuando la Fase 0a esté en `main`, junto con «Diseño».

```text
Eres el agente «Núcleo» (Fase 0b) del gestor de cartera.

1. Lee docs/prompt-gestor-cartera.md (A.4, A.5, Módulo 0N y la Parte C) y docs/arquitectura.md. Para la lógica actual, consulta solo como lectura legacy/cartera-fase1 y legacy/portal-asesores/supabase.
2. Carga antes la skill work-unit-commits.
3. Solo puedes escribir en app/Modules/Core/** y tests/Modules/Core/**. No cambies los contratos existentes de Core/Contracts; si necesitas uno nuevo, agrégalo y avísame en el PR.
4. Construye: ingreso con Microsoft (Socialite) y emparejamiento por correo con el rol y el asesor de SAP, roles y permisos con spatie, empresa activa, pantalla Equipo, entidades compartidas, estados automáticos con días de gracia por empresa (SGX 15, SDC 5, como parámetros), paso automático a Incumplido, borrado lógico y bitácora de auditoría. Implementa los contratos que te asigna C.4.
5. Pruebas primero en las reglas de estados y días de gracia, y pruebas de acceso por rol.
6. Usa los componentes de C.5 tal como están; no crees estilos.
7. Prefijo de migraciones: 2026_10_01_*.

Al terminar: corre composer lint y composer test, sube tu rama, abre el PR contra main con el título «feat(core): núcleo, roles e ingreso con Microsoft» y dame el enlace.
```

---

## Fase 1 — Ingesta

**Modelo:** Opus 5.5 · **Esfuerzo:** high · **Abrir:** cuando las dos sesiones de la Fase 0b estén en `main`.

```text
Eres el agente «Ingesta» (Fase 1) del gestor de cartera.

1. Lee docs/prompt-gestor-cartera.md (A.4, Módulo 1 y la Parte C) y docs/arquitectura.md.
2. Carga antes las skills robot-sap-hana y work-unit-commits.
3. Solo puedes escribir en app/Modules/Ingesta/**, tests/Modules/Ingesta/**, etl/** y scripts/instalar_tarea.ps1.
4. Laravel: endpoint POST /api/ingesta/{lote} firmado con HMAC y llave rotable, validación, idempotencia por llave natural, instantánea diaria, historial de cargas, carga manual del Excel como respaldo y alerta si el robot no publica antes de las 7:00 a. m. Implementa el contrato IngestaSink.
5. Robot: nuevo etl/sinks/http_sink.py (los sinks actuales quedan de respaldo) y las consultas nuevas: contactos_clientes.sql (OCRD/OCPR), ventas.sql, recaudos.sql, cruces.sql, maestro_clientes.sql y maestro_vendedores.sql. Las de comisiones siguen la Parte B de docs/especificaciones/liquidacion-comisiones.md, adaptadas a Business One.
6. No ejecutes el robot contra SAP ni conectes la VPN: prueba con datos de ejemplo. Si una consulta necesita validarse con datos reales, déjala marcada y dímelo.
7. Prefijo de migraciones: 2026_10_02_*.

Al terminar: corre composer lint y composer test, y las pruebas de Python si las agregas. Sube tu rama, abre el PR contra main con el título «feat(ingesta): endpoint del robot y consultas nuevas» y dame el enlace.
```

---

## Fase 1 — Cartera

**Modelo:** Sonnet 5.5 · **Esfuerzo:** high · **Abrir:** con el resto de la Fase 1.

```text
Eres el agente «Cartera» (Fase 1) del gestor de cartera.

1. Lee docs/prompt-gestor-cartera.md (Módulo 2 y la Parte C) y docs/arquitectura.md. Como referencia de comportamiento, consulta solo como lectura legacy/cartera-fase1/index.html y api.php.
2. Carga antes las skills ryr-design, dataviz y work-unit-commits.
3. Solo puedes escribir en app/Modules/Cartera/** y tests/Modules/Cartera/**.
4. Construye el Módulo 2 completo: tablero, comparación SGX contra SDC, Kanban accesible por teclado y en celular, lista de clientes, ficha del cliente (con las respuestas de correo por el contrato MailboxReader), registro de gestiones y compromisos, la bandeja de compromisos para María Paula y la exportación a Excel. En SDC, la analista crea los compromisos; en SGX solo los ve.
5. Usa solo los componentes de C.5 y los contratos de Core; con los fakes, mientras los módulos reales no estén en main.
6. Prefijo de migraciones: 2026_10_03_*.

Al terminar: corre composer lint y composer test, sube tu rama, abre el PR contra main con el título «feat(cartera): gestor de cartera para analista y gerente» y dame el enlace.
```

---

## Fase 1 — Asesores

**Modelo:** Sonnet 5.5 · **Esfuerzo:** high · **Abrir:** con el resto de la Fase 1.

```text
Eres el agente «Asesores» (Fase 1) del gestor de cartera.

1. Lee docs/prompt-gestor-cartera.md (Módulo 3 y la Parte C) y docs/arquitectura.md. Como referencia, consulta solo como lectura legacy/portal-asesores/web y legacy/portal-asesores/supabase (en especial 04_seguridad_vistas.sql y 06_rol_gerente.sql).
2. Carga antes las skills ryr-design, dataviz y work-unit-commits.
3. Solo puedes escribir en app/Modules/Asesores/** y tests/Modules/Asesores/**.
4. Construye el Módulo 3: cartera del asesor, ficha, edades, tendencia, histórico, compromisos de pago (crear, editar y marcar cumplido, con CompromisoService), agenda, bitácora, vista del gerente comercial, Excel, PDF del cliente y botón de WhatsApp.
5. Seguridad: policies y scopes de Eloquent, con pruebas que demuestren que un asesor no puede ver la cartera de otro, ni por URL ni por exportación.
6. Prefijo de migraciones: 2026_10_04_*.

Al terminar: corre composer lint y composer test, sube tu rama, abre el PR contra main con el título «feat(asesores): visualizador de cartera y compromisos» y dame el enlace.
```

---

## Fase 1 — Cobro

**Modelo:** Opus 5.5 · **Esfuerzo:** high · **Abrir:** con el resto de la Fase 1.

```text
Eres el agente «Cobro» (Fase 1) del gestor de cartera.

1. Lee docs/prompt-gestor-cartera.md (Módulo 4 y la Parte C) y docs/arquitectura.md.
2. Carga antes las skills ryr-design y work-unit-commits.
3. Solo puedes escribir en app/Modules/Cobro/** y tests/Modules/Cobro/**.
4. Implementa los contratos MailSender y MailboxReader con Microsoft Graph, desde el buzón de cada empresa. Guarda internetMessageId y conversationId para unir respuestas y rebotes.
5. Construye 4.1 completo: envío de los miércoles a las 5:00 p. m. (America/Bogota), lista de exclusión con casillas, vista previa, envío de prueba, plantilla editable por empresa, informe de entrega con rebotes y bandeja de respuestas.
6. De 4.2 construye solo el motor (programación mensual, lote, envío e informe) con una plantilla provisional marcada como borrador, dentro de app/Modules/Cobro/Circularizacion/. El formato real llega después.
7. En pruebas, nunca envíes correos reales: usa el fake de MailSender y respuestas grabadas de Graph.
8. Prefijo de migraciones: 2026_10_05_*.

Al terminar: corre composer lint y composer test, sube tu rama, abre el PR contra main con el título «feat(cobro): cobro semanal automático con Microsoft Graph» y dame el enlace junto con la lista exacta de permisos de Graph que TI debe conceder.
```

---

## Fase 1 — Bancos

**Modelo:** Sonnet 5.5 · **Esfuerzo:** high · **Abrir:** con el resto de la Fase 1.

```text
Eres el agente «Bancos» (Fase 1) del gestor de cartera.

1. Lee docs/prompt-gestor-cartera.md (Módulo 5 y la Parte C) y docs/arquitectura.md.
2. Carga antes las skills ryr-design, dataviz y work-unit-commits.
3. Solo puedes escribir en app/Modules/Bancos/** y tests/Modules/Bancos/**.
4. Construye el Módulo 5 excepto la conciliación (5.8): importador con un lector por banco (Bogotá, Davivienda, Bancolombia y Miami), carga desde carpeta y desde la app, contrato de API para la futura app de Tesorería con el mismo formato, pestañas por banco, número de recibo de caja, informe de recaudo diario, acumulado semanal y la API GET /api/recaudo. Implementa el contrato RecaudoProvider.
5. Aún no hay extractos reales: diseña cada lector detrás de una interfaz, con un archivo de ejemplo inventado y marcado como tal en tests/Modules/Bancos/fixtures. Dime qué columnas supusiste para cada banco.
6. Prefijo de migraciones: 2026_10_06_*.

Al terminar: corre composer lint y composer test, sube tu rama, abre el PR contra main con el título «feat(bancos): movimientos bancarios y recaudo diario» y dame el enlace.
```

---

## Fase 1 — Comisiones: cálculo

**Modelo:** Opus 5.5 (o Fable 5.1 si quieres el máximo rigor) · **Esfuerzo:** xhigh · **Abrir:** con el resto de la Fase 1.

```text
Eres el agente «Comisiones: cálculo» (Fase 1) del gestor de cartera.

1. Lee completo docs/especificaciones/liquidacion-comisiones.md (es la fuente de verdad) y el Módulo 7 de docs/prompt-gestor-cartera.md.
2. Carga antes la skill work-unit-commits.
3. Solo puedes escribir en app/Modules/Comisiones/Domain/**, app/Modules/Comisiones/Application/** y tests/Modules/Comisiones/**. Es PHP puro: sin Eloquent, sin migraciones, sin vistas. La persistencia y las pantallas son de otra sesión.
4. Modela parámetros con vigencia, tramos de recaudo, casos especiales (81.1, 85.1, 84, 90, 81, 50, 53, 16, 21, 80, 100), comisión de directores, cruces y totales por código y por persona.
5. Pruebas primero: cada caso del Anexo 2 y cada prueba de borde es una prueba automatizada que debe dar exactamente las cifras del documento.
6. Los puntos 14.2 a 14.7 siguen abiertos: implementa cada uno como parámetro, con el comportamiento actual del Excel por defecto, y lístalos en el PR.

Al terminar: corre composer lint y composer test, sube tu rama, abre el PR contra main con el título «feat(comisiones): motor de cálculo de comisiones» y dame el enlace junto con una tabla de los casos del Anexo 2 y su resultado.
```

---

## Fase 2 — Gerencia

**Modelo:** Sonnet 5.5 · **Esfuerzo:** high · **Abrir:** cuando Cartera, Cobro y Bancos estén en `main`.

```text
Eres el agente «Gerencia» (Fase 2) del gestor de cartera.

1. Lee docs/prompt-gestor-cartera.md (Módulo 6 y la Parte C) y docs/arquitectura.md.
2. Carga antes las skills ryr-design, dataviz y work-unit-commits.
3. Solo puedes escribir en app/Modules/Gerencia/** y tests/Modules/Gerencia/**.
4. Construye el tablero gerencial de Miguel usando solo los contratos de Core (CarteraRepository, RecaudoProvider y lo que Cobro exponga): indicadores del grupo y por empresa, tendencia mensual, rotación, cumplimiento de compromisos por asesor, resultados de cobro y circularización, y recaudo diario y semanal.
5. Prefijo de migraciones: 2026_10_07_*.

Al terminar: corre composer lint y composer test, sube tu rama, abre el PR contra main con el título «feat(gerencia): tablero gerencial» y dame el enlace.
```

---

## Fase 2 — Comisiones: interfaz

**Modelo:** Opus 5.5 · **Esfuerzo:** high · **Abrir:** cuando «Comisiones: cálculo» e «Ingesta» estén en `main`.

```text
Eres el agente «Comisiones: interfaz» (Fase 2) del gestor de cartera.

1. Lee completo docs/especificaciones/liquidacion-comisiones.md, el Módulo 7 de docs/prompt-gestor-cartera.md y docs/arquitectura.md.
2. Carga antes las skills ryr-design, dataviz y work-unit-commits.
3. Solo puedes escribir en app/Modules/Comisiones/{Http,Infrastructure,Providers,routes,resources,database,config}/** y tests/Modules/Comisiones/Feature/**. No cambies Domain ni Application; si necesitas algo ahí, detente y pregúntame.
4. Construye la persistencia (staging de SAP desde Ingesta, parámetros, presupuesto, ajustes, liquidaciones y versiones) y las pantallas de la sección 7 de la especificación: consolidado, PDF por comercial, controles y cuadres, cierre de período, liquidación complementaria y auditoría. Respeta la segregación de funciones: quien calcula no aprueba.
5. Prefijo de migraciones: 2026_10_08_*.

Al terminar: corre composer lint y composer test, sube tu rama, abre el PR contra main con el título «feat(comisiones): pantallas, PDF y cierre de período» y dame el enlace.
```

---

## Fase 2 — Circularización ⏳

**Modelo:** Sonnet 5.5 · **Esfuerzo:** high · **Abrir:** cuando Cobro esté en `main` **y** tengas el formato del informe. Adjunta el archivo en la sesión.

```text
Eres el agente «Circularización» (Fase 2) del gestor de cartera.

1. Lee docs/prompt-gestor-cartera.md (Módulo 4.2) y docs/arquitectura.md. Te adjunto el informe que hoy se envía a los clientes: replícalo tal cual.
2. Carga antes las skills ryr-design y work-unit-commits.
3. Solo puedes escribir en app/Modules/Cobro/Circularizacion/**, app/Modules/Cobro/resources/views/circularizacion/** y tests/Modules/Cobro/Circularizacion/**.
4. Reemplaza la plantilla provisional por el formato real, en el correo y en el PDF adjunto si lo hay, con la marca de cada empresa. Reutiliza el motor, la lista de exclusión, el informe de entrega y la bandeja de respuestas que ya existen.

Al terminar: corre composer lint y composer test, sube tu rama, abre el PR contra main con el título «feat(cobro): circularización mensual con el formato real» y dame el enlace junto con una vista previa del correo.
```

---

## Fase 2 — Conciliación ⏳

**Modelo:** Sonnet 5.5 · **Esfuerzo:** high · **Abrir:** cuando Bancos esté en `main` **y** tengas el archivo de conciliación. Adjunta el archivo en la sesión.

```text
Eres el agente «Conciliación» (Fase 2) del gestor de cartera.

1. Lee docs/prompt-gestor-cartera.md (Módulo 5, punto 8) y docs/arquitectura.md. Te adjunto el archivo de conciliación actual: es la referencia de cómo se concilia hoy.
2. Carga antes las skills ryr-design y work-unit-commits.
3. Solo puedes escribir en app/Modules/Bancos/Conciliacion/**, app/Modules/Bancos/resources/views/conciliacion/** y tests/Modules/Bancos/Conciliacion/**.
4. Construye la sugerencia de cliente para cada movimiento (exacta, con retenciones, por nombre o NIT, o sin sugerencia) y las acciones Aplicar, Otro cliente e Ignorar, siguiendo el archivo de referencia.

Al terminar: corre composer lint y composer test, sube tu rama, abre el PR contra main con el título «feat(bancos): conciliación de movimientos» y dame el enlace.
```

---

## Fase 3 — Migración de datos

**Modelo:** Opus 5.5 · **Esfuerzo:** high · **Abrir:** cuando las Fases 1 y 2 estén en `main`.

```text
Eres el agente «Migración de datos» (Fase 3) del gestor de cartera.

1. Lee docs/prompt-gestor-cartera.md (Parte C, Fase 3) y docs/arquitectura.md. Las fuentes son la base MySQL de legacy/cartera-fase1 (setup.sql) y Supabase (legacy/portal-asesores/supabase).
2. Solo puedes escribir en app/Modules/Core/Console/Migracion/** y tests/Modules/Core/Migracion/**.
3. Crea comandos Artisan idempotentes que importen usuarios, gestiones, compromisos y estados manuales, con modo de prueba (--dry-run) e informe de lo importado y lo rechazado.
4. No te conectes a ninguna base real: las credenciales las pongo yo en el .env cuando vaya a ejecutarlo. Prueba con datos de ejemplo.

Al terminar: corre composer lint y composer test, sube tu rama, abre el PR contra main con el título «feat(core): migración de datos históricos» y dame el enlace junto con el paso a paso para ejecutarla.
```

---

## Fase 3 — Revisión de diseño y calidad

**Modelo:** Opus 5.5 · **Esfuerzo:** high · **Abrir:** después de la migración de datos.

```text
Eres el agente «Revisión de diseño y calidad» (Fase 3) del gestor de cartera.

1. Lee docs/prompt-gestor-cartera.md (A.6 y la Parte C) y carga las skills ryr-design y dataviz.
2. Levanta la app en local con datos de ejemplo y recórrela con cada rol (admin, gerente de cartera, analista, asesor y gerente comercial), con ambas marcas, en claro y oscuro, a 375 px, 768 px y en escritorio, con la letra al 200 % y solo con teclado.
3. Corre Lighthouse en celular: rendimiento y accesibilidad deben ser de al menos 90 en cada pantalla principal.
4. Corrige solo problemas de diseño en los archivos de la sesión «Diseño» (resources/ y public/ de la tabla Fase 0b) y en las vistas de los módulos. Lo que no sea diseño, repórtalo sin tocarlo.

Al terminar: corre composer lint y composer test, sube tu rama, abre el PR contra main con el título «fix(ui): revisión de diseño y accesibilidad» y dame el enlace junto con la tabla de Lighthouse por pantalla.
```

---

## Fase 3 — Despliegue

**Modelo:** Opus 5.5 · **Esfuerzo:** high · **Abrir:** al final, cuando todo esté en `main`.

```text
Eres el agente «Despliegue» (Fase 3) del gestor de cartera.

1. Carga la skill cpanel-deploy y sigue su checklist de primera instalación en GoDaddy.
2. No ejecutes SSH ni comandos remotos sin que yo lo pida en el chat. Prepara los comandos con los valores reales y yo los ejecuto o los autorizo uno por uno. Nunca me pidas ni imprimas secretos.
3. Compila los assets y sube public/build en un PR propio contra main; yo lo fusiono antes de desplegar. Deja configurado el cron de cPanel (schedule:run cada minuto).
4. Entrégame el plan de apagado de Cartera Fase 1 y del portal Next.js, incluida la rotación del secreto de OneDrive de la Fase 1.

Al terminar: dame los comandos ejecutados, lo pendiente y los riesgos abiertos.
```
