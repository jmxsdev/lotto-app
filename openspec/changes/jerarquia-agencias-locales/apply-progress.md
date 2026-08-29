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

---

# Apply Progress: jerarquia-agencias-locales — PR 3 (F2, super banca / master scope)

## Estado de tareas (Fase 3 — F2)

| Tarea | Estado | Evidencia |
|---|---|---|
| 3.1 TDD-RED `SuperBancaScopeTest` | ✅ | RED inicial: 10 fallos + 1 error (master aún global: veía bancas/grupos/taquillas/usuarios/apuestas/cierres/límites/reportes/estadísticas ajenas; master sin bancas veía todo) |
| 3.2 Scope master en ~23 puntos | ✅ | Helpers `User::masterBancaScope`/`masterBancaChainScope`/`masterBancaGroupScope`/`masterCanAccessBanca`; rama master en BancaController (index/show/update/toggle/destroy/store auto-master_id), GrupoController (index + authorizeBancaAccess/authorizeGrupoAccess), TaquillaController (index + 3 authorize), ReporteController (buildApuestaQuery/buildTicketQuery), EstadisticaController (buildApuestaQuery), ApuestaController (index/historial/resumen), CierreController (index/resolveTaquillaParaCierre/authorizeCierreAccess), JuegoController (limites/listarLimites/entidadDentroDelAlcance/entidadesVisiblesPorTipo/expandirTipoAlcance/authorizeBancaLimitAccess/destroyLimite), UserController (index con whereIn por cadena + authorizeEntityBinding/authorizeUserAccess). Lista vacía ⇒ `whereRaw('1=0')` en todos |
| 3.3 TDD-GREEN regresión | ✅ | `php artisan test --filter=SuperBancaScopeTest`: 12/12 (RED 10 fallos + 1 error). Suite completa 285/283/2 skipped (baseline PR2: 273/271 → +12 tests, 0 rotos). Actualizados: RoleAuthorizationTest, LimitesApiTest, LimitesScopedApiTest, GestionUsuariosTest, CierreCajaTest, ApuestaTest, ActivacionEntidadesTest |
| 3.4 Panel | ✅ | `bancas/detalle.astro`: select "Master (super banca)" (carga usuarios rol master vía /users, envía master_id); `bancas.astro`: columna Master con `banca.master?.name`. `npm run build`: 20 páginas OK |

## TDD Cycle Evidence

| Tarea | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|---|---|---|---|---|---|---|---|
| 3.1/3.2/3.3 Scope master | `tests/Feature/SuperBancaScopeTest.php` | Integration (Feature) | ✅ 273/271 | ✅ 10 fallos + 1 error (master global) | ✅ 12/12 | ✅ 12 escenarios (bancas, grupos, taquillas, usuarios, apuestas, cierres, límites, reportes, estadísticas, sin-bancas-vacío, banca ajena 403, super global, regresión banca) | ✅ Pint limpio |

## Test Summary

- **Total tests escritos**: 12 (SuperBancaScopeTest)
- **Total tests pasando**: suite completa `composer test` → 285 tests / 283 passed / 2 skipped / 1114 assertions (baseline PR2: 273/271 → +12 tests, 0 rotos)
- **Layers**: Integration (Feature) con RefreshDatabase
- **Approval tests**: None — los tests existentes que asumían master global se actualizaron a la nueva semántica (master administra sus bancas), no se preservó la antigua
- **Pure functions creadas**: None — helpers de scope (closures) en `User`

## Commits del slice (rama `feat/jerarquia-agencias-f2`, base `feat/jerarquia-agencias-f1`)

- `bf024e7` feat(scope): master acotado a sus bancas en entidades, reportes, límites, apuestas y cierres
- `82094ea` test(regresion): suite adaptada a master scoped (rol master ya no es global)
- `33bc13d` feat(panel): select master en banca y columna master en el listado

## Evidencia de verificación

- `php artisan test --filter=SuperBancaScopeTest`: RED inicial 10 fallos + 1 error (SQL duplicado en límites por índice único) → GREEN `{"tool":"phpunit","result":"passed","tests":12,"passed":12,"assertions":46}`
- `composer test` (suite completa): `{"tool":"phpunit","result":"passed","tests":285,"passed":283,"assertions":1114,"skipped":2}` — baseline PR2: 273/271 (12 tests nuevos, 0 rotos)
- `./vendor/bin/pint --test`: `{"tool":"pint","result":"passed"}` (1 archivo auto-fixeado: SuperBancaScopeTest EOF)
- `npm run build` (panel): 20 páginas construidas OK

## Workload / PR Boundary

- **Modo**: chained PR slice (feature-branch-chain, `auto-chain`) — PR 3 → PR 2 (`feat/jerarquia-agencias-f1`)
- **Current work unit**: PR 3 / F2 — super banca (4 tareas, 3 commits)
- **Boundary**: empieza en `feat/jerarquia-agencias-f1` y termina con la verificación F2 completa. NO se implementaron tareas F3–F5 (4.1–6.3).
- **Review budget impact**: 3 commits, ~800 líneas (712 + 68 + 22). Excede 400 líneas de un PR simple; el ciclo ya previó chained PRs (PR 3 de 6); el diff de PR 3 vs PR 2 es solo el trabajo F2.
- **Rollback boundary**: revertir los 3 commits de la rama `feat/jerarquia-agencias-f2` elimina el scope master sin tocar F0/F1 (migraciones/backfill/scope agencia intactos). El master vuelve a ser global al quitar `whereIn`.

## Deviations from Design

