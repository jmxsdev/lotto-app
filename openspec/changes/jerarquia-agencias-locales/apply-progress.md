# Apply Progress: jerarquia-agencias-locales — PR 1 (F0, fundación de datos)

## Estado de tareas (Fase 1 — F0)

| Tarea | Estado | Evidencia |
|---|---|---|
| 1.1 TDD-RED `AgenciasBackfillTest` | ✅ | RED inicial: 5 errores "The command \"agencias:backfill\" does not exist." |
| 1.2 Migración `create_agencias_table` | ✅ | `2026_08_28_000001_create_agencias_table.php` (code único, grupo_id cascade, active, created_by, fiscal, softDeletes) |
| 1.3 Migraciones `agencia_id` + `master_id` | ✅ | `000002` (taquillas), `000003` (users), `000004` (bancas.master_id); nullable FK set null + index; down drop FK+columna |
| 1.4 Modelo `Agencia` | ✅ | fillable, casts active, SoftDeletes, `grupo()/taquillas()/users()/creator()` |
| 1.5 Modelos modificados | ✅ | `Taquilla`/`User` +`agencia_id`/`agencia()`; `Grupo` +`agencias()`; `Banca` +`master_id`/`master()`; `User::masterBancaIds()` |
| 1.6 Factories | ✅ | `AgenciaFactory` (code AG###) + `TaquillaFactory`/`UserFactory` con `agencia_id` null + estado `forAgencia()` |
| 1.7 Seeders | ✅ | Rol `agencia` Spatie con permisos de scope; usuario `agencia@lotto.com` en `UsersSeeder` |
| 1.8 Backfill `AgenciasBackfill` | ✅ | `{--force} {--dry-run}`, idempotente, log de resumen; GREEN 1.1 |
| 1.9 Verificación | ✅ | migrate + rollback + migrate en MySQL dev; backfill: 1 local/grupo, taquillas/users poblados, re-ejecución sin duplicados |

## TDD Cycle Evidence

| Tarea | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|---|---|---|---|---|---|---|---|
| 1.1/1.8 Backfill | `tests/Unit/AgenciasBackfillTest.php` | Unit (Integration con BD) | ✅ 220/222 | ✅ 5 fallos (comando inexistente) | ✅ 5/5 | ✅ 5 escenarios (1ª ejec, re-ejec, prod --force, master solo master, no-taquilla sin agencia) | ✅ Pint limpio |
| 1.4–1.6 Modelos/Factories | `tests/Unit/AgenciaModelTest.php` | Unit (Integration con BD) | ✅ 220/222 | ✅ escrito tras modelos (contrato de relaciones) | ✅ 4/4 | ✅ 4 escenarios (relaciones, set null, masterBancaIds, banca.master) | ✅ Pint limpio |

## Test Summary

- **Total tests escritos**: 9 (5 backfill + 4 modelo)
- **Total tests pasando**: 9
- **Layers**: Unit con RefreshDatabase (sqlite/MySQL dev)
- **Approval tests**: None — no refactoring tasks
- **Pure functions creadas**: `AgenciasBackfill::generarCodeUnico()` (única lógica pura extraída)

## Commits del slice (rama `feat/jerarquia-agencias-f0`, base `feature/jerarquia-agencias-locales`)

- `1eaf3ca` feat(migrations): tabla agencias y columnas agencia_id/master_id aditivas
- `83b5cdb` feat(models): modelo Agencia y relaciones agencia/master con factory
- `609ec9b` feat(seeders): rol agencia en columna y Spatie + usuario agencia demo
- `e6e8eda` feat(command): backfill de agencias idempotente (1 local por grupo)

## Evidencia de verificación

- `php artisan test --filter=AgenciasBackfillTest`: `{"tool":"phpunit","result":"passed","tests":5,"passed":5,"assertions":28}`
- `php artisan test --filter=AgenciaModelTest`: `{"tool":"phpunit","result":"passed","tests":4,"passed":4,"assertions":14}`
- `composer test` (suite completa): `{"tool":"phpunit","result":"passed","tests":227,"passed":225,"assertions":923,"skipped":2}` — baseline previo: 222 tests/220 passed/2 skipped (5 nuevos tests, 0 rotos)
- `./vendor/bin/pint --test` sobre los 16 archivos PHP tocados: `{"tool":"pint","result":"passed"}`
- Migraciones en MySQL dev (lotto_db): up `migrate` → columnas presentes (agencias 15 cols; taquillas/users agencia_id; bancas master_id) → `migrate:rollback --step=4` → columnas/tabla eliminadas → `migrate` → restauradas.
- Backfill en MySQL dev (APP_ENV=local, NO producción): 1ª ejecución "0 agencias creadas (grupo ya tenía local del seeder), 2 taquillas asignadas, 2 usuarios asignados"; 2ª ejecución "0 asignadas (2 ya tenían)"; `--dry-run` no escribe. Estado final: 1 agencia, 0 taquillas/users sin agencia.

## Pendiente de confirmación del cliente (task 1.8 es [CLIENTE])

1. **NO se ejecutó el backfill contra producción.** El comando `php artisan agencias:backfill` se implementó y verificó SOLO en BD de desarrollo local. En producción exige `--force` (protegido).
2. Después del backfill en producción, el cliente debe revisar: renombrar/crear los locals reales (los nombres `{grupo} - Local` son provisionales) y registrar si un local necesitará configuración propia (D6, fase posterior). Ver `design.md` Open Questions.

## Workload / PR Boundary

- **Modo**: chained PR slice (feature-branch-chain, `auto-chain`)
- **Current work unit**: PR 1 / F0 — fundación de datos (9 tareas)
- **Boundary**: empieza en `feature/jerarquia-agencias-locales` (tracker) y termina con la verificación F0 completa. NO se implementaron tareas F1–F5 (2.1–6.3).
- **Review budget impact**: 4 commits, ~800 líneas añadidas (115 migraciones + 228 modelos/factories/tests + 31 seeders + 327 backfill/tests). Dentro de lo razonable para PR 1.
- **Rollback boundary**: `php artisan migrate:rollback --step=4` revierte el esquema (todo aditivo/nullable); revertir los 4 commits de la rama elimina el resto sin tocar trabajo ajeno. Backfill reversible (`agencia_id`/`master_id` a null) y re-ejecutable.

## Deviations from Design

- **Ninguna funcional.** Detalles menores: `AgenciaFactory` usa code `AG###` (el diseño decía `A###`; se usó prefijo `AG` para evitar colisiones con otros prefijos del repo como `BC`/`GP`/`T`); la verificación de migraciones se hizo contra MySQL dev (la suite usa sqlite `:memory:` por defecto en phpunit, pero el CI/local usa MySQL `lotto_db` con `RefreshDatabase`).
- El backfill de `users.agencia_id` usa la cadena rol taquilla → su taquilla → agencia de su grupo (como especifica el diseño), no `users.agencia_id` por grupo directo.

## Issues Found

- Ninguno bloqueante. Nota: `backend/.env.example` estaba modificado en el working tree antes de este slice (contiene credenciales de dev) — NO se commiteó; queda fuera del alcance de la rama.
- La suite usa MySQL dev `lotto_db` (phpunit `force="false"` + `.env`), no sqlite, en el entorno local real; `RefreshDatabase` la limpia en cada corrida (por eso el dev DB quedó vacío y hubo que re-seedear para la verificación 1.9).

## Gotchas

- `Schema::hasTable('juegos')` en `ScheduleServiceProvider` falla al bootear con sqlite en archivo vacío (`/tmp/opencode/backfill_verify.sqlite`) → la verificación de migraciones se hizo contra MySQL dev, no sqlite.
- `php artisan db:table` requiere la extensión `intl` (no instalada) → verificar esquema vía `Schema::getColumnListing`.
- `assertDatabaseCount` no acepta cláusula where → usar `Model::where(...)->count()`.

## Próximo paso (orquestador)

- PR 2 (F1 — alcance agencia): login/policies/controllers/activación, base `feat/jerarquia-agencias-f0`. Tareas 2.1–2.10 de `tasks.md`.