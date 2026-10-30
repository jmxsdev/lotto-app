# Tasks: retiro-ledger-comisiones (Modelo A — comisiones consultivas)

## Review Workload Forecast

| Field | Value |
|---|---|
| Estimated changed lines | +735 / −990 ≈ 1 725 total |
| 400-line budget risk | High |
| 800-line budget risk | Medium |
| Chained PRs recommended | Yes |
| Delivery strategy | auto-chain |
| Chain strategy | stacked-to-main |
| Suggested split | 1a → 1b → 2a → 2b → 3 |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High
800-line budget risk: Medium

### Slice / PR plan (auto-chain, sin consulta)

| Slice | PR | Objetivo | Archivos | Líneas est. | Deployable | ≤400 |
|---|---|---|---|---|---|---|
| 1a | 1 | Consulta backend (aditivo) | `ComisionService.php`, `ComisionController.php`, `routes/api.php`, `ComisionServiceTest.php`, `ComisionesApiTest.php` | +180/−40 ≈ 220 | sí | ✓ |
| 1b | 2 | Panel read-only + colección | `panel/src/pages/comisiones.astro`, `AdminLayout.astro`, `collections/Comisiones/Consultar Comisiones.yml` | +150/−110 ≈ 260 | sí (tras 1a) | ✓ |
| 2a | 3 | Retiro de código del ledger | `Comision.php` (del), `Banca/Grupo/Taquilla.php`, `ComisionController.php`, `ComisionService.php`, `routes/api.php` | +5/−330 ≈ 335 | sí | ✓ |
| 2b | 4 | Migración + tests + colecciones | `2026_10_30_000001_archive_and_drop_comisiones_table.php` (new), `ComisionesApiTest.php`, `ComisionServiceTest.php`, 3 YAML (del) | +70/−450 ≈ 520 | sí (tras 2a) | ✗ (≤800 ✓) |
| 3 | 5 | Docs + changelog | `docs/dev/motor-cierres-comisiones.md` (+4 docs), `CHANGELOG.md` | +330/−60 ≈ 390 | sí | ✓ |

Orden impuesto por el diseño: endpoint + panel primero (el panel nunca llama a una ruta eliminada), luego retiro del ledger + migración, luego docs. Slice 2 (agregado +75/−780 = 855) supera 800 solo por la migración/tests; se parte en 2a (código) / 2b (migración+tests+colecciones). **2a deja CI roja de forma transitoria** (los tests del ledger aún referencian el código eliminado); mergear 2a→2b en secuencia inmediata, o usar feature-branch-chain si se prefiere aislar el tracker.

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|---|---|---|---|---|---|
| 1a | `filasPorRango` + `GET /comisiones/consulta` + tests | PR 1 | `cd backend && php artisan test --filter=ComisionServiceTest --filter=ComisionesApiTest` | `php artisan test --filter=consulta` con `RefreshDatabase`+seeder | revert de service/controller/rutas aditivos; rutas ledger intactas |
| 1b | Página read-only + colección consulta | PR 2 | `cd panel && npm run build` | Navegar `/comisiones` con super_master/master; filtros y paginación client-side | revert de `comisiones.astro`; rutas 1a intactas |
| 2a | Retiro de código ledger | PR 3 | `cd backend && composer test` | N/A (solo eliminación; suite verde en 2b) | revert restaura modelo/relaciones/métodos/rutas |
| 2b | Migración archive+drop + tests/colecciones | PR 4 | `cd backend && php artisan test --filter=ComisionesApiTest` | `php artisan migrate:fresh` en `lotto_test`; verificar replay tras migraciones históricas | `php artisan migrate:rollback --step=1` recrea `comisiones` vacía (down) |
| 3 | Docs + changelog | PR 5 | `git diff --stat` | N/A (docs; sin runtime) | revert de docs; sin efecto en runtime |

## Fase 1: Consulta read-only (endpoint + panel)

