```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:dbf20a69fae891a88a5f2b5776aa2528014a24c20f044059b7525514f22d561b
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 10/10
scenarios: 20/20
test_command: php artisan test --parallel --processes=4 --display-warnings --log-junit junit.xml
test_exit_code: 0
test_output_hash: sha256:d6f893aea0b2b94db21697da920cb92c9bc4974f6c3b8f224278fd00cda2882b
build_command: docker build --pull -t ghcr.io/jmxsdev/lotto-app-api:5f57c98 -t ghcr.io/jmxsdev/lotto-app-api:latest .
build_exit_code: 0
build_output_hash: sha256:ac96dc4965c4fd3544d4b7d24968106171afc24a1d8cd6dbef61332740d6c0f5
```

# Verification Report — autorizacion-rol-unico (issue #69)

**Change**: `autorizacion-rol-unico`
**Modo**: Standard (sin runner Strict TDD activo en esta fase)
**Commit verificado**: `5f57c98` (`feat(rol): elimina users.role (WU-7)`), sobre Merge 1 `4a2f183`
**Fecha**: 2026-11-03

## Veredicto global

**PASS WITH WARNINGS**

La implementación cumple los 9 requisitos de la capability nueva y el delta de `jerarquia-agencias`. Spatie es la única fuente de rol, `users.role` fue eliminada, el contrato de login/API se conserva con el accessor calculado, las 6 queries ocultas están migradas y la auditoría existe. La suite completa y el pipeline CI/CD están en verde, y el VPS dev fue reconstruido sin la columna. No hay hallazgos CRITICAL.

Las advertencias son de cobertura operativa y de documentación pendiente de archivo, no de comportamiento implementado:

- La spec base `openspec/specs/jerarquia-agencias/spec.md` todavía exige "ambas fuentes"; el delta se aplica recién en `sdd-archive`.
- `down()` de la migración de drop está implementado pero no se ejecutó (prohibición de migraciones en esta verificación).
- No hubo drill de rollback M1 → pre-M1.
- El test de auditoría no afirma explícitamente `ip`/`user_agent`, aunque el código los persiste.
- #84, #85, #89 y BE-04 siguen fuera de alcance por diseño.

## Completitud

| Métrica | Valor |
|---|---|
| Tareas totales | 38 |
| Tareas completas | 38 |
| Tareas incompletas | 0 |
| WUs completados | 7/7 (WU-1..WU-6 = Merge 1; WU-7 = Merge 2) |

## Cumplimiento por requisito

