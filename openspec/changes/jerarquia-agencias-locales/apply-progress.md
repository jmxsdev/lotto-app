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

---

# Apply Progress: jerarquia-agencias-locales — PR 2 (F1, alcance agencia)

## Estado de tareas (Fase 2 — F1)

| Tarea | Estado | Evidencia |
|---|---|---|
| 2.1 TDD-RED `AgenciaApiTest` + `AgenciaScopeTest` | ✅ | RED inicial: 39 fallos (rutas 404, mensajes antiguos, scope 403) |
| 2.2 Login agencia + VerifyMac | ✅ | X-Panel admite `agencia`; taquilla → 403 "Las taquillas deben usar la app de escritorio."; payload `user.agencia_id`; `mensajeCadenaInactiva` rama agencia; VerifyMac mensajes "agencia"→"taquilla" + causa `agencia` |
| 2.3 ApuestaPolicy | ✅ | `viewAny`/`view`/`delete` rama agencia vía `taquilla.agencia_id` |
| 2.4 TaquillaController | ✅ | `store` deriva `agencia_id`/`grupo_id` de la agencia (valores ajenos → 403); `index` rama agencia; `authorizeAgenciaAccess` nueva |
| 2.5 UserController | ✅ | `agencia_id` en validación/filtros, `validateRoleBindings` (agencia exige agencia_id), `deriveEntityBindings` (taquilla→agencia→grupo→banca), `authorizeEntityBinding`/`authorizeUserAccess` rama agencia |
| 2.6 Apuesta/Cierre/Juego::limites | ✅ | Rama agencia en `index`/`historial`/`resumen` (ApuestaController), `index`/`authorizeCierreAccess`/`resolveTaquillaParaCierre` (CierreController), `limites` GET + `listarLimites` scope (JuegoController) |
| 2.7 Reporte/Estadistica buildApuestaQuery | ✅ | Rama agencia en `buildApuestaQuery` (ambos) y `buildTicketQuery` (ReporteController) |
| 2.8 ActivacionEfectivaService | ✅ | `estadoTaquilla` inserta causa `'agencia'` entre flag propio y grupo; `ActivacionEntidadesTest` extendido (local inactivo pausa taquillas sin cascada) |
| 2.9 Rutas + AgenciaController | ✅ | `role:agencia` en users/taquillas/agencias/cierre/limites GET/apuestas/reportes/estadísticas; `apiResource('agencias')` + toggle; CRUD scoped (agencia solo lectura de su local) |
| 2.10 TDD-GREEN regresión | ✅ | TerminologiaTest (mensajes), RoleAuthorizationTest (agencia), GestionEntidadesApiTest (filtro `agencia_id`), LimitesScopedApiTest (agencia modo entidad), ActivacionEntidadesTest (causa agencia) |

## TDD Cycle Evidence

| Tarea | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|---|---|---|---|---|---|---|---|
| 2.1/2.9 CRUD agencias | `tests/Feature/AgenciaApiTest.php` | Integration | ✅ 231/229 | ✅ 11 fallos (404 ruta inexistente) | ✅ 11/11 | ✅ 11 escenarios (CRUD scoped, code único, toggle, destroy set null, banca/grupo/agencia alcance) | ✅ Pint limpio |
| 2.1/2.2/2.3/2.4/2.5/2.6/2.7/2.8 Scope agencia | `tests/Feature/AgenciaScopeTest.php` | Integration | ✅ 231/229 | ✅ 20 fallos (403 rutas, mensajes antiguos) | ✅ 20/20 | ✅ 20 escenarios (login, cadena, apuestas, taquillas, usuarios, cierres, límites, reportes) | ✅ Pint limpio |
| 2.2/2.8 Mensajes + cadena local | `tests/Feature/ActivacionEntidadesTest.php` | Integration | ✅ 231/229 | ✅ 5 fallos (mensajes VerifyMac + causa agencia) | ✅ 16/16 | ✅ local inactivo + sin cascada + mensajes | ✅ Pint limpio |
| 2.2/2.10 Terminología | `tests/Feature/TerminologiaTest.php` | Integration | ✅ 231/229 | ✅ 6 fallos (mensajes antiguos) | ✅ 10/10 | ✅ 6 mensajes actualizados (login/activación/controllers) | ✅ Pint limpio |
| 2.10 Rol agencia | `tests/Feature/RoleAuthorizationTest.php` | Integration | ✅ 231/229 | N/A (complementa; rutas ya activas) | ✅ 3/3 | ✅ taquillas scoped, sin bancas/grupos, sin límites | ✅ Pint limpio |
| 2.10 Filtro agencia_id | `tests/Feature/GestionEntidadesApiTest.php` | Integration | ✅ 231/229 | N/A (filtro ya implementado) | ✅ 2/2 | ✅ filtro + validación 422 | ✅ Pint limpio |
| 2.10 Límites agencia | `tests/Feature/LimitesScopedApiTest.php` | Integration | ✅ 231/229 | N/A (rama ya implementada) | ✅ 2/2 | ✅ modo entidad propio + taquilla ajena vacía | ✅ Pint limpio |

