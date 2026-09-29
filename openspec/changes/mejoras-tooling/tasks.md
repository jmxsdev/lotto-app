# Tasks: `mejoras-tooling` — ParallelTesting de la suite backend

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~25-35 líneas |
| 400-line budget risk | Low |
| 800-line budget risk | Low |
| Chained PRs recommended | No (1 slice) |
| Suggested split | PR única (1 work unit) |
| Delivery strategy | auto-chain |
| Chain strategy | feature-branch-chain |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: feature-branch-chain
400-line budget risk: Low
800-line budget risk: Low

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Paratest + script + CI + docs | PR 1 (base `feat/mejoras-tooling`) | `composer test:parallel` | `composer test:parallel` ×2 (REQ-1 esc 1, REQ-2 esc 2/3) | `composer remove brianium/paratest`, borrar script, restaurar paso CI secuencial |

### TDD (sujeto = la propia suite; sin tests unitarios nuevos)

- RED (honesto): paratest + script instalados, correr `composer test:parallel` con usuario MySQL sin `CREATE DATABASE` → `Access denied ... CREATE DATABASE` (REQ-1 esc 2 / REQ-2 esc 1). Evidencia en verify-report.
- GREEN: 2 corridas `composer test:parallel` verdes, sin flaky (REQ-1 esc 1, REQ-2 esc 2/3). CI no dispara en PR (solo push a main + dispatch): evidencia real post-merge.

## Phase 1: Dependencia paratest

- [ ] 1.1 `backend/composer.json` → `require-dev` añadir `"brianium/paratest": "^7.20.0"`; `composer update brianium/paratest` regenera `backend/composer.lock`.
  - Verificación: `composer show brianium/paratest` → 7.20.x; lock resuelto sin conflicto con PHPUnit 12.5.31.
  - Done: dep instalada, lock actualizado, sin subir a 7.21+ (exige PHP 8.4/PHPUnit 13).

## Phase 2: Script `test:parallel`

- [ ] 2.1 `backend/composer.json` scripts: array espejo de `test` → `"test:parallel": ["@php artisan config:clear --ansi @no_additional_args", "@php artisan test --parallel --processes=4"]`.
  - Verificación: `composer test:parallel -- --processes=2` → "Processes: 2" (override gana); `-p 2` se ignora (Collision la filtra).
  - Done: override `--processes=N` funciona; nunca `-p`.

## Phase 3: CI paralelo

- [ ] 3.1 `.github/workflows/ci-cd.yml`: service `mysql` añadir `MYSQL_ROOT_HOST: "%"`; env `DB_USERNAME: root` / `DB_PASSWORD: root`; paso PHPUnit → `php artisan test --parallel --processes=4 --display-warnings --log-junit junit.xml`.
  - Verificación RED: contenedor MySQL 8.0 con usuario restringido (sin CREATE) → `Access denied ... CREATE DATABASE` (REQ-2 esc 1).
  - Verificación GREEN: `composer test:parallel` ×2 verdes locales (REQ-1, REQ-2 esc 2/3).
  - Done: CI usa root + `MYSQL_ROOT_HOST: "%"`; junit sin `<warning`.

## Phase 4: Documentación

- [ ] 4.1 `docs/runbook-ops.md`, sección «Entorno local (backend)» tras paso 11 (L171): comando `composer test:parallel`, override `-- --processes=N`, prerrequisito `CREATE/DROP DATABASE` + DML sobre `lotto_test_test_%` con ejemplo `GRANT` para `lotto_user`, nota de `-p` filtrada, bases persistentes (`--recreate-databases` solo tras migraciones).
  - Verificación: `git diff` muestra la nota en la sección correcta.
  - Done: runbook documenta comando + requisito de privilegio (REQ-1 esc 2).

### Plan de commits (work-unit, conventional español, rama `feat/mejoras-tooling`, sin push)

1. `chore(backend): agrega brianium/paratest ^7.20.0`
2. `chore(backend): agrega script composer test:parallel`
3. `ci: paraleliza tests y habilita root en MySQL`
4. `docs(runbook): documenta test:parallel y privilegio CREATE DATABASE`