| REQ | Veredicto | Evidencia | Notas |
|---|---|---|---|
| REQ-1 — Fuente única en Spatie | ✅ CUMPLE | `grep -rn "where(['\"]role['\"]" backend/app` → 0 resultados. Las 14 coincidencias de `->role` son el scope Spatie `role()` (`CierreService`, `UserController::index`, `BancaController`, `AgenciaController`, `AgenciasBackfill`) o el parámetro `$request->role`; ninguna lee la columna. `DivergenciaRolTest` prueba autorización por Spatie. | Sin lecturas ni escrituras de columna como fuente. |
| REQ-2 — Accessor calculado `role` | ✅ CUMPLE | `User.php`: `$appends = ['role']`, `getRoleAttribute()` → `primaryRole()`, prioridad `super_master > master > banca > grupo > agencia > taquilla`, `null` sin rol. `UserRoleAccessorTest` (6 tests) cubre Spatie, `null`, prioridad, `toArray` y factory. | El accessor nunca consulta la columna; además la columna ya no existe. |
| REQ-3 — Eliminación de `users.role` | ⚠️ CUMPLE CON OBSERVACIÓN | Migración `2026_11_03_000001_drop_role_from_users_table.php` con `Schema::hasColumn` en `up()`/`down()`; `users.role` eliminada en VPS dev; CI corre migraciones sobre MySQL 8 limpio. | `down()` reversible por inspección, no ejecutado en esta fase; restaurar datos requiere dump. |
| REQ-4 — Fail-closed | ✅ CUMPLE | `Api\AuthController` deniega login sin rol (403); `ReporteController`, `EstadisticaController`, `ApuestaController`, `ComisionController`, `JuegoController` y `TicketController` terminan en `abort(403)` o scope vacío (`whereRaw('1=0')`, colección vacía). `DivergenciaRolTest > test_usuario_sin_rol_spatie_recibe_403`. | Sin fallback global ni respaldo de columna. |
| REQ-5 — Login/API preserva `role` | ✅ CUMPLE | Login devuelve `role => primaryRole()` y `user` serializa el accessor; `Admin\Auth\AuthController` usa `hasAnyRole` y `session('user_role')` con `primaryRole()`. `VerifyMac` usa `hasRole('taquilla')`. Tests: `test_login_panel_devuelve_role_de_spatie`, `test_login_taquilla_usa_spatie_rol`, `test_verify_mac_usa_spatie_taquilla`. | Shape de API sin cambios para el frontend. |
| REQ-6 — 6 queries ocultas migradas | ✅ CUMPLE | `CierreService` (`role('banca'|'master'|'super_master')`), `AgenciaController::destroy` (`users()->role('taquilla')`), `UserController::index` (`$query->role($filtros['role'])`), `AgenciasBackfill` (`role('super_master')`, `role('taquilla')`), `E2eSeeder` (`role('super_master')`, `role('banca')`). Búsqueda global sin `where('role',...)`. | `test_index_filtra_por_rol_spatie` cubre el filtro. |
| REQ-7 — Auditoría `user.role_changed` | ⚠️ CUMPLE CON OBSERVACIÓN | `UserController::update` emite `Log` con `action='user.role_changed'`, `user_id` actor, `details` (`actor_id`, `target_id`, `target_email`, `before`, `after`), `ip` y `user_agent`, dentro de la transacción. `$cambioRol` compara el set completo. `AuditoriaRolTest` (5 tests) cubre cambio, sin cambio, actor/target/before/after, `details` array y 403. | El test no afirma `ip`/`user_agent`; el código los escribe y `Log` los persiste (`$casts details=array`). Es brecha de aserción, no de implementación. |
| REQ-8 — Rollout expand/contract + wipe/reseed | ⚠️ CUMPLE CON OBSERVACIÓN | Merge 1 `4a2f183` y Merge 2 `5f57c98` desplegados con runs verdes; VPS dev con `migrate:fresh --seed` OK; panel login `super@lotto.com` OK; alta de usuario OK; backup previo validado. | No se ejecutó un drill de rollback M1 → pre-M1; queda como procedimiento documentado. |
| REQ-9 — Tests de divergencia y rol | ✅ CUMPLE | `UserRoleAccessorTest` (6), `UsuarioRolApiTest` (6), `AuditoriaRolTest` (5), `DivergenciaRolTest` (8). Suite completa: **1355 tests, 7794 assertions, 2 skipped esperados, OK**. | Los 4 casos de divergencia del proposal están cubiertos como contrato Spatie post-drop. |

### Delta `jerarquia-agencias`

| Escenario | Veredicto | Evidencia |
|---|---|---|
| Registro único en Spatie | ✅ COMPLIANT | `UserRoleAccessorTest` con `withRole('agencia')`; suites `Agencia*`, `RoleAuthorizationTest` y `LimitesScopedApiTest` asignan `agencia` solo en Spatie y autorizan correctamente. |
| Divergencia con la columna legacy | ✅ COMPLIANT (post-contract) | La columna ya no existe; grep sin lecturas. El escenario queda estructuralmente garantizado por REQ-3. |

## Matriz de escenarios (specs recuperadas)