1. **JuegoController::destroyLimite y authorizeBancaLimitAccess**: el design menciona "JuegoController::limites/listarLimites" como puntos; también se acotaron las ESCRITURAS de límites (`updateLimites` vía `authorizeBancaLimitAccess`, `batchLimites` vía expandir*, `destroyLimite`) para que un master no pueda escribir/borrar límites de bancas ajenas — misma regla "master solo sus bancas" aplicada a escritura (el design no la excluía; sin esto habría fuga de escritura).
2. **BancaController::store auto-asigna master_id**: cuando el creador es rol master, la banca nueva queda `master_id = creador` (coherente con el backfill F0 "master_id = created_by"). Sin esto, un master que crea una banca no podría verla después (violaría la regla F2).
3. **UserController::authorizeUserAccess master**: antes verificaba `targetUser->banca_id != currentUser->banca_id`; ahora resuelve la banca efectiva del objetivo por cadena (`resolveBancaId`) y la compara contra `masterBancaIds()`. Más correcto: cubre usuarios de agencias/taquillas cuyo banca_id directo puede ser null.
4. **UserController::index master**: se usó el patrón del rol banca (whereIn banca_id + orWhereHas taquilla→grupo) en vez del `where('banca_id', user->banca_id)` previo, porque un master administra N bancas, no una sola.

## Issues Found

- Ninguno bloqueante. Los 31 fallos iniciales tras el cambio eran tests que asumían master global (el design los fija como "actualizar"); se actualizaron 7 archivos de tests.
- `backend/.env.example` y `panel/.astro/settings.json` seguían modificados en el working tree (pre-existentes) — NO se commitearon (fuera del alcance de la rama).

## Gotchas

- `JuegoLimite` tiene índice único `idx_jl_entity_level (juego_id, moneda, banca_id, grupo_id, taquilla_id)` con `4294967295` para NULL en MySQL: no se puede crear 2 filas iguales en el mismo nivel para el mismo juego+moneda (el test RED original chocó contra esto).
- `Banca` filtra por columna `id` (no `banca_id`): `masterBancaScope('id')` para BancaController::index.
- `Taquilla` no tiene relación `taquilla`: usar `masterBancaGroupScope()` (primer eslabón grupo→banca), no `masterBancaChainScope()`.
- `ApuestaController::index` envuelve el paginator en `['data' => ...]`: los items están en `json('data.data')`, no en `json('data')` (a diferencia de CierreController que devuelve el paginator directo).
- El resumen estadístico de `ApuestaController::index` usaba `Apuesta::query()` (global) cuando no había filtros: ahora siempre usa `clone $query` (scoped), cerrando una fuga de datos para master sin filtros.

## Próximo paso (orquestador)

- PR 4 (F3 — reportes por local): `ApuestaService` niveles/labels, base `feat/jerarquia-agencias-f2`. Tareas 4.1–4.3 de `tasks.md`.
---

# Apply Progress: jerarquia-agencias-locales — PR 4 (F3, reportes por local)

## Estado de tareas (Fase 4 — F3)

| Tarea | Estado | Evidencia |
|---|---|---|
| 4.1 TDD-RED `CuadreCajaReportTest` + `ReporteTest` | ✅ | RED inicial: 11 fallos + 1 error (nivel=agencia caía a banca/máquina; falta nivel=taquilla en cuadre; labels viejos) |
| 4.2 `ApuestaService` + `ReporteController` | ✅ | `ventasTotales`/`cuadreCaja` nivel `agencia` = join `taquillas→agencias→grupos→bancas` + groupBy `agencias` (label `agencias.name`) + nivel `taquilla` (máquinas); `pagosCuadrePorNivel` join agencias condicional (`in_array('agencias.id', $groupCols)`); `rendimientoTaquillas` acepta `nivel` (D7: `taquilla` default → clave `Taquilla`, `agencia` → clave `Agencia`); `relacionTickets`/`vencidos`: `Agencia`=local (`taquilla.agencia.name`), nueva clave `Taquilla`=máquina; controller: filtro `nivel` en rendimiento + comentarios |
| 4.3 TDD-GREEN `EstadisticaTest` | ✅ | `test_time_series_agencia_solo_su_local` (alcance por local vía `buildApuestaQuery` F1; ya verde al escribirlo — guard de consistencia). Suite completa 293/291/2skipped (baseline PR3: 285/283 → +8, 0 rotos) |

## TDD Cycle Evidence

| Tarea | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|---|---|---|---|---|---|---|---|
| 4.1/4.2 Cuadre por local | `tests/Feature/CuadreCajaReportTest.php` | Integration | ✅ 50/50 (5 archivos reportes) | ✅ 4 fallos (agencia→máquina, falta nivel=taquilla, suma por local) | ✅ 15/15 | ✅ 4 escenarios (2 locals, grupo solo sus locals, nivel=taquilla máquinas, suma máquinas del mismo local) | ✅ Pint limpio |
| 4.1/4.2 VentasTotales por local | `tests/Feature/ReporteTest.php` | Integration | ✅ 50/50 | ✅ 2 fallos (agencia→banca 1 fila, clave rendimiento) | ✅ 10/10 | ✅ 5 escenarios (agencia 2 locals, taquilla 2 máquinas, rendimiento agencia, rendimiento taquilla clave, taquilla scope) | ✅ Pint limpio |
| 4.1/4.2 Labels Agencia/Taquilla | `tests/Feature/TerminologiaTest.php` | Integration | ✅ 50/50 | ✅ 4 fallos (clave Taquilla ausente, Agencia=máquina) | ✅ 12/12 | ✅ 4 escenarios (rendimiento máquina, rendimiento local, relacion-tickets, vencidos) | ✅ Pint limpio |
| 4.3 Serie temporal agencia | `tests/Feature/EstadisticaTest.php` | Integration | ✅ 50/50 | ➖ N/A (guard de consistencia: alcance por local ya impuesto por buildApuestaQuery F1; verde al escribirlo) | ✅ 3/3 | ✅ 1 escenario (agencia solo su local, local ajeno excluido) | ✅ Pint limpio |

## Test Summary

