# Apply Progress — comisiones (revenue-share ledger)

**Status**: success — Apply 100% complete en `feat/comisiones` (worktree lotto-app-worktrees/comisiones). Todas las tareas implementadas y verificadas; la suite completa (6.2) quedó verde en el cierre S7.

## Completed tasks (cumulative, S1→S6)

### Slices 1–3 (S1–S3 config/lectores/cálculo)
- [x] 1.1–1.6 Config default: migración `comision_defaults` (bs/usd), modelo `ComisionDefault`, RED `ComisionesApiTest`, `ComisionController` + rutas `GET/PUT /api/v1/comisiones/defaults`, bloque global aditivo en `limites.astro`, pint.
- [x] 2.1–2.5 Lectores: `tasaPropia`/`tasaEfectiva`/`tasaLiquidable` en `ComisionService` con RED previo (D2, D11: tope acumulado `100 − Σ tasas propias ancestros`, por moneda, piso 0).
- [x] 3.1–3.3 Cálculo/previsualización: `comisionEntidad` (buckets por moneda, redondeo 2dp, excluye anuladas, rangos inclusivos), `comisionesReporte`, `previsualizar` (D6).

### Slice 4 (S4 ledger + API + panel)
- [x] 4.1–4.7 Migraciones `_000002` (rango fechas en `comisiones`) y `_000003` (`comision_bs_equivalent` en `cierres_caja`), `Comision.php`/`CierreCaja.php`, RED `ComisionesApiTest`, `liquidar` (D4 transacción + lock, D5 PATCH idempotente, D7 una fila por nivel/entidad/rango), rutas + permisos (D8), `comisiones.astro` + nav.

### Slice 5 (S5 reports/cierre + panel)
- [x] 5.1 RED `ComisionReporteTest` (6 tests, 42 asserts); 5.2 `ApuestaService` aditivo (D9: banca/agencia rollup, hallazgo F4); 5.3 RED `ComisionCierreTest` (7 tests, 32 asserts); 5.4 `CierreService` + `comision_bs_equivalent` (D10); 5.5 columnas `Comisión` en `reportes/ventas.astro` y `cuadre.astro`; 5.6 build panel 27 páginas OK.

### Phase 6 (regresión) — S6
- [x] 6.1 Regresión en 3 chunks (S16, S17 — límites/premios untouched): chunk 1 → 46 tests/243 asserts; chunk 2 → 64 tests/394 asserts; chunk 3 → 42 tests/85 asserts. Total 152/152 passed.
- [x] 6.2 Suite completa `DB_DATABASE=lotto_test_motor php artisan test` → **passed, 1128 tests, 1126 passed, 2 skipped, 0 failed, 6587 assertions, 3215755 ms (~53.6 min)**. (Primera corrida invalidada por timeout del runner a los 40 min — proceso vivo, no colisión de DB; re-ejecutada detached hasta completar.)

## Commits (feat/comisiones — 13)

1. `521a660` feat(api): tabla y endpoints de default global de comisiones
2. `f932af6` feat(comisiones): lectores de tasa y tope acumulado
3. `3968e0c` feat(panel): bloque de default global de comisiones en límites
4. `c9537b3` feat(api): rango de fechas en ledger de comisiones
5. `85407ef` feat(comisiones): cálculo por rango y previsualización con conflictos
6. `a63fab2` feat(api): columna de comisión en cierres de caja
7. `badf261` feat(api): liquidación, listado y pago de comisiones
8. `330201b` feat(panel): página de comisiones con liquidación y pagos
9. `701ddbd` feat(api): columna de comisión en reportes de ventas
10. `0b5d6d0` feat(api): desglose de comisión en cierre de caja
11. `a7c7d72` feat(panel): columnas de comisión en reportes y cuadre
12. `99e3cf3` chore(sdd): evidencia de regresión 6.1 (límites/premios sin cambios)
13. *(este batch)* chore(sdd): suite completa 6.2 y apply-progress

## Work Unit Evidence (cierre S7)

| Evidence | Value |
|---|---|
| Focused test command | `DB_DATABASE=lotto_test_motor php artisan test` → passed, 1128 tests, 1126 passed, 2 skipped, 6587 assertions, 3215755 ms |
| Runtime harness | N/A — suite Feature/Unit sobre la DB compartida es el harness (ver 6.1: 152/152 en 3 chunks) |
| Rollback boundary | Este batch toca SOLO `openspec/changes/comisiones/tasks.md` + `apply-progress.md` (docs, `.gitignore:44`); `git revert` no afecta código del cambio |

## Deviations from design

- Ninguna en código. 6.1 se ejecutó en 3 chunks (instrucción del orquestador) y 6.2 como suite completa en el cierre S7 — la nota inline de tasks.md que difería 6.2 a sdd-verify queda resuelta: la evidencia la produjo apply.

## Issues / risks

- **Timeout de runner (RESUELTO)**: la primera corrida de 6.2 fue cortada por el timeout del shell a los 40 min (sin colisión de DB — `pgrep` limpio antes de arrancar); se re-ejecutó con `nohup` detached hasta completar (53.6 min). Resultado válido sin errores.
- **DB compartida**: sin colisión en esta corrida (ventana limpia verificada con `pgrep` antes de arrancar). Lección previa (slice 5): la DB compartida no tolera suites paralelas de worktrees distintos.

## Remaining

- sdd-verify: verificación formal contra spec/design + confirmación de la evidencia de 6.2 y de las notas resueltas (reconfirmar supuestos i–iii y coordinación §D en archive/PR). Luego sdd-archive.