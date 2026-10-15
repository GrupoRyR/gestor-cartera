# Gestor de cartera

Plataforma de cartera del Grupo RYR para Segurex (SGX) y Soluciones y Derivados (SDC). Une la app Cartera Fase 1 y el portal de asesores, y agrega:

- cobro automático
- circularización
- movimientos de bancos
- liquidación de comisiones

## Estructura

| Carpeta | Contenido |
|---|---|
| raíz | Proyecto Laravel 13 (lo crea la Fase 0a). |
| `app/Modules/` | Un directorio por módulo. |
| `etl/` | Robot Python que extrae la cartera de SAP Business One (HANA). |
| `scripts/` | Programación del robot en el Programador de tareas de Windows. |
| `legacy/` | Código de las apps anteriores, solo como referencia. |
| `docs/` | Prompt del proyecto, órdenes de los agentes y especificaciones. |

## Cómo se construye

Varios agentes trabajan en paralelo, cada uno en su worktree y con sus propios archivos. El plan está en [docs/prompt-gestor-cartera.md](docs/prompt-gestor-cartera.md) (Parte C) y las órdenes, en [docs/ordenes-agentes.md](docs/ordenes-agentes.md).