- [x] 1.1 Agregar método público `filasPorRango(Carbon $desde, Carbon $hasta, ?array $bancaIds = null): array` a `backend/app/Services/ComisionService.php` — normaliza fechas (`startOfDay`/`endOfDay`) y devuelve solo `rows` reusando `filasLiquidables`+`aplicarTasas` (engine intacto). Commit: `feat(comisiones): agrega filasPorRango consultivo read-only`. ✓ `monto_comision` string 2 decimales; base 0 omitida. Verify: `php artisan test --filter=filasPorRango`.
- [x] 1.2 Agregar `consulta(Request)` + privado `bancasEnAlcance()` a `backend/app/Http/Controllers/Api/ComisionController.php` — mantiene `defaults`/`updateDefaults`/`index`/`liquidar`/`pagar` (retiro en Fase 2); valida `desde`/`hasta` (date, `after_or_equal`) → 422; `super_master` global, `master` → `masterBancaIds()`, `banca_ids` fuera de alcance → 403. Commit: `feat(comisiones): agrega endpoint GET /comisiones/consulta`. ✓ envelope `{data:[{nivel,entidad_id,entidad,monto_comision}], meta:{desde,hasta,total}}`; sin `estado`/`logs`. Verify: `php artisan test --filter=consulta`.
- [x] 1.3 Agregar ruta aditiva `GET /comisiones/consulta` bajo `role:super_master|master` en `backend/routes/api.php` (defaults intactos; bloque ledger intacto). Commit: `feat(comisiones): registra ruta GET /comisiones/consulta`. ✓ 401/403/422 según contrato. Verify: `php artisan route:list --path=comisiones`.
- [x] 1.4 Reescribir los 2 tests `test_previsualizar_devuelve_rows…` y `test_previsualizar_filtra_por_banca_ids` → `test_filas_por_rango_…` contra `filasPorRango` en `backend/tests/Unit/ComisionServiceTest.php` (mantener fixtures de cadena). Commit: `test(comisiones): reescribe tests de previsualizar a filasPorRango`. ✓ 3 niveles (10/20/70), rango vacío, filtro `banca_ids`, base 0 omitida, redondeo 2 decimales. Verify: `php artisan test --filter=filas_por_rango`.
- [x] 1.5 Agregar tests de feature de `consulta` en `backend/tests/Feature/ComisionesApiTest.php`: 200 con montos string, rango vacío, `super_master` global, `master` scoped, `master` sin bancas ⇒ `[]`, `banca_ids` fuera de alcance ⇒ 403, roles `banca/grupo/taquilla/agencia` ⇒ 403, 422 `hasta<desde`, sin clave `estado`, sin `logs` `comision_liquidar`. Commit: `test(comisiones): cubre consulta por rango y alcance de roles`. Verify: `php artisan test --filter=consulta`.
- [ ] 1.6 Reescribir `panel/src/pages/comisiones.astro` a read-only: eliminar tarjeta liquidar, filtro/badges `estado`, `Pagar`, `fetchBody`/`liquidar`/`pagar`/`renderConflictos`, import `icono`; botón "Consultar" → `/comisiones/consulta`; conservar filtros rango/nivel/entidad, tabla y paginación client-side. Commit: `feat(panel): pagina comisiones consultiva read-only`. ✓ sin acciones de pago; montos string parseados. Verify: `npm run build`.
- [ ] 1.7 Actualizar `panel/src/layouts/AdminLayout.astro` (comentario; label "Comisiones" sin cambio) y crear `collections/Comisiones/Consultar Comisiones.yml` (`GET /comisiones/consulta` con docs `desde`/`hasta`/`banca_ids`). Commit: `docs(colecciones): agrega Consultar Comisiones.yml`. Verify: `npm run build`.

## Fase 2: Retiro del ledger + migración

- [ ] 2.1 Eliminar `backend/app/Models/Comision.php` y quitar `comisiones()` hasMany + import sin uso de `backend/app/Models/Banca.php`, `Grupo.php`, `Taquilla.php`. Commit: `refactor(comisiones): elimina modelo Comision y relaciones`. ✓ sin referencias a `Comision`. Verify: `composer test`.
- [ ] 2.2 Quitar `index`/`liquidar`/`pagar`/`comisionEnAlcanceMaster` e imports `Comision`/`Grupo`/`Log`/`Taquilla` de `backend/app/Http/Controllers/Api/ComisionController.php` (quedan `defaults`/`updateDefaults`/`consulta`/`bancasEnAlcance`; imports `User`/`Carbon` se conservan). Commit: `refactor(comisiones): elimina index/liquidar/pagar del controlador`. ✓ solo consulta + defaults. Verify: `composer test`.
- [ ] 2.3 Quitar `previsualizar`/`liquidar`/`conflictosPorRango` de `backend/app/Services/ComisionService.php`; plegar `filasLiquidables` en `filasPorRango` (eliminar arrays `bancaIds/grupoIds/taquillaIds` del retorno); quitar imports `Comision`/`Log`/`DB`. Commit: `refactor(comisiones): elimina liquidar/previsualizar/conflictosPorRango`. ✓ un solo método público `filasPorRango`. Verify: `composer test`.
- [ ] 2.4 Eliminar bloque de rutas ledger (`GET /comisiones`, `POST /comisiones/liquidar`, `PATCH /comisiones/{comision}/pagar`) de `backend/routes/api.php`; conservar `defaults` y `consulta`. Commit: `refactor(comisiones): elimina rutas del ledger`. ✓ `route:list` sin rutas ledger. Verify: `php artisan route:list --path=comisiones`.
- [ ] 2.5 Crear `backend/database/migrations/2026_10_30_000001_archive_and_drop_comisiones_table.php`: `up()` crea `comisiones_archivo_2026` (sin FK/unique), copia con `insertUsing` solo si `count(comisiones)>0 && count(archivo)===0`, verifica `count(archivo)>=count(comisiones)` antes de `dropIfExists('comisiones')`; `down()` recrea `comisiones` vacía (forma original, sin restaurar filas). Commit: `feat(comisiones): archiva y elimina tabla comisiones`. ✓ idempotente con `hasTable`. Verify: `php artisan migrate:fresh` en `lotto_test`.
- [ ] 2.6 Eliminar los 15 tests del ledger (~líneas 212–709) y helpers `crearJerarquia`/`apuesta`/`liquidar` + import `Comision` de `backend/tests/Feature/ComisionesApiTest.php`. Commit: `test(comisiones): elimina tests del ledger`. ✓ tests de defaults/limites intactos. Verify: `php artisan test --filter=ComisionesApiTest`.
- [ ] 2.7 Eliminar `test_previsualizar_detecta_conflictos_por_rango_solapado` e import `Comision` de `backend/tests/Unit/ComisionServiceTest.php`. Commit: `test(comisiones): elimina test de conflictos del ledger`. Verify: `php artisan test --filter=ComisionServiceTest`.
- [ ] 2.8 Agregar método de end-state en `ComisionesApiTest`: `Schema::hasTable('comisiones')` false y `comisiones_archivo_2026` existe/vacía. Commit: `test(comisiones): verifica archivo de tabla comisiones`. Verify: `php artisan test --filter=archivo`.
- [ ] 2.9 Eliminar `collections/Comisiones/Liquidar Comisiones.yml`, `Pagar Comision.yml`, `Listar Comisiones.yml` (conservar `Ver Defaults.yml`, `Actualizar Defaults.yml`, `folder.yml`). Commit: `docs(colecciones): elimina YAML del ledger`. Verify: `ls collections/Comisiones/`.

