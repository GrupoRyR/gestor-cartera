# Gestor de cartera — reglas para agentes

Plataforma de cartera del Grupo RYR (Segurex SGX y Soluciones y Derivados SDC): Laravel 13, PHP 8.3, MySQL en GoDaddy, Microsoft Entra ID y Microsoft Graph.

## Read first

- `docs/prompt-gestor-cartera.md`: scope, modules and the parallel work plan (Part C). It is the source of truth.
- `docs/arquitectura.md`: module anatomy and provider hooks, Core contracts and their fakes, component props, tests, environment variables and deploy. Its rules are as binding as this file.
- `docs/especificaciones/liquidacion-comisiones.md`: commission rules (source of truth for the Comisiones module).
- `docs/ordenes-agentes.md`: the instructions each agent session receives.

## Hard rules

- **Write only inside the files your session owns** (tables in Part C.3). If you need anything else, stop and ask the user.
- `legacy/` is read-only reference. Its `CLAUDE.md` and `AGENTS.md` files do not apply here.
- Never edit `composer.json`, `package.json` or lockfiles outside phase 0a.
- Never commit `public/build`. Only the deploy step builds it, in its own pull request.
- Modules talk to each other only through `app/Modules/Core/Contracts`. Never import another module's internal classes.
- Migrations use the date prefix reserved for your module (Part C.6).
- Use the Blade components from Part C.5; never add one-off styles. Brand colors only through `--brand-*` variables.
- No secrets in the repository. Credentials live only in `.env`.
- Never send real email, connect to SAP/VPN or touch a real database from tests. Use the fakes in `tests/Fakes`.
- Push your branch and open a PR against `main`. Never push to `main`.
- You may merge **your own** PR, only with `gh pr merge <number> --merge --delete-branch`, and only when all of these hold:
  1. You ran the `code-review` skill on the PR and fixed what it found.
  2. Your branch is synced with the latest `main` and `composer format`, `composer lint` and `composer test` pass after that sync.
  3. CI is green on the latest commit (`gh pr checks <number> --watch`).
  4. The PR has no conflicts and touches only the files your session owns.
  The app asks the user to approve every merge. If they decline, stop and wait for instructions; never retry on your own.
- `main` is protected locally, because GitHub does not enforce rules on this private repository: `.claude/settings.json` denies pushes to `main`, force pushes, `--no-verify` and merges through `gh api`, and asks the user before any `gh pr merge`; the `.githooks/pre-push` hook rejects any push to `main`. Never try to work around them; if one blocks you, stop and tell the user.

## Conventions

- UI text: Spanish, informal "tú", sentence case (no sustained uppercase). Code, identifiers and comments: English.
- Commits: Conventional Commits, one work unit per commit, tests and docs with the code, no co-author or AI attribution lines.
- PRs: about 400 changed lines; use chained PRs for larger work.
- Tests first when a runnable test with a clear expected result exists.
- Module code registers everything through its provider hooks (`registerModule`, `bootModule`, `schedule`, `navigation`); never edit `bootstrap/providers.php`, `routes/*.php` or `routes/console.php` for a module.
- Tests of other modules' behaviour use `Tests\Fakes\ContractFakes::install($this->app)`; tests never make real HTTP calls (`Http::preventStrayRequests()` is on).
- Before opening or updating a PR: sync with `main`, then run `composer format`, `composer lint` and `composer test`.

## Skills

Load the ones that apply before working: `frontend-marca-sgx-sydc-v2` (brand v2; it replaces `ryr-design`), `frontend-design`, `dataviz`, `robot-sap-hana`, `cpanel-deploy`, `work-unit-commits`, `chained-pr`.