| REQ | Escenario | Test / evidencia | Resultado |
|---|---|---|---|
| REQ-1 | Consulta de rol usa Spatie | `DivergenciaRolTest > test_comision_consulta_usa_spatie_master` | ✅ COMPLIANT |
| REQ-1 | Divergencia histórica resuelta | `DivergenciaRolTest > test_comision_consulta_usa_spatie_master` (post-drop, sin columna) | ✅ COMPLIANT |
| REQ-2 | Accessor refleja Spatie | `UserRoleAccessorTest > test_accessor_devuelve_rol_spatie` + `test_role_se_serializa_en_to_array` | ✅ COMPLIANT |
| REQ-2 | Usuario sin rol Spatie | `UserRoleAccessorTest > test_accessor_devuelve_null_sin_rol` | ✅ COMPLIANT |
| REQ-3 | Post-contract | Migración aplicada en CI/VPS + grep sin referencias | ✅ COMPLIANT |
| REQ-3 | Reversión de esquema | `down()` inspeccionado (`Schema::hasColumn` + re-add nullable/default), no ejecutado | ✅ COMPLIANT (observación: sin ejecución) |
| REQ-4 | Usuario sin rol Spatie | `DivergenciaRolTest > test_usuario_sin_rol_spatie_recibe_403` | ✅ COMPLIANT |
| REQ-4 | Sin fallback global | Mismo test + grep sin lecturas de columna | ✅ COMPLIANT |
| REQ-5 | Login taquilla | `DivergenciaRolTest > test_login_taquilla_usa_spatie_rol`; `UsuarioRolApiTest > test_login_panel_devuelve_role_de_spatie` | ✅ COMPLIANT |
| REQ-5 | VerifyMac consistente | `DivergenciaRolTest > test_verify_mac_usa_spatie_taquilla` | ✅ COMPLIANT |
| REQ-6 | Búsqueda global | `grep` en `app/`: 0 `where('role',...)` | ✅ COMPLIANT (estático) |
| REQ-6 | Scoping por rol | `UsuarioRolApiTest > test_index_filtra_por_rol_spatie` | ✅ COMPLIANT |
| REQ-7 | Cambio de rol registrado | `AuditoriaRolTest > test_cambio_de_rol_registra_user_role_changed` + `test_log_incluye_actor_target_before_after`; `ip`/`user_agent` verificados por inspección de `UserController` | ✅ COMPLIANT (observación: sin aserción de IP/UA) |
| REQ-7 | Sin cambio de rol | `AuditoriaRolTest > test_mismo_rol_no_registra_log` | ✅ COMPLIANT |
| REQ-8 | Rollback del expand | Dos deploys exitosos (M1 sin cambio de esquema y M2); sin drill de reversión | ✅ COMPLIANT (observación: sin drill) |
| REQ-8 | Reconstrucción dev | `migrate:fresh --seed` en VPS dev, login y alta de usuario OK | ✅ COMPLIANT (manual) |
| REQ-9 | Divergencia cubierta | 4 casos en `DivergenciaRolTest` + suites de comisiones/apuestas/reportes/VerifyMac | ✅ COMPLIANT |
| REQ-9 | Suite verde | CI `Tests y estilo`: 1355 tests OK | ✅ COMPLIANT |
| jerarquia-agencias | Registro único en Spatie | `UserRoleAccessorTest` + suites `Agencia*`/`RoleAuthorizationTest` | ✅ COMPLIANT |
| jerarquia-agencias | Divergencia con columna legacy | Columna eliminada; grep sin lecturas | ✅ COMPLIANT |

**Resumen**: 20/20 escenarios verificados; 3 con observación no bloqueante (reversión de esquema por inspección, aserción IP/UA, drill de rollback). 0 failing, 0 untested.

## Evidencia de ejecución

### Tests y estilo (CI, no re-ejecutados por el verificador)

Run `41669583139` (commit `5f57c98`), job `Tests y estilo`:

```text
PASS   ......................................................... 373 files
OK, but some tests were skipped!
Tests: 1355, Assertions: 7794, Skipped: 2.
Time: 10:06.124
```

- `vendor/bin/pint --test`: PASS (373 archivos).
- `composer test` reportado por el usuario: mismos totales (1355/7794), OK; skips esperados de `PluginIntegrationTest`.
- Hash del log exacto del job: `sha256:d6f893aea0b2b94db21697da920cb92c9bc4974f6c3b8f224278fd00cda2882b`.

### CI/CD completo