- **Total tests escritos**: 8 nuevos (4 ReporteTest + 2 CuadreCajaReportTest + 1 TerminologiaTest + 1 EstadisticaTest) + 6 actualizados con la nueva semántica (1.5.6, 1.5.8, 4.8, 3 claves R3)
- **Total tests pasando**: suite completa `composer test` → 293 tests / 291 passed / 2 skipped / 1158 assertions (baseline PR3: 285/283 → +8 tests, 0 rotos)
- **Layers**: Integration (Feature) con RefreshDatabase (MySQL dev lotto_db)
- **Approval tests**: None — los tests que fijaban `agencia`≡taquilla se actualizaron a la nueva semántica (Agencia=local, Taquilla=máquina), no se preservó la antigua
- **Pure functions creadas**: None — lógica de query/agrupación en el servicio

## Commits del slice (rama `feat/jerarquia-agencias-f3`, base `feat/jerarquia-agencias-f2`)

- `feat(reportes): nivel agencia agrupa por local en ventas, cuadre y rendimiento; labels Agencia=local, Taquilla=máquina`
- `docs(sdd): progreso de apply PR 4 (F3) y tareas 4.1-4.3 completadas`

## Evidencia de verificación

- RED: `php artisan test --filter="CuadreCajaReportTest|ReporteTest|TerminologiaTest|EstadisticaTest"` → 11 fallos + 1 error (undefined key "Taquilla")
- GREEN: mismo filtro → `{"tool":"phpunit","result":"passed","tests":58,"passed":58,"assertions":305}` (50 baseline + 8 nuevos)
- `composer test` (suite completa): `{"tool":"phpunit","result":"passed","tests":293,"passed":291,"assertions":1158,"skipped":2}` — baseline PR3: 285/283 (8 tests nuevos, 0 rotos)
- `./vendor/bin/pint --test`: `{"tool":"pint","result":"passed"}` (1 archivo auto-fixeado: ApuestaService — single_quote/unary_operator_spaces)
- Runtime harness: los Feature tests ejercitan los endpoints HTTP reales (`/reportes/ventas-totales?nivel=agencia`, `/reportes/cuadre-caja?nivel=agencia|taquilla`, `/reportes/rendimiento-taquillas?nivel=agencia`, `/reportes/relacion-tickets`, `/reportes/vencidos`, `/estadisticas/rendimiento`) con middleware + rutas + BD MySQL; N/A harness manual separado (la capa Feature ES el camino HTTP completo)

## Workload / PR Boundary

- **Modo**: chained PR slice (feature-branch-chain, `auto-chain`) — PR 4 → PR 3 (`feat/jerarquia-agencias-f2`)
- **Current work unit**: PR 4 / F3 — reportes por local (3 tareas, 2 commits)
- **Boundary**: empieza en `feat/jerarquia-agencias-f2` y termina con la verificación F3 completa. NO se implementaron tareas F4–F5 (5.1–6.3).
- **Review budget impact**: 2 commits, ~530 líneas (527 + docs). Excede 400 líneas de un PR simple; el ciclo ya previó chained PRs (PR 4 de 6); el diff de PR 4 vs PR 3 es solo el trabajo F3.
- **Rollback boundary**: revertir los commits de la rama `feat/jerarquia-agencias-f3` elimina el reporte por local sin tocar F0–F2 (migraciones/backfill/scope agencia/master intactos). `nivel=agencia` vuelve a caer a banca/taquilla y los labels vuelven a la semántica vieja.

## Deviations from Design

- **Ninguna funcional.** Detalles: las máquinas SIN local (`taquillas.agencia_id` null) quedan fuera del reporte `nivel=agencia` (join interno `agencias`), coherente con el backfill F0 que asigna local a todas las taquillas; los tests del cuadre/grupo ahora crean locales explícitos con `Agencia::factory()`.
- `rendimientoTaquillas` (D7): el nivel `taquilla` (default) etiqueta la fila con la clave `Taquilla` (antes `Agencia`); el nivel `agencia` usa la clave `Agencia` con el nombre del local — el panel "Rendimiento por Agencia" (`reportes/taquillas.astro`) seguirá leyendo `row.Agencia` cuando F4 lo apunte a `nivel=agencia`.

## Issues Found

- Ninguno bloqueante. El panel (F4) aún envía `nivel=taquilla` etiquetado "Agencia" en `reportes/ventas.astro` y lee `row.Agencia` en `reportes/taquillas.astro`: con el backend F3 el nivel `taquilla` ahora agrupa máquinas con clave `Taquilla`, por lo que el panel mostrará la columna vacía hasta que F4 adapte labels y nivel (ruptura esperada y acotada al ciclo chained; F4 y F5 cierran).
- `backend/.env.example` y `panel/.astro/settings.json` seguían modificados en el working tree (pre-existentes) — NO se commitearon (fuera del alcance de la rama).

## Gotchas

- El bug latente: `ventasTotales`/`cuadreCaja` con `nivel=agencia` caían al `default` del `match` (banca) o agrupaban por `taquillas` — la rama `agencia` ahora agrupa por `agencias` con join.
- `cuadreCaja` no soportaba `nivel=taquilla` (caía a banca): se añadió explícitamente (regresión cubierta por 1.5.9).
- Los pagos del cuadre (`pagosCuadrePorNivel`) necesitan el join a `agencias` SOLO cuando el nivel es agencia; se infiere de `$groupCols` (`in_array('agencias.id', ...)`) para no multiplicar filas en otros niveles.
- `rendimientoTaquillas` ahora une `taquillas` siempre (necesario para la cadena agencias) y resuelve entidades desde `$ventasPorEntidad->keys()`; la variable `$taquillaIds` quedó muerta y se eliminó.

## Próximo paso (orquestador)

- PR 5 (F4 — panel): login/ROLES +agencia, sidebar "Agencias", CRUD locals, select agencia, niveles local vs máquina en reportes/cuadre/rendimiento, base `feat/jerarquia-agencias-f3`. Tareas 5.1–5.5 de `tasks.md`.

