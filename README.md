# Gestor de cartera

Plataforma de cartera del Grupo RYR para Segurex (SGX) y Soluciones y Derivados (SDC). Une la app Cartera Fase 1 y el portal de asesores, y agrega:

- cobro automático
- circularización
- movimientos de bancos
- liquidación de comisiones

## Estructura

| Carpeta | Contenido |
|---|---|
| raíz | Proyecto Laravel 13 con PHP 8.3. |
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

## Desarrollo local

**Requisitos:** PHP 8.3 con la extensión `pdo_sqlite`, Composer 2 y Node 22 o más reciente. En local no hace falta MySQL: la app usa SQLite.

**Primera vez:**

1. Activa el hook de `main` (ver la sección anterior): `git config core.hooksPath .githooks`.
2. Instala las dependencias: `composer install` y `npm install`.
3. Copia `.env.example` a `.env` (`cp .env.example .env`; en PowerShell, `Copy-Item .env.example .env`).
4. Genera la llave: `php artisan key:generate`.
5. Crea la base SQLite vacía `database/database.sqlite` (`touch database/database.sqlite`; en PowerShell, `New-Item database/database.sqlite`).
6. Migra y siembra: `php artisan migrate --seed`. Con `APP_ENV=local` también corren los datos de demostración de los módulos.
7. Arranca todo con `composer dev`: servidor en http://localhost:8000, cola y Vite. Los logs quedan en `storage/logs/` (un archivo por día).

**Antes de abrir o actualizar un PR:**

| Comando | Para qué |
|---|---|
| `composer format` | Corrige el estilo con Pint. |
| `composer lint` | Revisa el estilo y corre Larastan. |
| `composer test` | Corre todas las pruebas (SQLite en memoria; CI las repite en MySQL 8). |

**Galería de componentes:** con `APP_ENV=local`, abre http://localhost:8000/dev/componentes. En cualquier otro entorno responde 404.

Antes de escribir código, lee [docs/arquitectura.md](docs/arquitectura.md): módulos, contratos, componentes, pruebas y despliegue.

## Cómo se construye

Varios agentes trabajan en paralelo, cada uno en su worktree y con sus propios archivos. El plan está en [docs/prompt-gestor-cartera.md](docs/prompt-gestor-cartera.md) (Parte C) y las órdenes, en [docs/ordenes-agentes.md](docs/ordenes-agentes.md).
