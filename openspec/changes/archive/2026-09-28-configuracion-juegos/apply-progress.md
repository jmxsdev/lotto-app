# Apply Progress: configuracion-juegos — Slices S1a + S1b + S2

**Cambio**: `configuracion-juegos` · **Slices completados**: S1a (servicio + endpoint + espejos + auditoría), S1b (tests de integración end-to-end) y S2 (snapshot de premios por apuesta)
**Ramas**: `feat/configuracion-juegos-s1a` (base: `feat/configuracion-juegos`, tracker con artefactos) · `feat/configuracion-juegos-s1b` (base: S1a) · `feat/configuracion-juegos-s2` (base: S1b)
**Fecha**: 2026-09-28 · **Modo**: Strict TDD (RED → GREEN → REFACTOR)

## Estado

`success` — S1a completo (5/5 tareas) + S1b completo (3/3 tareas) + S2 completo (6/6 tareas). S3/S4 sin tocar.

## TDD Cycle Evidence

| Tarea | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|-------|-----------|-------|------------|-----|-------|-------------|----------|
| 1.1 | `tests/Feature/JuegoPremiosApiTest.php` | Integration | ✅ 64/64 (MotorPremiosRegresionTest\|JuegosJsonTest\|PremiosOficialesTest) | ✅ 15 tests escritos primero (12 endpoint + 3 espejosLegacy); fallaron 15 (404 ruta ausente / método ausente) | ✅ 45/45 tras implementar | ✅ 12 casos endpoint: unión, clave inválida, la-ricachona, 200+espejos, 403, base (4 inputs), tipo, acumulativo, valores (5 inputs), canónica sin plugin, merge, auditoría | ✅ Pint + tests re-verdes |
| 1.2 | `tests/Feature/JuegoPremiosApiTest.php` (vía endpoint) | Integration | N/A (nuevo) | ✅ Referencia `PremiosConfigService::clavesModalidadValidas/actualizar` inexistente | ✅ Servicio creado; endpoint 200/422 reales | ✅ Unión plugin∪catálogo y canónica sin plugin | ✅ Pint |
| 1.3 | `tests/Unit/PremiosOficialesTest.php` (+3) | Unit | ✅ 30/30 previos del archivo | ✅ `espejosLegacy()` inexistente → 3 errores | ✅ `configPara()` delega; 33/33 | ✅ 3 casos: triple-zulia mapeo, terminal-activo ESPEJO_EXTRA, sin base | ✅ Pint; contrato configPara intacto (approval previos verdes) |
| 1.4 | `tests/Feature/JuegoPremiosApiTest.php` | Integration | N/A (ruta nueva) | ✅ 404 en 12 tests (ruta ausente) | ✅ Ruta + `updatePremios`; 200/403/422 reales | ✅ 403 rol banca + 200 master | ✅ Pint |
| 1.5 | `vendor/bin/pint --test` + regresión | REFACTOR | ✅ 64/64 baseline | N/A | N/A | N/A | ✅ Pint fixed 2 archivos → `--test` passed; `MotorPremiosRegresionTest\|JuegosJsonTest` 34/34 |

## Test Summary

- **Total tests escritos en S1a**: 15 (12 `JuegoPremiosApiTest` + 3 `PremiosOficialesTest::espejosLegacy*`)
- **Total tests pasando (focused)**: 45/45 — `JuegoPremiosApiTest` (12) + `PremiosOficialesTest` (33) — 202 assertions
- **Regresión**: 34/34 — `MotorPremiosRegresionTest` + `JuegosJsonTest` — 787 assertions (contrato de export intacto)
- **Layers**: Unit (33), Integration (12)
- **Approval tests** (refactor configPara): 30 previos de `PremiosOficialesTest` re-verdes tras la extracción
- **Pure functions creadas**: 1 (`PremiosOficiales::espejosLegacy`)

## Files Changed

| File | Action | What Was Done |
|------|--------|---------------|
| `backend/app/Services/PremiosConfigService.php` | Created | `clavesModalidadValidas(Juego)` (plugin ∪ catálogo, D3) y `actualizar(Juego, array, int)` (merge alto nivel + espejos + `updated_by` + auditoría `accion=premios`) |
| `backend/app/Support/PremiosOficiales.php` | Modified | `espejosLegacy(string, array)` público (fuente única de `ESPEJO_MODALIDADES`/`ESPEJO_EXTRA`); `configPara()` delega |
| `backend/app/Http/Controllers/Api/JuegoController.php` | Modified | `updatePremios()`: validación estricta (base int ≥ 1; claves en unión; comodines tipo/premio_multiplo; `acumulativo` solo con `palabra`; la-ricachona → 422) + servicio + 200 con `load('pluginJuego','updatedByUser')` |
| `backend/routes/api.php` | Modified | `PUT /juegos/{juego}/premios` en grupo `role:super_master|master` |
| `backend/tests/Feature/JuegoPremiosApiTest.php` | Created | 12 tests endpoint (200/403/422/auditoría/merge/espejos) |
| `backend/tests/Unit/PremiosOficialesTest.php` | Modified | +3 tests `espejosLegacy` |
| `openspec/changes/configuracion-juegos/tasks.md` | Modified | S1a marcada `[x]` (1.1–1.5) |
| `openspec/changes/configuracion-juegos/apply-progress.md` | Created | Este artefacto |

## Work Unit Evidence