---

# Apply Progress: jerarquia-agencias-locales — PR 5 (F4, panel)

## Estado de tareas (Fase 5 — F4)

| Tarea | Estado | Evidencia |
|---|---|---|
| 5.1 Login/ROLES +agencia | ✅ | `api.ts` ROLES +`agencia`; `login.astro` ROLES_PERMITIDOS +`agencia`; mensaje de máquinas → "Las taquillas deben usar la app de escritorio." |
| 5.2 Sidebar Taquillas/Agencias | ✅ | `AdminLayout.astro`: "Taquillas"→`/taquillas` (🖥️, roles super/master/banca/grupo/agencia) y nueva "Agencias"→`/agencias` (🏪, super/master/banca/grupo); rol agencia ve taquillas/cuadre/reportes sin entidades superiores; count de agencias en sidebar |
| 5.3 CRUD de agencias | ✅ | `agencias.astro` (listado con grupo/banca/estado + toggle + delete) y `agencias/detalle.astro` (crear/editar local, asignar grupo, fiscal, activar/desactivar, eliminar desvinculando, pestaña con sus taquillas y "+ Nueva taquilla" prefijando el local) |
| 5.4 Selectores y formularios | ✅ | `usuarios.astro` (rol agencia + select de local); `taquillas.astro` (renombrada, columna Local); `taquillas/detalle.astro` (select Local con agencia_id — requirió backend); `grupos/detalle.astro` (pestaña Locales + crear usuario rol agencia); `dashboard.astro` (stats Agencias/Taquillas separadas, tolerantes a roles sin entidades superiores); `bancas.astro`/`bancas/detalle.astro`/`grupos.astro` (labels de rol y mensajes coherentes) |
| 5.5 Verificación | ✅ | `npm run build` 22 páginas OK; backend `php artisan test` 296/294/2 (baseline PR4: 293/291 → +3 tests, 0 rotos); `pint --test` limpio |

## Cambio de backend incluido en F4 (requerido por 5.4)

El detalle de taquilla "permite asignar el LOCAL (agencia_id)" pero `TaquillaController::update` descartaba `agencia_id` (solo `$request->only(...)` sin esa clave). Se añadió con TDD estricto:

- `TaquillaController::update`: valida `agencia_id` nullable exists, llama `authorizeAgenciaAccess` (misma autorización jerárquica que el store F1: el rol agencia solo su local → 403 en local ajeno) y lo incluye en `$data` (`null` explícito desasigna).
- Nuevo `tests/Feature/TaquillaAgenciaIdTest.php` (3 casos).

## TDD Cycle Evidence

| Tarea | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|---|---|---|---|---|---|---|---|
| 5.4 (backend) asignar local en update | `tests/Feature/TaquillaAgenciaIdTest.php` | Integration (Feature) | ✅ 293/291 | ✅ 3 fallos (agencia_id ignorado; 403 ausente) | ✅ 3/3 | ✅ 3 escenarios (super asigna null→local; agencia su local; agencia local ajeno 403) | ✅ Pint limpio |
| 5.1–5.4 (panel) | — (sin suite e2e) | — | ✅ build 20 páginas | N/A (panel sin test runner; verificación = `npm run build`) | ✅ build 22 páginas | N/A (build compila las 22 páginas) | ✅ labels y mensajes coherentes |

## Test Summary

- **Total tests escritos**: 3 (TaquillaAgenciaIdTest)
- **Total tests pasando**: suite completa `php artisan test` → 296 tests / 294 passed / 2 skipped / 1165 assertions (baseline PR4: 293/291 → +3 tests, 0 rotos)
- **Layers**: Integration (Feature) con RefreshDatabase
- **Approval tests**: None — los labels/mensajes del panel se actualizaron a la nueva semántica (Agencia=local, Taquilla=máquina)
- **Pure functions creadas**: None — el panel usa mapeos client-side (`Map id→name` para locales)

## Commits del slice (rama `feat/jerarquia-agencias-f4`, base `feat/jerarquia-agencias-f3`)

- `38333e1` feat(taquillas): asignar el local (agencia_id) al actualizar una taquilla
- `ba89e96` feat(panel): acceso del rol agencia al panel y sidebar con Taquillas/Agencias separadas
- `b7a9b9b` feat(panel): CRUD de agencias (locales) con listado y detalle
- `a907397` feat(panel): selectores local vs maquina en usuarios, taquillas, grupo y dashboard
- `7c00dfe` feat(panel): niveles Agencia=local y Taquilla=maquina en reportes, cuadre y limites
- (docs) docs(sdd): progreso de apply PR 5 (F4) y tareas 5.1-5.5 completadas

## Evidencia de verificación

- RED: `php artisan test --filter=TaquillaAgenciaIdTest` → 3 fallos (agencia_id null tras PUT; 200 en vez de 403 para local ajeno)
- GREEN: mismo filtro → `{"tool":"phpunit","result":"passed","tests":3,"passed":3,"assertions":7}`
- Regresión taquillas: `--filter="TaquillaAgenciaIdTest|AgenciaScopeTest|GestionEntidadesApiTest|RoleAuthorizationTest"` → 44/44
- `php artisan test` (suite completa): `{"tool":"phpunit","result":"passed","tests":296,"passed":294,"assertions":1165,"skipped":2}` — baseline PR4: 293/291 (3 tests nuevos, 0 rotos)
- `./vendor/bin/pint --test`: `{"tool":"pint","result":"passed"}` (1 archivo auto-fixeado: TaquillaAgenciaIdTest)
- `npm run build` (panel): 22 páginas construidas OK (baseline PR4: 20 → +2 páginas: /agencias y /agencias/detalle)
- Runtime harness: los Feature tests ejercitan los endpoints HTTP reales (PUT /taquillas/{id} con agencia_id, incluyendo middleware + rutas + BD MySQL); el panel no tiene suite e2e → `npm run build` es el harness de compilación y la coherencia del contrato se validó contra el backend real (rutas `/agencias`, toggle, niveles de reportes F3)

