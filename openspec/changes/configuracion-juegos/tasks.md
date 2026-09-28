# Tasks: Configuración de juegos — edición de premios con snapshot no retroactivo

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: feature-branch-chain
400-line budget risk: High
800-line budget risk: Exceeded (~870-1.170)
Delivery strategy: auto-chain

### Suggested Work Units

| # | Unit | PR | Focused test | Harness | Rollback |
|---|------|----|-------------|---------|----------|
| S1a | Endpoint+servicio+espejos+auditoría | 1 (base: feat) | `JuegoPremiosApiTest` | `php artisan test --filter=JuegoPremiosApiTest` | Revertir ruta/servicio |
| S1b | Integración espejos/reglas/export | 2 (base: PR1) | `JuegoPremiosApiTest\|JuegosJsonTest` | `--filter='JuegoPremiosApiTest\|JuegosJsonTest'` | Tests sin side effect |
| S2 | Migración+snapshot+override+pago | 3 (base: PR2) | `PremioSnapshotTest\|PremiosEngineTest\|VerificarGanadoresTest` | `--filter='PremioSnapshotTest\|...'` | `migrate:rollback` |
| S3 | Fix toggle+tests deuda+re-export | 4 (base: PR3) | `JuegoToggleTest\|JuegoUpdateTest`+`npm run build` | Build + test | Revertir panel |
| S4 | Editor P2 premios panel | 5 (base: PR4) | `npm run build`+smoke | Build + smoke manual | Revertir panel |

---

## S1a: Servicio + endpoint + espejos + auditoría (~180 líneas)

- [ ] 1.1 **RED**: `JuegoPremiosApiTest` (~12 tests): unión plugin∪catálogo, clave inválida, la-ricachona, 200 body completo, 403 rol, 422 base/tipo/acumulativo/valor, clave canónica sin plugin, merge preserva scraper, auditoría before/after.
- [ ] 1.2 **GREEN**: Crear `app/Services/PremiosConfigService.php` — `clavesModalidadValidas(Juego)`, `actualizar(Juego,array,int):Juego` (validar→merge→espejos→auditar `accion=premios`).
- [ ] 1.3 **GREEN**: `PremiosOficiales::espejosLegacy(string,array):array` — extrae lógica de `configPara`; `configPara()` delega en este método.
- [ ] 1.4 **GREEN**: `JuegoController::updatePremios()` + `PUT /api/v1/juegos/{juego}/premios` en `routes/api.php` L120-123 (grupo `role:super_master|master`).
- [ ] 1.5 **REFACTOR**: `vendor/bin/pint --test`; `MotorPremiosRegresionTest`.
## S1b: Tests de integración (~100 líneas)

- [ ] 2.1 **RED**: `JuegoPremiosApiTest`: espejos (`premio_multiplo`=base, `config.modalidades` espejo, `config.comodines`), `/reglas`. `JuegosJsonTest`: export refleja premios editados.
- [ ] 2.2 **GREEN**: Tests integración: verificar `accion=premios` con `before/after`/`updated_by`; espejos sincronizados; reglas+export reflejan cambios.
- [ ] 2.3 **REFACTOR**: `vendor/bin/pint --test` + suite S1a+S1b completa.

## S2: Snapshot por apuesta (~250 líneas)

- [ ] 3.1 **RED**: `PremioSnapshotTest`: snapshot al vender, edición posterior no altera, pago usa snapshot, fallback legacy sin snapshot.
- [ ] 3.2 **GREEN**: Migración `2026_09_28_000001_add_premios_snapshot_to_detalle_apuestas_table.php` — `premios_snapshot` JSON nullable `after('premio_ganado_usd')`. `DetalleApuesta`: fillable+cast `'array'`.
- [ ] 3.3 **GREEN**: `ApuestaService::createApuesta()` persiste `premios_snapshot` en `DetalleApuesta::create`.
- [ ] 3.4 **GREEN**: `PremiosEngine::calcular/premioPosible/multiplicadorPara/multiplicadorConComodines` + `JuegoPluginManager::calcularPremio` aceptan `?array $premios = null`.
- [ ] 3.5 **GREEN**: `ApuestaService::verificarGanadores()` carga `with('detalles')`, pasa `premios_snapshot ?? null`. `PagoController::calcularPremio()` usa snapshot con fallback legacy.
- [ ] 3.6 **REFACTOR**: `vendor/bin/pint --test` + `PremiosEngineTest` + `VerificarGanadoresTest` + `MotorPremiosRegresionTest`.

## S3: Fix toggle + tests deuda + re-export (~100 líneas)

- [ ] 4.1 **RED**: `JuegoToggleTest` (toggle 200/422/audita/plugin sincronizado) + `JuegoUpdateTest` (update audita `accion=actualizar`).
- [ ] 4.2 **GREEN**: `panel/src/pages/juegos.astro` L31: `apiFetch('PATCH','/juegos/'+id+'/toggle', {active: !active})` + `catch(err){alert('Error: '+err.message)}`.
- [ ] 4.3 **GREEN**: `JuegoToggleTest` y `JuegoUpdateTest` pasan; `npm run build` verde.
- [ ] 4.4 **GREEN**: Nota en `docs/motor-premios.md` §9.1: "Tras editar premios, `juegos:export` y coordinar copia `docs/juegos.json` → `taquilla/src/data/juegos.json`."
- [ ] 4.5 **REFACTOR**: `vendor/bin/pint --test` + `npm run build`.
## S4: Editor P2 premios en panel (~180 líneas)

- [ ] 5.1 **RED**: `npm run build` + smoke: editor no existe al expandir fila.
- [ ] 5.2 **GREEN**: Editor en `panel/src/pages/juegos.astro` (clic en fila): `base`(number), `modalidades`(clave:valor), `comodines`(tipo/valor/acumulativo), guardar→`PUT /juegos/{id}/premios`, errores 422 junto al campo.
- [ ] 5.3 **GREEN**: `npm run build` verde.