| Run | Commit | Tests | Front | E2E | Build | Deploy |
|---|---|---|---|---|---|---|
| `41667153232` (Merge 1) | `4a2f183` | success | success | success | success | success |
| `41669583139` (Merge 2) | `5f57c98` | success (11m15s) | success | success | success | success |

- Build: `docker build --pull -t ghcr.io/jmxsdev/lotto-app-api:5f57c98 -t ghcr.io/jmxsdev/lotto-app-api:latest .` → OK; push de `:5f57c98` y `:latest`.
- Deploy al VPS: contenedores `lotto_api_prod`, `lotto_mysql_prod`, `lotto_caddy_prod`, `lotto_horizon_prod`, `lotto_scheduler_prod` arrancados; healthcheck `*** OK`.
- Hash del log exacto del job de build: `sha256:ac96dc4965c4fd3544d4b7d24968106171afc24a1d8cd6dbef61332740d6c0f5`.

### VPS dev (evidencia del usuario)

- `php artisan migrate:fresh --seed` ejecutado post-Merge 2 sin errores.
- `users.role` eliminada; esquema consistente sin la columna.
- Login de panel con `super@lotto.com` OK.
- Alta de usuario nuevo OK.
- Backup pre-cambio validado: `/home/deploy/backups/lotto-pre-rol-unico-20261103-155003.sql.gz`, SHA-256 `332ba744...`, 37 tablas, `gzip -t` OK.

## Coherencia con el diseño

| Decisión | Cumplida | Notas |
|---|---|---|
| D1 — Spatie única fuente | ✅ | Sin lecturas/escrituras de columna. |
| D2 — Accessor no persistido | ✅ | `$appends`, `getRoleAttribute()`, `primaryRole()`. |
| D3 — `primaryRole()` con prioridad fija | ✅ | Constante `ROLE_PRIORITY` y fallback alfabético. |
| D4 — Sin rol ⇒ `null` + fail-closed | ✅ | Login 403; scopes vacíos; controllers con `abort(403)`. |
| D5 — Conversión por ramas `hasRole` | ✅ | Orden preservado en los controllers. |
| D6 — `role` fuera de `$fillable` | ✅ | Sin escritura accidental. |
| D7 — `syncRoles` en transacción | ✅ | `store` y `update` en `DB::transaction`. |
| D8 — Auditoría en `logs` | ✅ | `details` array, sin doble JSON; `ip`/`user_agent` persistidos. |
| D9 — Merge 1 sin migración | ✅ | Sin migración en `4a2f183`. |
| D10 — Drop idempotente y reversible | ✅ | `Schema::hasColumn` en `up()` y `down()`. |
| D11 — Factory sin rol implícito | ✅ | `withRole()` con `syncRoles`; `forAgencia()` asigna `taquilla`. |
| D12 — 7 WUs apilados | ✅ | Historial de commits y PRs por WU. |

**Desviaciones documentadas, no bloqueantes**:

- El test `test_accessor_ignora_columna_legacy_divergente` (diseño §6) fue eliminado en WU-7 porque la columna ya no existe; `apply-progress.md` lo registra. Quedan 6 tests en `UserRoleAccessorTest` en lugar de 7.
- La migración se llama `2026_11_03_000001_...` en lugar del placeholder `2026_11_10_000001_...` del diseño; el propio diseño indicaba ajustar a la fecha real del merge.
- `DivergenciaRolTest` pasó de inyectar una columna divergente a probar el contrato Spatie post-drop; los casos siguen cubriendo los 4 escenarios del proposal.

## Drift entre artefactos y código

