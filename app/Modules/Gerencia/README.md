# Gerencia

Tablero gerencial de Miguel (gerente de cartera): cartera, cumplimiento de compromisos, resultados del cobro por correo y recaudo del grupo y de cada empresa (`docs/prompt-gestor-cartera.md`, módulo 6). Solo lee: no escribe en ninguna tabla y habla con el resto de la app únicamente por los contratos de Core (`docs/arquitectura.md` §6).

## Pantalla

| Ruta | Nombre | Permiso | Qué hace |
|---|---|---|---|
| `GET /gerencia` | `gerencia.tablero` | `gerencia.view` | El tablero. `?periodo=mes` (por defecto), `mes_anterior`, `trimestre` o `anio`; cualquier otro valor muestra el mes actual. |

Permiso (`GerenciaPermissionSeeder`): `gerencia.view` solo para el gerente de cartera. El analista trabaja desde el tablero de Cartera. La entrada del menú («Tablero gerencial») va con `order: 50` a propósito: Core manda a cada usuario a la primera entrada que puede abrir y el gerente debe seguir aterrizando en el tablero de Cartera.

El tablero sigue la empresa activa del selector: con la vista de grupo muestra el grupo y compara cada empresa; con una sola empresa, solo esa. Secciones, en orden:

1. Indicadores del alcance actual: cartera total, vencido (color por porcentaje: 30 % o más, peligro; 15 % o más, alerta), rotación y cumplimiento de compromisos.
2. Comparación del grupo con cada empresa (solo en la vista de grupo).
3. Tendencia: línea del porcentaje vencido por mes, una serie por alcance (el grupo punteado; SGX y SDC con su color fijo), y la misma información en una tabla.
4. Cumplimiento de compromisos por asesor.
5. Resultados del cobro por correo: un bloque para el cobro semanal y otro para la circularización, cada uno con el total del alcance y, en la vista de grupo, una fila por empresa; al final, los mensajes sin leer.
6. Recaudo: barras por día (períodos de hasta 45 días) o por semana (más largos), tabla semanal y tabla diaria.

Si no hay ninguna carga de cartera, el tablero lo dice y oculta los indicadores, la comparación y la tendencia; las demás secciones siguen mostrando lo que tengan.

## Cómo se calcula cada cifra

- **Cartera total, vencido y porcentaje:** `CarteraRepository::totals()` del alcance. El porcentaje es vencido ÷ total.
- **Rotación (aproximada):** se rotula «aprox., por recaudo» porque no usa ventas. Recaudo del período ÷ cartera promedio del período. El recaudo sale de `RecaudoProvider` (créditos bancarios por fecha de movimiento) y la cartera promedio, de la media de las fotos de `CarteraRepository::history()` cuyo corte cae en el período; sin fotos usa la cartera actual. «Días por vuelta» = días del período ÷ rotación (sin rotación, no hay valor).
- **Tendencia:** una foto por mes calendario (la de corte más reciente), de los últimos `gerencia.trend_months` meses. Todas las series comparten los mismos meses; un mes sin foto de una empresa corta su línea.
- **Cumplimiento:** `CompromisoService::paginate()` con los compromisos que vencen en el período, leídos por páginas hasta `gerencia.compliance_max_pages`. Cuentan los cumplidos (pagados a tiempo o tarde), los incumplidos y los vencidos sin pago; los cancelados no se cuentan y los que vencen hoy o después aparecen como próximos, sin evaluarse. Cumplimiento = cumplidos ÷ evaluados, por asesor y empresa; el color del chip es verde desde 90 %, ámbar desde 70 % y rojo por debajo. Los más incumplidos van primero. Si quedan compromisos sin leer, el tablero avisa que las cifras son parciales. En los períodos que llegan hasta hoy, «Próximos» solo trae los que vencen hoy, porque la lectura termina en la fecha de hoy.
- **Resultados del cobro:** `MailRunResultsProvider::results()` una vez por empresa del alcance (`CurrentCompany::scope()`) y por tipo (`MailRunKind`: cobro semanal y circularización), como mucho cuatro llamadas. El contrato no filtra por usuario, así que el tablero nunca pide una empresa que el usuario no ve. Entra cada lote cuya fecha programada cae en el período (día calendario en Bogotá, extremos incluidos), sin tope de lectura. La circularización sale el día 1 con el cierre del mes anterior, así que el envío de un mes muestra el cierre del mes previo; el tablero lo dice bajo su título. Todas las cifras cuentan clientes: enviados (salió el correo, rebotara o no), entregados (enviados − rebotados), rebotados, respuestas (aunque lleguen después del período), sin correo y fallidos (el buzón no tomó el correo). Porcentaje rebotado = rebotados ÷ enviados y porcentaje de respuesta = respuestas ÷ enviados; sin enviados no hay porcentaje. El total de cada tipo suma las empresas del alcance; un tipo sin lotes en el período muestra «Sin envíos en el período». «Sin leer ahora» sale de `MailboxReader::unreadCount()` y no depende del período.

## Configuración

`app/Modules/Gerencia/config/gerencia.php`, leída con `config('gerencia.<llave>')`:

| Llave | Por defecto | Para qué |
|---|---|---|
| `group_name` | `Grupo RYR` | Nombre de la vista de grupo. |
| `trend_months` | `12` | Meses de historia detrás de la tendencia. |
| `compliance_page_size` | `200` | Compromisos por página al medir el cumplimiento. |
| `compliance_max_pages` | `50` | Páginas que se leen como máximo; pasado el tope el cumplimiento queda marcado como parcial. |

Los umbrales de color (vencido 15 % y 30 %; cumplimiento 70 % y 90 %) son constantes de `Http/Presenters/Tones.php`, no configuración.

## Gráficos

`<x-gerencia::line-chart>` y `<x-gerencia::bar-chart>` son SVG dibujados en el servidor (sin JavaScript): cada punto y cada barra llevan un `<title>` con su valor para el cursor y los lectores de pantalla, y la cuadrícula, los ticks y las etiquetas salen de `Http/Presenters/` (`ChartScale`, `LineChartGeometry`, `BarChartGeometry`). El color sigue a la entidad (SGX, SDC) y el grupo va punteado; cada gráfico tiene su tabla al lado.

## Pruebas

`php artisan test tests/Modules/Gerencia`. Las pruebas de pantalla están en `Feature/BoardTest.php` y las de los presentadores en `Feature/Presenters/`; usan `ContractFakes` y `Support/GerenciaUsers.php`.