## Workload / PR Boundary

- **Modo**: chained PR slice (feature-branch-chain, `auto-chain`) — PR 5 → PR 4 (`feat/jerarquia-agencias-f3`)
- **Current work unit**: PR 5 / F4 — panel (5 tareas, 6 commits)
- **Boundary**: empieza en `feat/jerarquia-agencias-f3` y termina con la verificación F4 completa. NO se implementaron tareas F5 (6.1–6.3).
- **Review budget impact**: 6 commits, ~790 líneas añadidas (121 backend + tests + ~670 panel). Excede 400 líneas de un PR simple; el ciclo ya previó chained PRs (PR 5 de 6); el diff de PR 5 vs PR 4 es solo el trabajo F4.
- **Rollback boundary**: revertir los commits de la rama `feat/jerarquia-agencias-f4` elimina el panel F4 sin tocar F0–F3. El único cambio de backend (update taquilla con agencia_id) es aditivo y nullable: revertirlo solo impide asignar el local desde el detalle de taquilla (el store F1 y el resto siguen funcionando).

## Deviations from Design

1. **`TaquillaController::update` acepta `agencia_id`**: el design.md no listaba `agencia_id` en el update de taquilla (solo en el store), pero la spec panel-jerarquia exige que el detalle de taquilla "muestra/permite asignar el LOCAL". Se añadió con la misma `authorizeAgenciaAccess` del store (F1) y TDD estricto. Aditivo y nullable: no rompe nada previo.
2. **Bancas/detalle y grupos.astro solo labels**: el detalle de banca mantiene sus roles de usuario (banca/grupo/taquilla) y solo se corrigieron labels (Taquilla=máquina, Agencia=local) y etiquetas de límites; NO se añadió el rol agencia al modal del detalle de banca (el select de local se gestiona en usuarios.astro y grupos/detalle.astro, donde el alcance es claro).
3. **Nombres de locales en listados vía mapeo client-side**: el backend no carga la relación `agencia` en `TaquillaController::index`/`UserController::index`; el panel mapea `agencia_id`→nombre con la lista de `/agencias` (patrón ya usado para bancas derivadas). Sin cambios de backend adicionales.

## Issues Found

- Ninguno bloqueante. El `update` de taquilla con rol agencia requiere que la máquina YA tenga el local asignado (`authorizeTaquillaAccess` compara `agencia_id`): un local no puede asignarse a una máquina sin local (403 correcto de negocio; la asignación inicial la hace un rol superior).
- `backend/.env.example` y `panel/.astro/settings.json` seguían modificados en el working tree (pre-existentes) — NO se commitearon (fuera del alcance de la rama).

## Gotchas

- `authorizeTaquillaAccess` (rama agencia) exige `taquilla.agencia_id === user.agencia_id`: el rol agencia nunca ve máquinas sin local, por lo que "agencia asigna su local" solo aplica a máquinas ya vinculadas.
- `$request->only(...)` en `TaquillaController::update` descartaba silenciosamente `agencia_id`; se añadió explícito con `$request->has('agencia_id')` para permitir también desasignar (`null`).
- El dashboard anterior usaba `Promise.all` sin tolerancia a 403: roles banca/grupo/agencia no ven `/bancas` y todo el bloque fallaba en silencio. Ahora cada stat es tolerante (`.catch(() => '-')`).

## Próximo paso (orquestador)

- PR 6 (F5 — endurecer): `TerminologiaTest` final verde (claves Agencia=local/Taquilla=máquina + mensajes), `composer test` + `./vendor/bin/pint --test`, revisión post-backfill del cliente. Base `feat/jerarquia-agencias-f4`. Tareas 6.1–6.3 de `tasks.md`.

---

# Apply Progress: jerarquia-agencias-locales — PR 6 (F5, endurecimiento)

## Estado de tareas (Fase 6 — F5)

| Tarea | Estado | Evidencia |
|---|---|---|
| 6.1 Cierre de semántica | ✅ | Repaso FINAL: TerminologiaTest ampliado de 11 → 17 casos (6 nuevos RED→GREEN); corregidos 6 mensajes residuales donde la MÁQUINA decía "agencia" (moneda no permitida en apuestas/tickets, vigencia y tiempo de la taquilla contra grupo/banca, dispositivo no registrado, grupo con taquillas); docblocks de cierre (CierreController/CierreService) y label de origen en limites.ts (Agencia→Taquilla); comentarios del scope de límites en JuegoController ("agencias de esa banca/grupo"→"taquillas"). Panel revisado: labels Agencia=local/Taquilla=máquina coherentes. App taquilla (Electron) verificada: no menciona "agencia" en ningún mensaje (la app de escritorio ES la máquina) — sin cambios |
| 6.2 Suite completa final verde | ✅ | `composer test` → 302 tests / 300 passed / 2 skipped / 1177 assertions (baseline PR5: 296/294 → +6 tests, 0 rotos); `./vendor/bin/pint --test` → passed; `npm run build` (panel) → 22 páginas OK |
| 6.3 [CLIENTE] Revisión post-backfill | ✅ (checklist documentado; NO ejecutado) | Sección "Despliegue del ciclo completo" a continuación: orden F0→F5, backfill con `--force` desde el VPS, checklist de revisión del cliente y rollback por fase. El backfill NO se ejecutó en producción (exige `--force`; se verificó solo en BD de desarrollo) |

## TDD Cycle Evidence (F5)

| Tarea | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|---|---|---|---|---|---|---|---|
| 6.1 mensajes residuales | `tests/Feature/TerminologiaTest.php` (+6) | Integration (Feature) | ✅ 296/294 | ✅ 6 fallos (mensajes decían "agencia" en contexto de máquina) | ✅ 17/17 | ✅ 6 escenarios (apuesta moneda USD, ticket moneda USD, vigencia taquilla vs grupo, tiempo taquilla vs grupo, dispositivo no registrado, grupo con taquillas) | ✅ Pint limpio |