| Artefacto | Estado | Detalle |
|---|---|---|
| `proposal.md` | ✅ Sin drift material | Objetivos, alcance y no-objetivos reflejan el resultado; #84/#85/#89/BE-04 siguen fuera. |
| `design.md` | ✅ Coherente | Placeholder de fecha y test legacy están explicados; el resto se cumple. |
| `tasks.md` | ✅ 38/38 | Sin tareas pendientes. Los checkboxes de WU-0 y criterios globales están actualizados en el working tree local, aún sin commit. |
| `specs/autorizacion-rol-unico/spec.md` | ⚠️ Pendiente de archivo | Los escenarios de "columna divergente" son literales de la etapa expand; post-contract la columna no existe. Conviene ajustar la redacción al archivar. |
| `specs/jerarquia-agencias/spec.md` (delta) | ✅ Correcto | Reemplaza "ambas fuentes" por "solo Spatie". |
| `openspec/specs/jerarquia-agencias/spec.md` (base) | ⚠️ Drift esperado | Todavía dice "AMBAS fuentes" porque `sdd-archive` aplica el delta. Debe resolverse antes de cerrar el change. |
| `openspec/specs/autorizacion-rol-unico/` | ⚠️ Pendiente de archivo | La capability nueva aún no existe en `openspec/specs/`; se crea en `sdd-archive`. |
| Docs (`arquitectura-backend.md`, `motor-cierres-comisiones.md`) | ✅ Actualizados | Documentan Spatie como fuente única y marcan FIXED el hallazgo de rol inconsistente. |

## Warnings y riesgos residuales

1. **Spec base sin archivar**: `openspec/specs/jerarquia-agencias/spec.md` sigue exigiendo columna + Spatie. Es el paso pendiente de `sdd-archive`; no afecta el código desplegado, pero el change no puede considerarse cerrado hasta aplicar el delta.
2. **`down()` no ejecutado**: la migración de drop es reversible por inspección (`Schema::hasColumn` + re-add nullable/default), pero no se corrió. Restaura el esquema vacío; los datos de rol viven en Spatie y no se reconstruyen desde la columna.
3. **Rollback M2 → pre-M1**: una imagen anterior al Merge 1 volvería a leer `users.role`, que ya no existe. Solo es seguro revertir a la imagen M1 (`4a2f183`). Para volver a pre-M1 haría falta `down()` + restore de dump.
4. **Rollback M1 → pre-M1 sin drill**: el plan exige re-sincronizar `users.role` desde Spatie antes de revertir; no se ejecutó esa prueba.
5. **Cobertura de IP/UA en auditoría**: `AuditoriaRolTest` no asserta `ip` ni `user_agent`; el código los escribe (`UserController` líneas 248-249) y `logs` los persiste. Se recomienda agregar la aserción en una iteración menor.
6. **Escenarios de divergencia post-contract**: al no existir `users.role`, los escenarios que describen una columna divergente no son reproducibles; los tests prueban el contrato Spatie. Ajustar la redacción de la spec al archivar evita una promesa que ya no aplica.
7. **#84 fuera de alcance**: `TicketController` conserva alcance global para `master`/`agencia`, y `PagoController`/`ApuestaPolicy` mantienen el cross-tenant de banca. Está documentado y no se "arregló" por accidente.
8. **#89 fuera de alcance**: solo `UserController::update` audita; `store` (alta de usuario) no emite `user.role_changed`. La auditoría completa queda para su issue.
9. **N+1 del accessor**: serializar listas de usuarios ejecuta `getRoleNames()` por fila; los endpoints existentes cargan `roles`, pero endpoints nuevos deben hacer eager-load.
10. **CI no gatea ramas feature**: el pipeline corre solo en `main`; la verificación real ocurre al mergear. Los runs verificados son los de `main`.
11. **Higiene de artefactos**: los checkboxes de `tasks.md` y otros cambios locales (`CHANGELOG.md`, docs) están sin commit en `main`. Deben commitearse con el cierre/archivo para que el estado verificado quede trazable.
12. **Backup reportado por el usuario**: la validación del dump (`332ba744...`, 37 tablas, gzip OK) es evidencia del VPS; no fue re-verificada desde este host.

## Confirmación CRITICAL

**No hay hallazgos CRITICAL.** No hay tareas pendientes, la suite completa pasa, el pipeline de Merge 1 y Merge 2 está verde, el deploy y el healthcheck funcionan, y no quedan lecturas ni escrituras de `users.role` como fuente. Las observaciones listadas son warnings de cobertura/ciclo de vida documental.

## Veredicto

**PASS WITH WARNINGS** — implementación completa y desplegada; quedan warnings de cobertura operativa y el archivo de specs como siguiente paso obligatorio.