| Evidence | Required value |
|---|---|
| Focused test command and exact result | `DB_DATABASE=lotto_test_motor php artisan test --filter='JuegoPremiosApiTest\|PremiosOficialesTest'` → `passed`, 45 tests, 202 assertions |
| Runtime harness command/scenario and exact result | Endpoint real sobre BD sembrada (DatabaseSeeder): `PUT /api/v1/juegos/{juego}/premios` con rol `super_master` (200 + espejos + auditoría), `banca` (403), payloads inválidos (422) — 12 escenarios ejecutados vía HTTP |
| Rollback boundary | Revertir `feat/configuracion-juegos-s1a` (aditivo): ruta + controlador + servicio + espejosLegacy. `configPara` vuelve a su forma previa; nada retroactivo (sin migración en S1a) |

## Commits

| Hash | Mensaje | Contenido |
|------|---------|-----------|
| (ver `git log`) | `feat(juegos): endpoint de premios con merge seguro` | Código + tests backend (6 archivos) |
| (ver `git log`) | `docs(sdd): cierre del slice S1a de configuracion-juegos` | `tasks.md` `[x]` + `apply-progress.md` |

NO push (regla del slice; PR #1 de la feature-branch-chain lo hará el orchestrator).

## Deviations

- **Aserciones de orden de claves**: el round-trip MySQL en columnas `json` reordena las claves de objetos (`config.premios` vuelve `[base, comodines, modalidades]`). Las comparaciones de objetos JSON usan `assertEqualsCanonicalizing` (misma convención que `JuegosJsonTest`). El contrato es de valores, no de orden de claves. Ninguna desviación de diseño.
- Firmas: `actualizar(Juego, array, int)` y `clavesModalidadValidas(Juego)` según tasks/design (el prompt resumía la firma sin el `int`).

## Issues

- Ninguno. Los 12 fallos RED y los 3 errores RED fueron el estado esperado (código inexistente).
- Nota: la validación de claves de modalidad y `acumulativo` devuelve 422 vía `response()->json(message)` (no `ValidationException`) — mismo status que el contrato; el mensaje es claro.

## Next Steps

- S1b: tests de integración de espejos (`premio_multiplo`=base, `config.modalidades` espejo, `config.comodines`), `/reglas`, export `--path` en `JuegosJsonTest`.
- Luego S2 (snapshot), S3 (toggle + nota re-export), S4 (editor panel) — fuera de este slice.

---

# Slice S1b — Tests de integración end-to-end

**Rama**: `feat/configuracion-juegos-s1b` (base: `feat/configuracion-juegos-s1a`)
**Fecha**: 2026-09-28 · **Modo**: Strict TDD

## Estado

`success` — S1b completo (3/3 tareas). Sin defectos de producción S1a destapados: el contrato end-to-end (espejos vía GET, auditoría vía GET, reglas, export `--path`) ya estaba completo.

## TDD Cycle Evidence (S1b)

| Tarea | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|-------|-----------|-------|------------|-----|-------|-------------|----------|
| 2.1 | `tests/Feature/JuegoPremiosApiTest.php` (+3) + `tests/Feature/JuegosJsonTest.php` (+1) | Integration | ✅ 16/16 (JuegoPremiosApiTest 12 + JuegosJsonTest 4; 764 assertions) | ✅ 4 tests escritos primero (espejos vía GET, reglas, auditoría vía GET, export `--path`); 1º run: 19/20 (defecto del PROPIO test: ruta `tempnam().'.json'` inexistente para unlink, no de producción) | ✅ 20/20 (795 assertions) tras corregir el fixture del test | ✅ 2 vías: PUT→GET `/juegos/{id}` (espejos+auditoría) y PUT→`/reglas` (motor) + PUT→export (catálogo) | ✅ Pint `--test` limpio (repo completo) |
| 2.2 | `tests/Feature/JuegoPremiosApiTest.php` + `JuegosJsonTest.php` | Integration | N/A (tests nuevos) | ✅ Los 4 tests describen el contrato end-to-end (accion=premios con before/after/updated_by expuestos; espejos sincronizados; reglas+export reflejan cambios) | ✅ 20/20; verificación real HTTP + `artisan` real | ✅ Espejo triple-zulia (cola/zodiacal) y export sobre el mismo payload editado | ✅ Pint |
| 2.3 | `vendor/bin/pint --test` + suite S1a+S1b | REFACTOR | ✅ 16/16 baseline | N/A | N/A | N/A | ✅ Pint `--test` passed; `JuegoPremiosApiTest\|PremiosOficialesTest` 48/48; regresión `MotorPremiosRegresionTest\|JuegosJsonTest` 35/35 |

## Test Summary (S1b)

- **Total tests escritos en S1b**: 4 (3 `JuegoPremiosApiTest` + 1 `JuegosJsonTest`)
- **Total tests pasando (focused)**: 20/20 — `JuegoPremiosApiTest` (15) + `JuegosJsonTest` (5) — 795 assertions
- **Regresión**: 35/35 — `MotorPremiosRegresionTest` + `JuegosJsonTest` — 800 assertions (contrato de export intacto)
- **Suite S1a+S1b**: 48/48 — `JuegoPremiosApiTest` + `PremiosOficialesTest` — 220 assertions
- **Layers**: Integration (4)
- **Approval tests**: None — sin refactor de producción
- **Pure functions creadas**: 0 — slice de tests puro

## Files Changed (S1b)

| File | Action | What Was Done |
|------|--------|---------------|
| `backend/tests/Feature/JuegoPremiosApiTest.php` | Modified | +3 tests end-to-end: `test_put_premios_espejos_reflejados_en_get_juego` (GET /juegos/{id} refleja premios canónicos + premio_multiplo + modalidades espejo + comodines), `test_put_premios_reflejados_en_reglas` (GET /juegos/{id}/reglas expone premios nuevos del motor), `test_put_premios_auditoria_expuesta_en_get_juego` (auditoria[] con accion=premios, before/after y user editor vía relación user) |
| `backend/tests/Feature/JuegosJsonTest.php` | Modified | +1 test `test_export_con_path_refleja_premios_editados_sin_tocar_docs`: `juegos:export --path=<tmp>` genera catálogo con premios editados + espejos y NO reescribe `docs/juegos.json` (hash sha256 antes/después idéntico) |
| `openspec/changes/configuracion-juegos/tasks.md` | Modified | S1b marcada `[x]` (2.1–2.3) |
| `openspec/changes/configuracion-juegos/apply-progress.md` | Modified | Merge: secciones S1a intactas + sección S1b añadida |

## Work Unit Evidence (S1b)

| Evidence | Required value |
|---|---|
| Focused test command and exact result | `DB_DATABASE=lotto_test_motor php artisan test --filter='JuegoPremiosApiTest\|JuegosJsonTest'` → `passed`, 20 tests, 795 assertions (15 + 5) |
| Runtime harness command/scenario and exact result | Flujo real HTTP + comando real: PUT `/api/v1/juegos/{id}/premios` → GET `/juegos/{id}` (espejos+auditoría) → GET `/juegos/{id}/reglas` (premios del motor) → `php artisan juegos:export --path=<tmp>` (catálogo editado, docs/juegos.json intacto por hash) — 4 escenarios end-to-end sobre BD sembrada |
| Rollback boundary | Revertir `feat/configuracion-juegos-s1b` (aditivo, solo tests): 4 tests en 2 archivos. Cero cambios de producción. `docs/juegos.json` no se tocó (verificado por hash en el propio test) |

## Commits (S1b)

| Hash | Mensaje | Contenido |
|------|---------|-----------|
| (ver `git log`) | `test(juegos): espejos, auditoría y export` | +4 tests de integración (2 archivos) |
| (ver `git log`) | `docs(sdd): cierre del slice S1b de configuracion-juegos` | `tasks.md` `[x]` + `apply-progress.md` merge |

NO push (regla del slice; PR #2 de la feature-branch-chain lo hará el orchestrator).

## Deviations (S1b)

- Ninguna de diseño. El único fallo del primer run fue un defecto del PROPIO test (construcción de ruta temporal `tempnam().'.json'` que rompía el `unlink`); se corrigió el fixture con `sys_get_temp_dir().'/juegos-export-'.uniqid().'.json'` — no se tocó producción.
- Nota: la auditoría end-to-end verifica el usuario editor vía `auditoria[].user.email` (relación `user` que carga `show()`); `updated_by` en la fila del juego ya estaba cubierto en S1a (`test_put_premios_audita_before_y_after`).

## Issues (S1b)

- Ningún defecto de producción S1a destapado: el contrato end-to-end ya cumplía spec/design (espejos, auditoría, reglas, export).

## Next Steps (tras S1b)

- S2 (snapshot por apuesta): migración + venta con snapshot + override motor/manager + liquidación/pago + `PremioSnapshotTest`.
- S3 (fix toggle + deuda tests + nota re-export), S4 (editor P2 en panel) — fuera de este slice.

---

# Slice S2 — Snapshot de premios por apuesta (sin retroactividad)

**Rama**: `feat/configuracion-juegos-s2` (base: `feat/configuracion-juegos-s1b`)
**Fecha**: 2026-09-28 · **Modo**: Strict TDD

## Estado

`success` — S2 completo (6/6 tareas). La venta persiste `config.premios` vigente como
`detalle_apuestas.premios_snapshot`; el motor (`PremiosEngine`) y el manager (`JuegoPluginManager`)
aceptan un override opcional `?array $premios = null` (null → `config.premios` actual); la liquidación
(`verificarGanadores`) y el pago (`PagoController::calcularPremio`) resuelven contra el snapshot con
fallback legacy. Cero cambios en S3/S4 (toggle/panel) — fuera de alcance.

## TDD Cycle Evidence (S2)

| Tarea | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|-------|-----------|-------|------------|-----|-------|-------------|----------|
| 3.1 | `tests/Feature/PremioSnapshotTest.php` (nuevo, 5) + `tests/Unit/PremiosEngineTest.php` (+4) | Integration + Unit | ✅ 112/112 (PremiosEngineTest 21, VerificarGanadoresTest 5, ApuestaServiceTest 21, PagoPremioSinMontosTest 4, ModalidadesSingleDrawTest 21, JuegoPremiosApiTest 15, ApuestaTest 19, JuegosJsonTest 5, MotorPremiosRegresionTest subset 1) | ✅ 9 tests escritos primero; run inicial: 5 fallos + 2 errores (7 RED reales: columna ausente → null; override ignorado → config actual 600/2000 en vez de snapshot 500/3000/400; pago 201 en vez de 422) + 2 approval verdes (sin override = default; legacy sin snapshot = config actual) | ✅ 30/30 (25 engine + 5 snapshot) | ✅ 5 casos flujo completo: persistencia, edición→liquidación (50× no 60×), pago snapshot (422 monto nuevo / 201 sin montos), fallback legacy (600× config actual), comodines congelados (50+20=70× no 600); +3 casos unit override (base, comodines, premioPosible/multiplicadorPara) | ✅ Pint `--test` limpio (fix automático en migración + test nuevo) |
| 3.2 | Migración (no test directo; cubierta por `PremioSnapshotTest`) | — | ✅ N/A (columna nueva) | ✅ RED 3.1 depende de la columna (`null does not match expected type array`) | ✅ Migración `2026_09_28_000001_add_premios_snapshot_to_detalle_apuestas_table.php` (json nullable `after('premio_ganado_usd')`, down drop) + `DetalleApuesta` fillable+cast `array` | ✅ Verificada por los 5 tests del flujo real (RefreshDatabase migra y siembra) | ✅ Pint |
| 3.3 | `tests/Unit/ApuestaServiceTest.php` (approval, sin modificar) + `PremioSnapshotTest::test_venta_persiste_snapshot...` | Integration | ✅ 21/21 (ApuestaServiceTest) | ✅ `premios_snapshot` null al vender (RED 3.1) | ✅ `createApuesta()` añade `'premios_snapshot' => $juego->config['premios'] ?? null` en `DetalleApuesta::create` | ✅ Monje (con premios) snapshot completo; null cuando no hay premios (legacy-safe) | ✅ Pint |
| 3.4 | `tests/Unit/PremiosEngineTest.php` (+4) | Unit | ✅ 21/21 previos del archivo | ✅ override ignorado: `calcular` devolvía 600 (config) en vez de 500 (snapshot); comodín 600 en vez de 400; `premioPosible` 2000 en vez de 3000 | ✅ `calcular/premioPosible/multiplicadorPara/multiplicadorConComodines` + `JuegoPluginManager::calcularPremio` aceptan `?array $premios = null`; con valor reemplaza `config.premios` (incl. comodines); default intacto | ✅ 3 casos override + 1 approval default (600 sin override) | ✅ Pint |
| 3.5 | `tests/Feature/VerificarGanadoresTest.php` (approval 5/5) + `PremioSnapshotTest` (flujo) + `PagoPremioSinMontosTest` (approval 4/4) | Integration | ✅ 5/5 + 4/4 | ✅ pago con monto del config nuevo → 201 (debía 422: el snapshot no manda aún) | ✅ `verificarGanadores` con `with('detalles')` pasa `premios_snapshot ?? null`; `PagoController::store` eager-loads `detalles` y `calcularPremio()` usa `detalles->first()?->premios_snapshot` | ✅ 3 vías: liquidación snapshot (500), pago snapshot (422/201), legacy (600) | ✅ Pint |
| 3.6 | Suite S2 completa + regresión | REFACTOR | ✅ 112/112 baseline | N/A | N/A | N/A | ✅ Pint `--test` passed; focused `PremioSnapshotTest\|PremiosEngineTest` 30/30; regresión `JuegoPremiosApiTest\|JuegosJsonTest\|ModalidadesSingleDrawTest\|VerificarGanadoresTest\|PremioSnapshotTest` 51/51 (874 assertions); `MotorPremiosRegresionTest` 30/30; `ApuestaTest` 19/19; `ApuestaServiceTest` 21/21; `PagoPremioSinMontosTest` 4/4 |

## Test Summary (S2)

- **Total tests escritos en S2**: 9 (5 `PremioSnapshotTest` + 4 `PremiosEngineTest`)
- **Total tests pasando (focused)**: 30/30 — `PremioSnapshotTest` (5) + `PremiosEngineTest` (25) — 51 assertions
- **Regresión**: 51/51 — `JuegoPremiosApiTest\|JuegosJsonTest\|ModalidadesSingleDrawTest\|VerificarGanadoresTest\|PremioSnapshotTest` — 874 assertions
- **MotorPremiosRegresionTest**: 30/30 — 59 assertions (contrato del motor intacto con override default)
- **Layers**: Integration (5), Unit (4)
- **Approval tests**: 3 — default sin override (engine), fallback legacy (snapshot), pago legacy (PagoPremioSinMontosTest re-verde)
- **Pure functions creadas**: 0 — el override es un parámetro opcional propagado (D4)

## Files Changed (S2)

| File | Action | What Was Done |
|------|--------|---------------|
| `backend/database/migrations/2026_09_28_000001_add_premios_snapshot_to_detalle_apuestas_table.php` | Created | `premios_snapshot` json nullable `after('premio_ganado_usd')`; `down()` drop |
| `backend/app/Models/DetalleApuesta.php` | Modified | fillable + cast `array` para `premios_snapshot` |
| `backend/app/Services/ApuestaService.php` | Modified | `createApuesta()` persiste `premios_snapshot`; `verificarGanadores()` con `with('detalles')` pasa `premios_snapshot ?? null` al motor |
| `backend/app/Services/PremiosEngine.php` | Modified | `calcular/premioPosible/multiplicadorPara/multiplicadorConComodines` aceptan `?array $premios = null` (override de `config.premios`, incl. comodines) |
| `backend/app/Services/JuegoPluginManager.php` | Modified | `calcularPremio()` acepta `?array $premios = null` y lo propaga al engine |
| `backend/app/Http/Controllers/Api/PagoController.php` | Modified | `store()` eager-loads `detalles`; `calcularPremio()` usa `detalles->first()?->premios_snapshot` (fallback legacy null) |
| `backend/tests/Feature/PremioSnapshotTest.php` | Created | 5 tests flujo completo (persistencia, liquidación snapshot, pago snapshot, legacy, comodines congelados) |
| `backend/tests/Unit/PremiosEngineTest.php` | Modified | +4 tests override de premios (base, comodines, premioPosible/multiplicadorPara, default) |
| `openspec/changes/configuracion-juegos/tasks.md` | Modified | S2 marcada `[x]` (3.1–3.6) |
| `openspec/changes/configuracion-juegos/apply-progress.md` | Modified | Merge: secciones S1a/S1b intactas + sección S2 añadida |

## Work Unit Evidence (S2)

| Evidence | Required value |
|---|---|
| Focused test command and exact result | `DB_DATABASE=lotto_test_motor php artisan test --filter='PremioSnapshotTest\|PremiosEngineTest'` → `passed`, 30 tests, 51 assertions |
| Runtime harness command/scenario and exact result | Flujo real HTTP + servicio + BD sembrada (DatabaseSeeder): vender (ApuestaService::createApuesta, snapshot base 50) → `PUT /api/v1/juegos/{id}/premios` base 60 (S1a) → resultado → `verificarGanadores` liquida 500 (10×50 snapshot, NO 600) → pago egreso con monto 600 → 422 `premio_esperado_bs=500`; sin montos → 201 premio 500. Legacy sin snapshot → 600 (config actual). Comodín PATRONUS del snapshot → 700 (50+20×) tras edición sin comodines. 5 escenarios end-to-end ejecutados |
| Rollback boundary | `php artisan migrate:rollback --step=1` (drop `premios_snapshot`) + revertir call sites (`createApuesta`/`verificarGanadores`/`PagoController`/engine/manager): el parámetro es opcional y null → comportamiento legacy exacto (probado por approval tests) |

## Commits (S2)

| Hash | Mensaje | Contenido |
|------|---------|-----------|
| (ver `git log`) | `feat(premios): snapshot por apuesta` | Migración + modelo + servicio + motor/manager + pago + 9 tests (8 archivos) |
| (ver `git log`) | `docs(sdd): cierre del slice S2 de configuracion-juegos` | `tasks.md` `[x]` + `apply-progress.md` merge |

NO push (regla del slice; PR #3 de la feature-branch-chain lo hará el orchestrator).

## Deviations (S2)

- Ninguna de diseño (D4 respetado: nombre de migración, JSON nullable, parámetro opcional, comodines incluidos en el override). El prompt resumía `PremiosEngine::calcular(..., ?array $premios = null)`; tasks.md exige también `premioPosible/multiplicadorPara/multiplicadorConComodines` — implementado según tasks/design (los 4 métodos).
- Nombre del test: `PremioSnapshotTest` (contrato tasks.md 3.1), no `JuegoPremiosSnapshotTest` (el prompt pedía ajustar al set real definido en tasks — el filtro de regresión usa el nombre real).
- `multiplicadorPara` con override sin `base` cae al fallback transicional `premio_multiplo` legacy (mismo criterio que el default); los snapshots siempre traen base validada, así que no afecta.

## Issues (S2)

- Ninguno. Los 7 fallos/errores RED fueron el estado esperado (columna ausente + override ignorado). Pint corrigió estilo en la migración y el test nuevo (class_definition, EOF) — sin cambio de comportamiento.
- Nota de runtime: `MotorPremiosRegresionTest` (30 tests, RefreshDatabase+DatabaseSeeder por test) tarda ~3,5 min; los runs individuales pueden variar según caché.

## Next Steps (tras S2)

- S3 (fix toggle + deuda tests + nota re-export), S4 (editor P2 en panel) — fuera de este slice.
- S2 listo para el PR #3 de la feature-branch-chain (base: PR #2 S1b).

---

# Slice S3 — Fix toggle + tests deuda + nota de re-export

**Rama**: `feat/configuracion-juegos-s3` (base: `feat/configuracion-juegos-s2`)
**Fecha**: 2026-09-28 · **Modo**: Strict TDD

## Estado

`success` — S3 completo (5/5 tareas). El panel envía `{active: !actual}` en el toggle y muestra el error
del backend; los tests de deuda de `docs/PENDIENTE.md` §7 (`JuegoToggleTest` + `JuegoUpdateTest`)
quedan cubiertos; la nota de re-export/taquilla está en `docs/motor-premios.md` §9.1. El test RED de
reactivación del plugin destapó y corrigió un defecto real de producción en `toggle()` (la relación
`pluginJuego` filtra por `active=true` → al reactivar el juego el plugin no se reactivaba).

## TDD Cycle Evidence (S3)

| Tarea | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|-------|-----------|-------|------------|-----|-------|-------------|----------|
| 4.1 | `tests/Feature/JuegoToggleTest.php` (nuevo, 4) + `tests/Feature/JuegoUpdateTest.php` (nuevo, 3) | Integration | ✅ 20/20 (JuegoPremiosApiTest 15 + JuegosJsonTest 5; 795 assertions) | ✅ 7 tests escritos primero; run inicial 6/7 (1 fallo REAL de producción: `test_toggle_plugin_sincronizado_en_ambos_sentidos` — al reactivar, el plugin quedaba false porque `pluginJuego` filtra `active=true`) | ✅ 7/7 (34 assertions) tras fix mínimo en `toggle()` | ✅ 3 casos toggle (422 sin body; desactivar; activar) + 1 de sincronización bidireccional; update 3 casos (persistencia+auditoría; reemplazo completo no merge; 403 banca) | ✅ Pint fix (EOF en 2 tests) + `--test` passed; focused 22/22; regresión 35/35 |
| 4.2 | `panel/src/pages/juegos.astro` (sin runner de panel; verificación = build + lectura) | — | ✅ Build previo no aplicable (cambio de script inline) | ✅ Estado previo leído: `apiFetch('PATCH','/juegos/'+id+'/toggle')` sin body + `catch(err){}` silencioso | ✅ L31: `{active: !active}` + `catch(err){alert('Error: '+err.message)}` | ✅ Verificación honesta: lectura del diff + `pnpm run build` verde (25 páginas, incluye `/juegos`) | ✅ Build verde |
| 4.3 | `JuegoToggleTest` + `JuegoUpdateTest` (GREEN 4.1) + build panel | Integration | ✅ 20/20 baseline | ✅ RED 4.1 (1 fallo de producción) | ✅ 7/7; focused 22/22 (con `JuegoPremiosApiTest`); `pnpm run build` verde | ✅ 7 casos distribuidos en 2 archivos | ✅ Pint `--test` passed |
| 4.4 | Nota en `docs/motor-premios.md` §9.1 (docs; sin test) | — | N/A (docs) | N/A — tarea documental, contrato literal de tasks.md 4.4 | ✅ Nota añadida: tras editar premios, `juegos:export` + coordinar copia `docs/juegos.json` → `taquilla/src/data/juegos.json` (sin tocar `taquilla/`) | ✅ Verificación: contenido exacto del contrato presente en §9.1 | ✅ Pint `--test` (repo completo) |
| 4.5 | Suite S3 completa + regresión | REFACTOR | ✅ 20/20 baseline | N/A | N/A | N/A | ✅ Pint `--test` passed; focused `JuegoToggleTest\|JuegoUpdateTest\|JuegoPremiosApiTest` 22/22 (88 assertions); regresión `MotorPremiosRegresionTest\|JuegosJsonTest` 35/35 (800 assertions); check extra `PluginIntegrationTest\|ActivacionTest` 16/16 (1 skipped preexistente) |

## Test Summary (S3)

- **Total tests escritos en S3**: 7 (4 `JuegoToggleTest` + 3 `JuegoUpdateTest`)
- **Total tests pasando (focused)**: 22/22 — `JuegoToggleTest\|JuegoUpdateTest\|JuegoPremiosApiTest` — 88 assertions
- **Regresión**: 35/35 — `MotorPremiosRegresionTest` + `JuegosJsonTest` — 800 assertions (contrato del motor y export intactos)
- **Layers**: Integration (7)
- **Approval tests**: 3 — toggle 422 (estado vigente intacto), update reemplazo completo no merge (docs §8), 403 banca (ruta)
- **Pure functions creadas**: 0 — fix de una línea de producción + script inline del panel

## Files Changed (S3)

| File | Action | What Was Done |
|------|--------|---------------|
| `backend/tests/Feature/JuegoToggleTest.php` | Created | 4 tests: 422 sin body, desactivar persiste+audita (`accion=desactivar` before/after + updated_by), activar audita (`accion=activar`), plugin sincronizado en ambos sentidos |
| `backend/tests/Feature/JuegoUpdateTest.php` | Created | 3 tests: update persiste name/config + audita `accion=actualizar` (before/after + updated_by), config reemplazo completo no merge, 403 rol banca |
| `backend/app/Http/Controllers/Api/JuegoController.php` | Modified | `toggle()`: sincroniza el plugin vía `pluginJuegos()` (hasMany sin filtro) en vez de `pluginJuego` (filtra `active=true`) — el plugin se reactiva al reactivar el juego |
| `panel/src/pages/juegos.astro` | Modified | L31: envía `{active: !active}` en `PATCH /juegos/{id}/toggle` y reemplaza `catch(err){}` por `alert('Error: '+err.message)` |
| `docs/motor-premios.md` | Modified | §9.1: nota de re-export tras editar premios + coordinación de la copia bundled de taquilla |
| `openspec/changes/configuracion-juegos/tasks.md` | Modified | S3 marcada `[x]` (4.1–4.5) |
| `openspec/changes/configuracion-juegos/apply-progress.md` | Modified | Merge: secciones S1a/S1b/S2 intactas + sección S3 añadida |

## Work Unit Evidence (S3)

| Evidence | Required value |
|---|---|
| Focused test command and exact result | `DB_DATABASE=lotto_test_motor php artisan test --filter='JuegoToggleTest\|JuegoUpdateTest\|JuegoPremiosApiTest'` → `passed`, 22 tests, 88 assertions |
| Runtime harness command/scenario and exact result | Endpoint real sobre BD sembrada (DatabaseSeeder): `PATCH /api/v1/juegos/{id}/toggle` con body `{active}` (200 + persistencia + plugin + auditoría before/after), sin body (422), reactivación tras desactivar (plugin reactivado — defecto corregido); `PUT /api/v1/juegos/{id}` (name/config + auditoría, 403 banca) — 7 escenarios vía HTTP; panel: `pnpm run build` verde (25 páginas) |
| Rollback boundary | Revertir `feat/configuracion-juegos-s3`: panel (1 línea), `toggle()` (vuelve a `pluginJuego` con el defecto de reactivación), 2 archivos de test nuevos, 1 línea de docs. El fix del toggle es aditivo (no cambia contrato de respuesta) |

## Commits (S3)

| Hash | Mensaje | Contenido |
|------|---------|-----------|
| `5f67077` | `fix(panel): toggle con active y error visible` | `panel/src/pages/juegos.astro` (1 línea) |
| `09abcfa` | `test(juegos): update y toggle` | `JuegoToggleTest` + `JuegoUpdateTest` + fix `toggle()` (defecto reactivación plugin) |
| `15ac158` | `docs(juegos): nota de re-export` | `docs/motor-premios.md` §9.1 (1 línea) |
| (siguiente) | `docs(sdd): cierre del slice S3 de configuracion-juegos` | `tasks.md` `[x]` + `apply-progress.md` merge |

NO push (regla del slice; PR #4 de la feature-branch-chain lo hará el orchestrator).

## Deviations (S3)

- **Defecto de producción destapado por el test**: `toggle()` usaba `$juego->pluginJuego` (relación con filtro `where('active', true)`), por lo que al REACTIVAR un juego desactivado el plugin quedaba `active=false` (la relación devolvía `null`). Fix mínimo: `$juego->pluginJuegos()->update(...)` (hasMany sin filtro). Es el GREEN del test `test_toggle_plugin_sincronizado_en_ambos_sentidos` — la spec REQ "Toggle del juego" exige persistir el estado, y tasks.md 4.1 exige "plugin sincronizado". Ninguna desviación de diseño.
- Panel: sin runner de tests en `panel/` (solo build). La verificación del cambio es lectura del diff + `pnpm run build` verde — reportado honestamente en la evidencia (no hay check ligero de tests del panel en el repo).
- Nota de la copia bundled: se dejó la nota en §9.1 (contrato literal de tasks.md 4.4); NO se tocó `taquilla/src/data/juegos.json` ni código de `taquilla/` (regla del slice).

## Issues (S3)

- El único fallo RED fue el defecto real de `toggle()` descrito arriba (no un fallo del test). Corregido con el cambio mínimo.
- `pnpm install` del panel regeneró/actualizó `node_modules/` local (ignorado por git; `dist/` también ignorado) — sin cambios versionados.

## Next Steps (tras S3)

- S4 (editor P2 de premios en `juegos.astro`: base/modalidades/comodines, errores junto al campo, `PUT /juegos/{id}/premios`) — fuera de este slice.
- S3 listo para el PR #4 de la feature-branch-chain (base: PR #3 S2).

---

# Slice S4 — Editor P2 de premios en el panel (último slice del cambio)

**Rama**: `feat/configuracion-juegos-s4` (base: `feat/configuracion-juegos-s3`, HEAD `a2ca9aa`)
**Fecha**: 2026-09-28 · **Modo**: Strict TDD — con la honestidad de que el panel NO tiene runner de tests: el RED de 5.1 es el smoke de estado (build verde + editor ausente) y la verificación del GREEN es build + lectura del flujo compilado.

## Estado

`success` — S4 completo (3/3 tareas). Con este slice quedan TODOS los slices del cambio (S1a, S1b, S2, S3, S4) completos; el cambio `configuracion-juegos` está listo para `sdd-verify`.

## TDD Cycle Evidence (S4)

| Tarea | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|-------|-----------|-------|------------|-----|-------|-------------|----------|
| 5.1 | — (panel sin runner; smoke = build + lectura) | — | ✅ Build previo verde (25 páginas) | ✅ Smoke: `juegos.astro` no tenía editor (solo tabla + toggle); build verde sin editor | N/A (estado previo confirmado) | N/A | N/A |
| 5.2 | — (panel sin runner; verificación = build + lectura del flujo) | — | ✅ Build previo verde | ✅ Estado previo leído: tabla sin editor, `apiFetch` descartaba `errors` del body 422 | ✅ Editor en `juegos.astro` (modal por clic en fila): base (number), modalidades (clave:valor con sugerencias de `/reglas`), comodines (tipo/valor/acumulativo solo con palabra), Guardar → `PUT /juegos/{id}/premios` con set completo, errores del backend junto al campo (`message` + `errors`), auditoría (`auditoria[].user`, before/after para `accion=premios`), `vendible` espejo de solo lectura de `active` (D5) | ✅ 5 caminos: carga desde `config.premios` (GET /juegos/{id}); sugerencias plugin ∪ claves configuradas (D3); payload plano `{base, modalidades, comodines}` (contrato verificado del endpoint); errores 422 mapeados a base/fila; auditoría before/after | ✅ `pnpm run build` verde; sin cambios de estilo pendientes (Pint no aplica a panel) |
| 5.3 | `pnpm run build` | Build | ✅ 25 páginas baseline | N/A | ✅ `pnpm run build` → `25 page(s) built in ~1.9s` · Complete! | ✅ Bundle `/juegos` inspeccionado: contiene `editor-modal`, `premios-form`, PUT `/juegos/{id}/premios`, `showErrors(err.message, err.errors)`, filas kv de modalidades/comodines, auditoría | ✅ Sin refactor adicional |

## Test Summary (S4)

- **Panel sin runner de tests** (hecho conocido y reportado honestamente, igual que en S3): la verificación del slice es (a) `pnpm run build` verde y (b) lectura del flujo — tanto del fuente como del bundle compilado (`dist/_astro/hoisted.*.js`).
- **Evidencia de lectura del flujo (contrato del PUT)**:
  - Payload enviado: `{base, modalidades: {clave: valor}, comodines: {clave: {tipo, premio_multiplo, acumulativo?}}}` — body PLANO, idéntico al contrato verificado de `JuegoController::updatePremios()` (`$request->only(['base','modalidades','comodines'])`; `base required|integer|min:1`; `comodines.*.tipo in:flag,letra,numero,palabra`; `comodines.*.premio_multiplo required|integer|min:1`; `acumulativo` solo con `tipo=palabra`).
  - Errores: `apiFetch` ahora adjunta `errors` + `status` al `Error` lanzado (cambio aditivo, `err.message` intacto); el editor muestra `message` en la caja general y mapea `errors` por campo (`base` → bajo el input; `modalidades.<clave>` / `comodines.<clave>.<campo>` → bajo la fila correspondiente; el resto se lista en la caja).
  - Auditoría: `GET /juegos/{id}` → `auditoria[]` con `user` (name/email); render por entrada con `accion`, usuario y fecha; las de `accion=premios` expanden `cambios.before`/`after` en JSON.
  - Sugerencias de modalidad: `GET /juegos/{id}/reglas` → `modalidades[].code` ∪ claves ya configuradas (canónicas sin plugin, D3).
  - `vendible` mostrado como espejo de solo lectura de `active` (D5/REQ6) — sin columna ni semántica nueva.

## Files Changed (S4)

| File | Action | What Was Done |
|------|--------|---------------|
| `panel/src/pages/juegos.astro` | Modified | Editor de premios completo (modal por clic en fila, ~300 líneas): base, modalidades (clave/valor + datalist de sugerencias), comodines (tipo/valor/acumulativo condicional), guardar → PUT con set completo, errores `message`+`errors` junto al campo, auditoría (user, before/after), `vendible` espejo de solo lectura |
| `panel/src/utils/api.ts` | Modified | `apiFetch` adjunta `errors` + `status` al Error lanzado en respuestas no-OK (Laravel 422); `err.message` intacto para el resto del panel |
| `openspec/changes/configuracion-juegos/tasks.md` | Modified | S4 marcada `[x]` (5.1–5.3) |
| `openspec/changes/configuracion-juegos/apply-progress.md` | Modified | Merge: secciones S1a/S1b/S2/S3 intactas + sección S4 añadida |

## Work Unit Evidence (S4)

| Evidence | Required value |
|---|---|
| Focused test command and exact result | `pnpm run build` (panel) → `25 page(s) built in ~1.9s` · `Complete!` (2 runs: baseline pre-editor y post-editor; ambos verdes). No hay comando de tests del panel en el repo (hecho reportado) |
| Runtime harness command/scenario and exact result | No hay runtime boundary automatizable del panel (sin runner, sin e2e). Verificación por lectura del flujo compilado: `dist/_astro/hoisted.*.js` del bundle de `/juegos` contiene `PUT "/juegos/"+id+"/premios"` con payload `{base, modalidades:{}, comodines:{}}`, `showErrors(err.message, err.errors)`, `GET /juegos/{id}` + `GET /juegos/{id}/reglas` en paralelo, render de auditoría con before/after. Smoke manual descrito en Next Steps. |
| Rollback boundary | Revertir `feat/configuracion-juegos-s4`: 2 archivos del panel (`juegos.astro`, `api.ts`) + docs sdd. `apiFetch` es aditivo (el resto del panel no depende de `errors`); sin cambios de backend ni de contrato |

## Commits (S4)

| Hash | Mensaje | Contenido |
|------|---------|-----------|
| (ver `git log`) | `feat(panel): editor de premios` | `juegos.astro` (editor modal) + `api.ts` (errores 422 con `errors`) |
| (ver `git log`) | `docs(sdd): cierre del slice S4 de configuracion-juegos` | `tasks.md` `[x]` + `apply-progress.md` merge |

NO push (regla del slice; PR #5 de la feature-branch-chain lo hará el orchestrator).

## Deviations (S4)

- **Forma del payload**: el prompt del orchestrator decía "set completo `{premios:{...}}`"; el contrato VERIFICADO del endpoint (design D1, spec REQ "el body ES el objeto premios completo", y tests S1a) es el body PLANO `{base, modalidades, comodines}` (el controlador hace `$request->only([...])`). Se envió el body plano; envolverlo en `premios:` habría roto el contrato (422 `base required`).
- **P2 del doc integración sugería checkbox `vendible` editable**; el alcance y D5 exigen `vendible` como espejo de solo lectura de `active` (sin columna). Se muestra en modo lectura.
- Panel sin runner de tests: la verificación es build + lectura del flujo compilado (misma honestidad que S3); no hay tests de panel que escribir.

## Issues (S4)

- Ninguno. El build pasó a la primera en ambos runs. `.codegraph/` queda como único artefacto no versionado (ignorado).

## Next Steps (tras S4 — fin del cambio)

- **Cambio completo**: S1a (5/5) + S1b (3/3) + S2 (6/6) + S3 (5/5) + S4 (3/3) = 22/22 tareas `[x]` en `tasks.md`.
- `sdd-verify` (orquestador): verificar el cambio completo contra spec/design/tasks; smoke manual sugerido del editor (abrir `/juegos`, clic en fila, editar base/modalidades/comodines, guardar, ver errores 422 junto al campo, ver auditoría nueva).
- PR #5 de la feature-branch-chain (base: PR #4 S3) con este slice; al cerrar el tracker `feat/configuracion-juegos` → `main`, ejecutar `php artisan juegos:export` y coordinar la copia bundled de taquilla (nota §9.1 de `docs/motor-premios.md`).