## Test Summary

- **Total tests escritos**: 42 nuevos (20 AgenciaScopeTest + 11 AgenciaApiTest + 3 RoleAuthorizationTest + 2 GestionEntidadesApiTest + 2 LimitesScopedApiTest + 4 ActivacionEntidadesTest)
- **Total tests pasando**: suite completa 273 tests / 271 passed / 2 skipped (baseline PR1: 231/229 → +42 tests, 0 rotos)
- **Layers**: Integration (Feature) con RefreshDatabase
- **Approval tests**: None — los mensajes se actualizaron con la nueva semántica (agencia=local, taquilla=máquina), no se preservó la antigua
- **Pure functions creadas**: None — la rama agencia es lógica de query/scope en controladores

## Commits del slice (rama `feat/jerarquia-agencias-f1`, base `feat/jerarquia-agencias-f0`)

- `d09f998` feat(auth): rol agencia en login X-Panel, cadena de activación con local y mensajes taquilla
- `f23591a` feat(scope): rama agencia en taquillas, usuarios, cierres, límites, reportes y CRUD de agencias
- `faba3ef` test(regresion): semántica agencia≡local en suite (rol agencia, filtros y límites)

## Evidencia de verificación

- `php artisan test --filter=AgenciaScopeTest`: `{"tool":"phpunit","result":"passed","tests":20,"passed":20}` (RED inicial: 20 fallos)
- `php artisan test --filter=AgenciaApiTest`: `{"tool":"phpunit","result":"passed","tests":11,"passed":11}` (RED inicial: 11 fallos 404)
- `composer test` (suite completa): `{"tool":"phpunit","result":"passed","tests":273,"passed":271,"assertions":1062,"skipped":2}` — baseline PR1: 231 tests/229 passed (42 tests nuevos, 0 rotos)
- `./vendor/bin/pint --test`: `{"tool":"pint","result":"passed"}` (4 archivos auto-fixeados: AgenciaController, AgenciaScopeTest, AgenciaApiTest, ActivacionEntidadesTest)

## Workload / PR Boundary

- **Modo**: chained PR slice (feature-branch-chain, `auto-chain`) — PR 2 → PR 1 (`feat/jerarquia-agencias-f0`)
- **Current work unit**: PR 2 / F1 — alcance agencia (10 tareas)
- **Boundary**: empieza en `feat/jerarquia-agencias-f0` y termina con la verificación F1 completa. NO se implementaron tareas F2–F5 (3.1–6.3).
- **Review budget impact**: 3 commits, ~605 líneas añadidas + 3 archivos nuevos (~500 líneas de tests). Excede el presupuesto de 400 líneas de un PR simple, por lo que el ciclo ya previó chained PRs (PR 2 de 6); el diff de PR 2 vs PR 1 es solo el trabajo F1.
- **Rollback boundary**: revertir los 3 commits de la rama `feat/jerarquia-agencias-f1` elimina el alcance agencia sin tocar F0 (migraciones/backfill intactos). Rutas sin `agencia` + mensajes revertidos devuelven el comportamiento previo.

