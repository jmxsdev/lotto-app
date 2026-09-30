# Design: `mejoras-tooling` — ParallelTesting de la suite backend

## Enfoque técnico

Aditivo, sin dominio nuevo; mapea REQ-1/2/3: dep `brianium/paratest:^7.20.0` +
script `test:parallel` (4 procesos) + fix de credenciales MySQL en CI + paso
PHPUnit con `--parallel`. Sin migraciones.

## Decisiones

### D1 — Credenciales CI

| Opción | Tradeoff | Decisión |
| --- | --- | --- |
| **A. root + `MYSQL_ROOT_HOST: "%"`** | 3 líneas; runner efímero y creds root/root ya visibles en el workflow; cubre CREATE/DROP | **Elegida** |
| B. `docker exec ... GRANT ALL ON \`lotto\_test\_%\`.*` | least-privilege irrelevante en runner efímero; paso extra con escapes; wildcards en GRANT de BD **deprecados desde MySQL 8.0.35** | Rechazada |
| C. `--without-databases` | rompe el aislamiento por proceso → viola REQ-2 | Rechazada |

Hallazgo nuevo: `MYSQL_ROOT_HOST` es vacío por defecto en `mysql:8.0` (solo
`root@localhost`); el runner conecta vía IP de bridge → sin esa línea A falla
con `Access denied`. Son 3 líneas, no 2.

### D2 — Procesos y script

| Opción | Tradeoff | Decisión |
| --- | --- | --- |
| **Array espejo de `test`** | `config:clear` evita config cache stale; los args extra van al último comando → `composer test:parallel -- --processes=N` sobreescribe (Symfony: gana el último) | **Array** |
| N=4 local y CI | CI=4 vCPU; local (12 cores) paga migrate + 25 seeders por worker (RAM/IO); N>4 gana poco | **4 y 4** |
| `-p N` | Collision filtra `-p*` en silencio (`TestCommand.php` L262) | **Prohibida** |

Script: `"test:parallel": ["@php artisan config:clear --ansi @no_additional_args", "@php artisan test --parallel --processes=4"]`.

### D3 — Bases por proceso

| Pregunta | Evidencia (vendor v13.19.0) | Decisión |
| --- | --- | --- |
| Patrón | `TestDatabases::testDatabase()` L198-209: `"{$database}_test_{$token}"` **incondicional** (sin dedupe); token = `TEST_TOKEN` del worker | `lotto_test` → `lotto_test_test_1..4`; `lotto_test_motor` → `lotto_test_motor_test_1..4` |
| ¿Hooks/`phpunit.xml`? | `ParallelTestingServiceProvider::boot()` ya invoca `bootTestDatabase()` | **No se toca** |

### D4 — Docs

`docs/runbook-ops.md`, bajo «Entorno local (backend)» (tras paso 11, L171):
comando y override; requisito CREATE/DROP + DML sobre `lotto_test_test_%` con
ejemplo `GRANT` para `lotto_user`; nota de `-p`; las bases persisten
(`--recreate-databases` solo tras migraciones).

### D5 — TDD

Sin tests unitarios nuevos: el sujeto es la propia suite. RED = reproducción de
`Access denied` con usuario restringido (evidencia en verify-report); GREEN =
paralelo verde (sección Verificación).

## Flujo

```
composer test:parallel
 └─ artisan test --parallel --processes=4          (Collision)
    └─ paratest: 4 workers (TEST_TOKEN=1..4)
       └─ TestDatabases → CREATE `lotto_test_test_{token}` (requiere CREATE)
          └─ RefreshDatabase por worker: migrate + seeds
```

## Archivos

| Archivo | Acción | Detalle |
| --- | --- | --- |
| `backend/composer.json` | Modificar | dep `^7.20.0` + script `test:parallel` |
| `backend/composer.lock` | Modificar (generado) | resolución de paratest + deps |
| `.github/workflows/ci-cd.yml` | Modificar | +`MYSQL_ROOT_HOST: "%"`; DB root/root; PHPUnit +`--parallel --processes=4` |
| `docs/runbook-ops.md` | Modificar | nota de uso y privilegios |
| `backend/phpunit.xml` | Sin cambios | confirmado (D3) |

## Contratos

- `composer test:parallel` (override con `-- --processes=N`); siempre `--processes=N`, nunca `-p`.
- CI: usuario con CREATE/DROP y DML sobre `lotto_test_test_%`.

## Verificación

| Capa | Qué | Cómo |
| --- | --- | --- |
| RED | privilegio insuficiente | usuario restringido → `Access denied ... CREATE DATABASE` (REQ-2 esc. 1) |
| Integración | verde, aislada, sin flakiness | `composer test:parallel` ×2 (REQ-1, REQ-2 esc. 2/3) |
| CI réplica | crear `lotto_test_test_%` | contenedor MySQL 8.0 con el env exacto del workflow |
| CI real | REQ-3 | no corre en PRs (solo push a main + dispatch): run post-merge o dispatch en rama (build publica `:latest` en GHCR) |
| Regresión | junit | `--log-junit` agregado y grep `<warning`; si falla `--display-warnings`, quitarla |

Prerrequisito local: copiar `.env`/`.env.testing` (untracked) a este worktree.

## Matriz de amenazas

| Boundary | App | Respuesta | Verificación |
| --- | --- | --- | --- |
| Docs-like paths | N/A | sin clasificación/ejecución de rutas doc | — |
| Git repo / commit / push / PR | N/A | sin automatización VCS/PR | — |
| Argumentos de subproceso | **Aplicable** | `--processes=N` (nunca `-p`, filtrada); el override del usuario gana | `-p 2` se ignora; `--processes=2` muestra "Processes: 2" |

## Rollout / rollback

Sin migración. Rollout: merge a main. Revert aditivo: `composer remove`, borrar
script y restaurar el paso CI secuencial.

## Preguntas abiertas

- [ ] ¿Trigger `pull_request` en `ci-cd.yml`? Hoy «CI verde en el PR» no es alcanzable; default: no tocarlo.
- [ ] Confirmar que paratest agrega `junit.xml` y acepta `--display-warnings`.
- [ ] Pin `^7.20.0`: no subir a 7.21+ sin PHP 8.4 / PHPUnit 13.
