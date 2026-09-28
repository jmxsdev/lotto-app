# Apply Progress: configuracion-juegos — Slice S1a

**Cambio**: `configuracion-juegos` · **Slice**: S1a (servicio + endpoint + espejos + auditoría)
**Rama**: `feat/configuracion-juegos-s1a` (base: `feat/configuracion-juegos`, tracker con artefactos)
**Fecha**: 2026-09-28 · **Modo**: Strict TDD (RED → GREEN → REFACTOR)

## Estado

`success` — S1a completo (5/5 tareas). S1b/S2/S3/S4 sin tocar.

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