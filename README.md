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

## Primer paso después de clonar

Activa el hook que bloquea las subidas directas a `main`. Se hace una vez por cada clon:

```bash
git config core.hooksPath .githooks
```

GitHub no aplica reglas de rama en repositorios privados de cuentas gratuitas, así que `main` se protege en el equipo:

- **El hook `.githooks/pre-push`** rechaza cualquier subida a `main`.
- **`.claude/settings.json`** impide que los agentes suban a `main`, fuercen una subida o fusionen PR.

Los cambios llegan a `main` solo por PR fusionados en la web de GitHub.

## Cómo se construye

Varios agentes trabajan en paralelo, cada uno en su worktree y con sus propios archivos. El plan está en [docs/prompt-gestor-cartera.md](docs/prompt-gestor-cartera.md) (Parte C) y las órdenes, en [docs/ordenes-agentes.md](docs/ordenes-agentes.md).