## Deviations from Design

1. **AgenciaController::destroy desvincula explícitamente**: el diseño decía "agencia_id en taquillas/users queda null vía FK set null", pero el soft delete (`SoftDeletes`) no dispara la FK `ON DELETE SET NULL` (hace UPDATE de `deleted_at`). Se desvincula explícitamente con `taquillas()->update(['agencia_id' => null])` y `users()->update(...)` antes del delete. Cumple el escenario de spec "Borrado sin cascada destructiva".
2. **`JuegoController::limites` (GET /limites/{juego})**: la rama agencia restringe las filas a nivel banca solo a filas banca-level (`whereNull grupo/taquilla`) para no filtrar filas de taquillas de otros locales de la misma banca (el diseño decía "filas banca_id de su banca"; se interpretó como filas de nivel banca, no cualquier fila con ese banca_id).
3. **`listarLimites` (GET /limites)**: se añadió `agencia` al role check y ramas en `entidadDentroDelAlcance`/`entidadesVisiblesPorTipo`/`expandirTipoAlcance` para que el agencia pueda consultar su taquilla en modo entidad y scope (necesario para `LimitesScopedApiTest`). NO se soporta `agencia_id` como filtro de entidad en modo entidad (fuera de diseño; requeriría `filasDeEntidad('agencia')`).
4. **PagoController sin cambios**: el diseño no modifica PagoController y las rutas `/pagos` no incluyen `agencia` (el rol agencia no accede a pagos; el permiso `create_pago` del seeder queda sin ruta, como en F0).
5. **TicketController**: solo cambió el mensaje de `store` ("Solo las taquillas pueden crear tickets."); las rutas `/tickets` no incluyen `agencia` (sin rama de alcance → sin fuga).

## Issues Found

- **Test pre-existente frágil** `LimitesScopedApiTest::test_scope_grupos_con_raiz_banca_solo_esos_grupos`: fallaba en aislamiento (y en ciertos órdenes de suite) porque `assertNotContains($otraBanca->id, $ids)` chocaba numéricamente con el id de un grupo legítimo (ambas tablas comparten contador autoincrement y RefreshDatabase no resetea AUTO_INCREMENT entre tests del mismo proceso). Se endureció: ahora verifica que todas las entidades sean `tipo === 'grupo'` (propiedad real), en lugar de comparar ids numéricos entre tablas distintas.
- `backend/.env.example` y `panel/.astro/settings.json` estaban modificados en el working tree antes de este slice — NO se commitearon (quedan fuera del alcance de la rama).

## Gotchas

- `RefreshDatabase` no resetea `AUTO_INCREMENT` entre tests del mismo proceso PHPUnit: los ids numéricos de distintas tablas pueden colisionar (banca id 2 == grupo id 2). Los tests deben afirmar propiedades reales (tipo de entidad, alcance), no igualdades de ids numéricos entre tablas.
- `JuegoLimite` castea `decimal:2` → los valores llegan como string ("900.00") en `limites` (GET /limites/{juego}); en `listarLimites` pasan por `serializarLimite()` → float. Los tests deben castear según el endpoint.
- `ventasTotales` con `nivel=taquilla` usa la clave `Entidad` (no "Taquilla") para el nombre de la entidad agrupada.
- El soft delete de `Agencia` no dispara la FK `ON DELETE SET NULL` (es un UPDATE); desvincular explícitamente.
- La rama agencia de `mensajeCadenaInactiva` y de `estadoTaquilla` debe considerar `agencia_id` null (binding faltante = activo), siguiendo el patrón existente.

## Próximo paso (orquestador)

- PR 3 (F2 — super banca): `whereIn banca_id` master en ~10 puntos, base `feat/jerarquia-agencias-f1`. Tareas 3.1–3.4 de `tasks.md`.