## Test Summary (F5)

- **Total tests escritos**: 6 nuevos (TerminologiaTest: apuesta moneda, ticket moneda, vigencia, tiempo, dispositivo, grupo destroy)
- **Suite completa**: `composer test` → 302 tests / 300 passed / 2 skipped / 1177 assertions — baseline PR5: 296/294 (+6 tests, 0 rotos)
- **Pint**: `./vendor/bin/pint --test` → passed
- **Panel**: `npm run build` → 22 páginas (sin suite e2e; verificación = build + coherencia de labels/mensajes)

## Commits del slice (rama `feat/jerarquia-agencias-f5`, base `feat/jerarquia-agencias-f4`)

- `6dc0c66` fix(backend,panel): terminología taquilla=máquina en docblocks de cierre y label de origen
- `ea21413` fix(backend): mensajes residuales usan taquilla=máquina en lugar de agencia
- (docs) docs(sdd): progreso de apply PR 6 (F5) y tareas 6.1-6.3 completadas

## Evidencia de verificación

- RED: `php artisan test --filter=TerminologiaTest` → 6 fallos (mensajes "agencia" en contexto de máquina)
- GREEN: mismo filtro → `{"tool":"phpunit","result":"passed","tests":17,"passed":17,"assertions":52}`
- `composer test` (suite completa, COMPOSER_PROCESS_TIMEOUT=900): `{"tool":"phpunit","result":"passed","tests":302,"passed":300,"assertions":1177,"skipped":2}` — baseline PR5: 296/294 (6 tests nuevos, 0 rotos)
- `./vendor/bin/pint --test`: `{"tool":"pint","result":"passed"}`
- `npm run build` (panel): 22 páginas construidas OK
- Runtime harness: los Feature tests ejercitan los endpoints HTTP reales (POST /apuestas y /tickets con moneda deshabilitada → 422, POST /taquillas con vigencia/tiempo excedidos → 422, POST /dispositivo/verificar, DELETE /grupos/{id} con taquillas → 422); panel sin suite e2e → `npm run build` es el harness de compilación

## Workload / PR Boundary

- **Modo**: chained PR slice (feature-branch-chain, `auto-chain`) — PR 6 → PR 5 (`feat/jerarquia-agencias-f4`)
- **Current work unit**: PR 6 / F5 — endurecimiento (3 tareas, 2 commits de código + 1 docs)
- **Boundary**: empieza en `feat/jerarquia-agencias-f4` y termina con la verificación F5 completa. Es el ÚLTIMO slice del ciclo F0→F5.
- **Review budget impact**: 3 commits, ~150 líneas (145 de código/tests + docs). Dentro del presupuesto de 400 líneas.
- **Rollback boundary**: revertir los 2 commits de código de la rama `feat/jerarquia-agencias-f5` elimina la limpieza de mensajes/labels sin tocar F0–F4 (el backend previo no rompe: los mensajes vuelven a la semántica vieja solo si se revierten los commits). El commit docs es independiente.

## Deviations from Design (F5)

- **Ninguna funcional.** La tarea 6.1 encontró 6 mensajes de error y 1 docblock residuales de la semántica previa (donde "agencia" significaba la máquina) que el design no enumeraba explícitamente: se corrigieron porque la spec panel-jerarquia y el propio TerminologiaTest exigen "agencia=local, taquilla=máquina" en TODOS los mensajes, no solo en los listados.
- `GrupoController::destroy`: el check verifica taquillas (máquinas); el mensaje decía "tiene agencias asociadas" (legado). Ahora dice "tiene taquillas asociadas" (refleja lo que el check realmente verifica).

## Issues Found (F5)

- Ninguno bloqueante. `backend/.env.example` y `panel/.astro/settings.json` seguían modificados en el working tree (pre-existentes, contienen credenciales de dev y timestamp de Astro) — NO se commitearon (fuera del alcance de la rama).

## Gotchas (F5)

- La suite tarda >5 min con el process-timeout default de composer (300s la mata): usar `COMPOSER_PROCESS_TIMEOUT=900 composer test` o `php artisan test` directo (122s).
- `validarVigenciaContraParent`/`validarTiempoEliminacionContraParent` (TaquillaController) y `validarMonedaYLimites` (ApuestaService) se ejecutan en la MÁQUINA: sus mensajes deben hablar de la "taquilla", nunca de la "agencia" (el local no configura monedas/vigencia/tiempo).
- El mensaje de `DispositivoController::verificar` aplica a la MÁQUINA (activación): "Active su taquilla.", no "su agencia".
- Los comentarios del scope de límites en JuegoController llamaban "agencias" a las taquillas de una banca/grupo: se corrigieron a "taquillas" (en el dominio de límites no existe el nivel local, es passthrough).

## Mapeo tareas → specs (cierre del ciclo, 4 specs cubiertas)

| Spec | Fase(s) que la cubren | Requerimientos |
|---|---|---|
| `jerarquia-agencias` | F0 (1.1–1.9) + F1 (2.1–2.10) | Entidad agencia (local), asociación taquilla/usuario a agencia, rol agencia en ambas fuentes, cadena de activación, backfill idempotente, actualización de tests |
| `alcance-super-banca` | F2 (3.1–3.4) | Asociación master↔banca, alcance master en entidades/reportes/estadísticas/apuestas/cierres/límites, login X-Panel admite agencia |
| `reportes-agencia` | F3 (4.1–4.3) + F5 (6.1) | ventasTotales/cuadre/rendimiento por local, semántica de labels (Agencia=local, Taquilla=máquina), sin configuración propia del local, actualización de tests |
| `panel-jerarquia` | F4 (5.1–5.5) + F5 (6.1) | Renames y labels, sidebar por rol, login/payload con agencia_id, creación de taquillas por agencia, selectores y formularios, actualización de tests de terminología |