## Fase 3: Docs + changelog

- [ ] 3.1 Copiar `docs/dev/motor-cierres-comisiones.md` desde el worktree principal (untracked) y actualizar: §1 (un subsistema + archivo), §2 (service/controlador), §5.4/§5.5 → consulta, §6 `comisiones_archivo_2026`, §8 tabla de tests, §9 hallazgos 3 y 5. Commit: `docs(dev): actualiza motor-cierres-comisiones a modelo consultivo`. Verify: `git diff --stat`.
- [ ] 3.2 Quitar referencias al ledger/pago y documentar el paso de dump en `docs/dev/integracion-front-motor-premios.md`, `estructura.md`, `arquitectura-backend.md`, `runbook-ops.md`. Commit: `docs(dev): elimina referencias al ledger`. Verify: `grep -ri "liquidar\|/comisiones/pagar" docs/dev/`.
- [ ] 3.3 Entrada en `CHANGELOG.md` (retiro del ledger, archivo `comisiones_archivo_2026`, endpoint `consulta`). Commit: `docs: registra retiro del ledger de comisiones`. Verify: `git diff --stat CHANGELOG.md`.

## Plan de tests

- **Eliminar**: 15 tests ledger de `ComisionesApiTest` (~497 líneas) + helpers/import `Comision`; `test_previsualizar_detecta_conflictos_por_rango_solapado` de `ComisionServiceTest`.
- **Reescribir**: `test_previsualizar_devuelve_rows_*` y `test_previsualizar_filtra_por_banca_ids` → `filasPorRango`.
- **Agregar**: tests `consulta` (200/403/422/alcance/sin persistencia) + end-state de archivo.
- **Conservar (regresión)**: `ComisionCierreTest`, `ComisionReporteTest`, `CierreCajaTest` (aserciones de comisión), defaults 200/403/422/16–17, matriz `/limites` (incluye tests `grupo` matriz 710–750).
- Comandos: `cd backend && composer test` (suite completa); `cd backend && php artisan test --filter=consulta`; `php artisan test tests/Feature/ComisionesApiTest.php`; `php artisan test tests/Unit/ComisionServiceTest.php`.

## Migración / deploy

1. **Pre-deploy (producción)**: `mysqldump` de `comisiones` + `SELECT COUNT(*)` gate ANTES de `php artisan migrate --force`.
2. **Migrar**: `php artisan migrate --force` (copia → verifica → drop; throw si copia corta, sin tocar el source).
3. **Post-deploy**: verificar `COUNT(*)` de `comisiones_archivo_2026` == pre, y `SHOW TABLES LIKE 'comisiones'` vacío.
4. **Replay en tests**: `RefreshDatabase` sobre `lotto_test`; nunca editar migraciones históricas.

## Archivo de spec

El delta se archiva vía `sdd-archive` **después de `verify`**; NO editar `openspec/specs/comisiones/spec.md` directamente en apply. `cierre-caja` y `reportes-agencia` no requieren delta (referencian la capability, no el ledger).

## Riesgos / bloqueadores

- **Discrepancia de nomenclatura**: el brief/proposal dicen "`previsualizar` → `consultaPorRango`", pero design AD #4 elige **un solo método público `filasPorRango`** (eliminar `previsualizar`). Apply debe seguir el design.
- **CI roja transitoria en 2a**: los tests del ledger se eliminan en 2b; mergear 2a→2b en secuencia inmediata (o usar feature-branch-chain).
- **Pérdida irreversible de filas**: mitigado por dump + gate de conteo; la migración lanza antes de dropear si la copia es corta.
- **#67 descartado**: no reintroducir el fix de índice único (ya revertido).
- **Rollback**: `git revert` del merge restaura modelo/relaciones/controlador/rutas/página/tests/colecciones (juntos, o la página 404s); `down()` recrea `comisiones` vacía y el dump restaura filas.
