# Apply Progress: configuracion-juegos — Slices S1a + S1b

**Cambio**: `configuracion-juegos` · **Slices completados**: S1a (servicio + endpoint + espejos + auditoría) y S1b (tests de integración end-to-end)
**Ramas**: `feat/configuracion-juegos-s1a` (base: `feat/configuracion-juegos`, tracker con artefactos) · `feat/configuracion-juegos-s1b` (base: S1a)
**Fecha**: 2026-09-28 · **Modo**: Strict TDD (RED → GREEN → REFACTOR)

## Estado

`success` — S1a completo (5/5 tareas) + S1b completo (3/3 tareas). S2/S3/S4 sin tocar.

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