---

# DESPLIEGUE DEL CICLO COMPLETO (jerarquia-agencias-locales) — checklist para el cliente

> **Estado**: implementación y verificación local COMPLETAS (F0→F5). Nada de esto se ha ejecutado contra producción todavía.

## 1. Orden de despliegue (F0 → F5)

El ciclo se construyó como cadena de 6 slices sobre la rama feature `feat/jerarquia-agencias-f5` (base f4 → f3 → f2 → f1 → f0). El orquestador hace el push del ciclo al final; NO abrir PRs por fase desde este slice.

| Fase | Rama | Contenido | Backend | Panel | Taquilla |
|---|---|---|---|---|---|
| F0 | `feat/jerarquia-agencias-f0` | Migraciones (agencias, agencia_id, master_id), modelos, seeders, comando backfill | ✅ | — | — |
| F1 | `feat/jerarquia-agencias-f1` | Scope agencia (login, policies, controllers, activación) | ✅ | — | — |
| F2 | `feat/jerarquia-agencias-f2` | Super banca (master scope) | ✅ | select master | — |
| F3 | `feat/jerarquia-agencias-f3` | Reportes por local (niveles y labels) | ✅ | — | — |
| F4 | `feat/jerarquia-agencias-f4` | Panel: sidebar/CRUD/selectores/niveles | ✅ (update taquilla agencia_id) | ✅ | — |
| F5 | `feat/jerarquia-agencias-f5` | Endurecimiento: mensajes residuales + suite verde | ✅ | ✅ (label origen) | verificado (sin cambios) |

## 2. Ejecución del backfill en producción (como deploy)

El comando `php artisan agencias:backfill` crea 1 local por grupo y vincula taquillas y usuarios rol taquilla. En producción exige `--force` y registra un log revisable. NO se ejecutó en producción (solo BD de desarrollo).

```bash
# En el VPS, dentro del backend:
php artisan migrate                      # aplica las 4 migraciones aditivas (todo nullable)
php artisan agencias:backfill --dry-run  # OPCIONAL pero recomendado: muestra qué haría
php artisan agencias:backfill --force    # ejecuta: 1 local/grupo + vincula taquillas/usuarios
php artisan agencias:backfill --force    # 2ª ejecución: debe reportar 0 creaciones/0 asignaciones (idempotente)
```

## 3. Checklist de revisión del cliente (después del backfill)

1. **Renombrar los locales provisionales**: el backfill crea `{grupo} - Local` (código `{grupo.code}-L01`). Revisar en el panel (Agencias → editar cada local) y asignar el nombre real del punto de venta, datos fiscales (RIF, email, teléfono, dirección, estado, municipio) y confirmar el grupo correcto.
2. **Verificar `bancas.master_id`**: cada banca debe tener su super banca (master) asignado; revisar en el panel (Bancas → columna Master). El backfill asigna `master_id = created_by` a las bancas existentes si el creador es rol master; de lo contrario asignar manualmente.
3. **Verificar el alcance de los roles**: iniciar sesión en el panel con un usuario rol agencia (debe ver solo su local: taquillas, cuadre, reportes) y con un master (debe ver solo sus bancas). La app taquilla (Electron) no cambió: sus mensajes siguen siendo "taquilla" (máquina).
4. **Registrar si un local necesitará configuración propia** (D6 posterior): en esta iteración la agencia es solo identidad (passthrough — no configura monedas/vigencia/tiempo/límites). Si algún local necesita límites propios distintos de su grupo/banca, anotarlo como requerimiento para la fase D6.

## 4. Rollback por fase

| Fase | Rollback |
|---|---|
| F0 | `php artisan migrate:rollback --step=4` revierte el esquema (todo aditivo/nullable); `taquillas.grupo_id` intacto. Backfill reversible: `agencia_id=null` / `master_id=null`; re-ejecutable, no destructivo |
| F1 | Revertir ramas agencia en controllers/policies/routes (login X-Panel, VerifyMac, ApuestaPolicy, Taquilla/User/Apuesta/Cierre/Juego/Reporte/Estadistica, ActivacionEfectivaService) |
| F2 | Quitar `whereIn` master (vuelve global) + revertir select master en bancas |
| F3 | Revertir niveles/labels en `ApuestaService`/`ReporteController` |
| F4 | Revertir commits panel (backend previo no rompe: todo nullable y aditivo) |
| F5 | Revertir limpieza de mensajes/labels residuales (backend previo no rompe) |

## Próximo paso (orquestador)

- **VERIFICAR**: ejecutar `sdd-verify` con el mapeo tareas→specs anterior (4 specs cubiertas: jerarquia-agencias, alcance-super-banca, reportes-agencia, panel-jerarquia) y el diff completo del ciclo F0→F5.

---

# CORRECCIONES DE AUDITORÍA (work unit del PR 6, rama `feat/jerarquia-agencias-f5`)

> **Estado**: 4/4 correcciones implementadas con TDD estricto RED→GREEN. Suite completa verde.
> **Decisión del cliente**: UNA TAQUILLA NO PUEDE EXISTIR SIN UN LOCAL ASIGNADO (agencia_id obligatorio en API/UI; la columna sigue nullable en BD — el endurecimiento NOT NULL queda para fase posterior con backfill).

## Correcciones implementadas

| # | Severidad | Corrección | Archivos | Tests |
|---|---|---|---|---|
| 1 | CRÍTICO | `AgenciaController` sin scoping para master: `index` separa super_master (global) de master (`masterBancaGroupScope()`, lista vacía ⇒ `whereRaw('1=0')`); `authorizeGrupoAccess` y `authorizeAgenciaAccess` usan `masterCanAccessBanca` (banca del local vía `$agencia->grupo?->banca_id`) → 403 fuera de sus bancas | `AgenciaController.php` | `AgenciaMasterScopeTest.php` (8) |
| 2 | ALTO | Taquilla con `grupo_id` y `agencia_id` inconsistentes: nuevo `validarLocalPerteneceAlGrupo` en store y update (grupo efectivo = enviado o actual de la taquilla) → 422 'El local no pertenece al grupo indicado.' | `TaquillaController.php` | `TaquillaGrupoAgenciaConsistenciaTest.php` (5) |
| 3 | MEDIO | `BancaController` `master_id` sin validar rol: regla de validación (store y update) — si `master_id` viene, el usuario debe tener rol master → 422 'El master seleccionado no tiene el rol master.' | `BancaController.php` | `BancaMasterIdTest.php` (4) |
| 4 | NEGOCIO | Taquilla SIEMPRE con local: store `agencia_id` → `required|exists:agencias,id` (el rol agencia lo deriva de su sesión); update → `sometimes|required|exists:agencias,id` (no se puede desasignar con null; PUT parciales de Monedas/Vigencia siguen válidos). Panel: select Local `required` y sin opción 'Sin local asignado'; el select se refiltra por grupo al cargar | `TaquillaController.php`, `panel/src/pages/taquillas/detalle.astro` | `TaquillaLocalObligatorioTest.php` (4) |

### TDD Cycle Evidence (correcciones)

| Tarea | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|---|---|---|---|---|---|---|---|
| 1 (master scope) | `AgenciaMasterScopeTest.php` | Feature (HTTP) | ✅ 85/85 | ✅ 6 fallos (master global) | ✅ 8/8 | ✅ 8 casos (2 listado + 4 escritura 403 + 1 gestión propia + 1 super global) | ✅ Pint limpio |
| 2 (consistencia grupo/local) | `TaquillaGrupoAgenciaConsistenciaTest.php` | Feature (HTTP) | ✅ 85/85 | ✅ 3 fallos (inconsistencias aceptadas) | ✅ 5/5 | ✅ 5 casos (3 rechazo + 2 parejas consistentes) | ✅ Pint limpio |
| 3 (rol master en banca) | `BancaMasterIdTest.php` | Feature (HTTP) | ✅ 85/85 | ✅ 2 fallos (no-master aceptado) | ✅ 4/4 | ✅ 4 casos (2 rechazo + 2 aceptación) | ✅ Pint limpio |
| 4 (local obligatorio) | `TaquillaLocalObligatorioTest.php` | Feature (HTTP) | ✅ 85/85 | ✅ 2 fallos (sin local / null aceptados) | ✅ 4/4 | ✅ 4 casos (2 rechazo + store válido + PUT parcial) | ✅ Pint limpio |

### Test Summary (correcciones)

- **Total tests escritos**: 21 nuevos (8 + 5 + 4 + 4)
- **Tests existentes actualizados** (creaban taquillas sin local; ahora envían `agencia_id`): `RoleAuthorizationTest`, `TerminologiaTest` (2), `InformacionFiscalTest`, `EliminacionApuestasTest` (2)
- **Suite completa**: `COMPOSER_PROCESS_TIMEOUT=900 composer test` → `{"tool":"phpunit","result":"passed","tests":323,"passed":321,"assertions":1235,"skipped":2}` — baseline PR6: 302/300 (+21 tests, 0 rotos)
- **Pint**: `./vendor/bin/pint --test` → `{"tool":"pint","result":"passed"}`
- **Panel**: `npm run build` → 22 páginas construidas OK

### Commits de las correcciones (rama `feat/jerarquia-agencias-f5`)

- `3f7bb6b` fix(backend): acotar el alcance del master en el CRUD de agencias
- `50b7563` fix(backend): validar consistencia grupo-local al asignar una taquilla
- `c34c0d3` fix(backend): validar que master_id apunte a un usuario con rol master
- `2b43e32` fix(backend,panel): la taquilla siempre requiere un local asignado

### Deviations from Design (correcciones)

1. **Fix 4 en update usa `sometimes|required` en vez de `required` plano**: el PUT parcial de la pestaña Monedas/Vigencia del panel envía solo `vigencia_premios`/`tiempo_eliminacion`; un `required` plano rompería esa pestaña siempre. `sometimes|required` logra el mismo objetivo de negocio (no se puede desasignar el local con null) sin romper las actualizaciones parciales.
2. **Fix 3 también cubre `update`** (la auditoría citaba solo `:46`/store): el mismo bug existía en update (`:132`), se corrigió en ambos con la misma regla.

### Issues / Gotchas (correcciones)

- `AgenciaController::destroy` sigue desvinculando taquillas/usuarios (`agencia_id = null`) al borrar un local: tras la decisión del cliente quedan máquinas sin local en ese ciclo de vida. El endurecimiento NOT NULL + backfill posterior deberá decidir el destino de esas taquillas (riesgo documentado, fuera del alcance de este work unit).
- La suite tarda >2 min con el process-timeout default de composer (300s): usar `COMPOSER_PROCESS_TIMEOUT=900 composer test` o `php artisan test` directo.
- `backend/.env.example` y `panel/.astro/settings.json` seguían modificados en el working tree (pre-existentes) — NO se commitearon.

### Workload / PR Boundary (correcciones)

- **Modo**: chained PR slice (feature-branch-chain) — correcciones del mismo PR 6 sobre la rama `feat/jerarquia-agencias-f5` (base f4). NO se abrió PR.
- **Current work unit**: CORRECCIONES DE AUDITORÍA (4 fixes + regresión completa).
- **Review budget impact**: 4 commits, ~590 líneas (279 código/panel + 4 tests nuevos ~310 + actualizaciones de tests). Acumulado del PR 6 con F5 previo: dentro del presupuesto de 400 líneas por revisión al ser work units independientes.
- **Rollback boundary**: revertir los 4 commits de corrección elimina los fixes sin tocar F0–F5 (backend previo no rompe); cada commit es reversible de forma independiente.
