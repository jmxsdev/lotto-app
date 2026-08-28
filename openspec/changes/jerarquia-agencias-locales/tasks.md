# Tasks: Jerarquía de agencias (locales)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~1500–2500 (4 migraciones + 1 modelo nuevo + 5 modelos mod + 1 comando + 2 seeders + 3 factories + 1 controlador nuevo + 10 controladores mod + 1 policy + 2 services + rutas + middleware + ~10 tests + ~13 archivos panel) |
| 400-line budget risk | High |
| Configured review budget | 800 líneas (excedido ampliamente) |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 (F0) → PR 2 (F1) → PR 3 (F2) → PR 4 (F3) → PR 5 (F4) → PR 6 (F5) |
| Delivery strategy | auto-chain |
| Chain strategy | feature-branch-chain |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: feature-branch-chain
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | F0 datos: esquema + backfill idempotente | PR 1 (base `feature/jerarquia-agencias`) | `php artisan test --filter=AgenciasBackfillTest` | `php artisan migrate && php artisan agencias:backfill` (verificar 1 local/grupo) | `php artisan migrate:rollback --step=4` (down de 4 migraciones aditivas) |
| 2 | F1 scope agencia: login/policies/controllers/activación | PR 2 (base PR 1) | `php artisan test --filter=AgenciaScopeTest` | `php artisan test --filter=RoleAuthorizationTest` | revertir commits de ramas agencia (login, policies, controllers, routes) |
| 3 | F2 super banca: `whereIn banca_id` master | PR 3 (base PR 2) | `php artisan test --filter=SuperBancaScopeTest` | `php artisan test --filter=RoleAuthorizationTest` | quitar `whereIn master` (vuelve global) + revertir select master en bancas |
| 4 | F3 reportes: `nivel=agencia`=locals | PR 4 (base PR 3) | `php artisan test --filter=CuadreCajaReportTest` | `php artisan test --filter=ReporteTest` | revertir niveles/labels en `ApuestaService` |
| 5 | F4 panel: renames/CRUD/selectores | PR 5 (base PR 4) | `npm run build` (panel) | `npm run build` (panel no tiene suite e2e) | revertir commits panel (backend previo no rompe: todo nullable) |
| 6 | F5 endurecer: suite verde + pint | PR 6 (base PR 5) | `composer test` | `./vendor/bin/pint --test` | revertir limpieza de mensajes/labels residuales |

## Phase 1 (F0): Fundación de datos

- [x] 1.1 [TDD-RED] Crear `backend/tests/Unit/AgenciasBackfillTest.php`: 1ª ejecución crea 1 local/grupo y vincula taquillas+usuarios rol taquilla; 2ª no duplica; prod exige `--force`. Verificar falla: `php artisan test --filter=AgenciasBackfillTest`.
- [x] 1.2 [ALTO] Crear `2026_08_28_000001_create_agencias_table.php` (`code` único, `grupo_id` NOT NULL cascade, `active`, `created_by` set null, fiscal, softDeletes).
- [x] 1.3 [ALTO] Crear `000002_add_agencia_id_to_taquillas` y `000003_add_agencia_id_to_users` (nullable FK `set null` + index) y `000004_add_master_id_to_bancas` (nullable FK users). Down: drop FK+columna.
- [x] 1.4 Crear `backend/app/Models/Agencia.php` (fillable, casts `active`, SoftDeletes, `grupo()/taquillas()/users()/creator()`).
- [x] 1.5 Modificar `Taquilla.php`, `User.php` (+`agencia_id`/`agencia()`, fillable), `Grupo.php` (+`agencias()`), `Banca.php` (+`master_id`/`master()`); `User::masterBancaIds()`.
- [x] 1.6 Crear `AgenciaFactory.php` (code `A###`) + `TaquillaFactory`/`UserFactory` (`agencia_id` null + estado `forAgencia()`).
- [x] 1.7 Modificar `RolesAndPermissionsSeeder.php` (rol `agencia` en columna + Spatie + permisos) y `UsersSeeder.php` (`agencia@lotto.com`).
- [x] 1.8 [ALTO] [CLIENTE] Crear `AgenciasBackfill.php` (`{--force} {--dry-run}`, idempotente, log de resumen). GREEN 1.1.
- [x] 1.9 Verificar: `php artisan migrate && php artisan agencias:backfill` → 1 local/grupo, `taquillas.agencia_id`/`users.agencia_id` poblados.

## Phase 2 (F1): Alcance agencia

- [x] 2.1 [TDD-RED] Crear `AgenciaApiTest.php` (CRUD scoped) y `AgenciaScopeTest.php` (agencia solo su local, rechazo en otro local).
- [x] 2.2 Modificar `AuthController::login` (X-Panel admite `agencia`; taquilla → 403 "app de escritorio"; payload `agencia_id`) y `VerifyMac.php` (mensajes "agencia"→"taquilla" solo rol taquilla).
- [x] 2.3 Modificar `ApuestaPolicy.php` (`viewAny`/`view`/`delete` rama agencia vía `taquilla.agencia_id`).
- [x] 2.4 Modificar `TaquillaController.php` (`store` deriva `agencia_id=user.agencia_id` y `grupo_id`; `index` rama; nueva `authorizeAgenciaAccess`).
- [x] 2.5 Modificar `UserController.php` (validar `agencia_id`, `validateRoleBindings`, `authorizeEntityBinding` rama agencia).
- [x] 2.6 Modificar `ApuestaController`, `CierreController`, `JuegoController::limites` GET (rama agencia).
- [x] 2.7 Modificar `ReporteController`/`EstadisticaController` `buildApuestaQuery` (rama agencia).
- [x] 2.8 Modificar `ActivacionEfectivaService.php` (estadoTaquilla + agencia causa `'agencia'`; `mensajeCadenaInactiva` rama) + extender `ActivacionEntidadesTest`.
- [x] 2.9 Modificar `routes/api.php` (`role:agencia` en taquillas/limites GET; `apiResource('agencias')`) + crear `AgenciaController.php` (index/store/update/toggle/destroy scoped).
- [x] 2.10 [TDD-GREEN] Actualizar `RoleAuthorizationTest`, `GestionEntidadesApiTest` (filtro `agencia_id`), `LimitesScopedApiTest`, login X-Panel, `TerminologiaTest` (mensajes VerifyMac/controllers). `php artisan test`.

## Phase 3 (F2): Super banca (master scope)

- [ ] 3.1 [TDD-RED] Crear `SuperBancaScopeTest.php` (master ve solo sus bancas/descendientes; sin bancas = vacío `whereRaw('1=0')`; super global).
- [ ] 3.2 `User::masterBancaIds()` + `whereIn('banca_id', $ids)` en ~10 puntos: Banca/Grupo/Taquilla/User controllers, Reporte/Estadistica `buildApuestaQuery`+`buildTicketQuery`, Apuesta/Cierre/Juego::limites. Lista vacía ⇒ `whereRaw('1=0')`.
- [ ] 3.3 [TDD-GREEN] Actualizar `RoleAuthorizationTest` (master scoped). `php artisan test --filter=SuperBancaScopeTest`.
- [ ] 3.4 Panel: `bancas.astro` + `bancas/detalle.astro` (select master). `npm run build`.

## Phase 4 (F3): Reportes por local

- [ ] 4.1 [TDD-RED] Actualizar `CuadreCajaReportTest` y `ReporteTest` (nivel=agencia agrupa locals; nivel=taquilla máquinas).
- [ ] 4.2 Modificar `ApuestaService.php`: `ventasTotales`/`cuadreCaja` nivel `agencia` (joins `taquillas→agencias→grupos→bancas`, groupBy agencias) + nivel `taquilla`; `pagosCuadrePorNivel`; `rendimientoTaquillas` nivel agencia + label "Taquilla"; `relacionTickets`/`vencidos` labels.
- [ ] 4.3 [TDD-GREEN] Actualizar `EstadisticaTest` (serie temporal labels). `php artisan test --filter=ReporteTest`.

## Phase 5 (F4): Panel

- [ ] 5.1 `panel/src/utils/api.ts` (ROLES +`agencia`) y `login.astro` (ROLES_PERMITIDOS).
- [ ] 5.2 `AdminLayout.astro`: sidebar "Taquillas"→`/taquillas`, nueva "Agencias"→`/agencias`; `ROLE_LABELS` `agencia:'Agencia'`, `taquilla:'Taquilla'`.
- [ ] 5.3 Crear `agencias.astro` + `agencias/detalle.astro` (CRUD locals).
- [ ] 5.4 Modificar `usuarios.astro`, `taquillas.astro`(+detalle), `dashboard.astro`, `cuadre.astro`, `reportes/*`, `limites.astro` (select agencia, nivel local vs máquina, stats locales+taquillas).
- [ ] 5.5 Verificar `npm run build` (panel sin suite e2e — manual).

## Phase 6 (F5): Endurecimiento

- [ ] 6.1 `TerminologiaTest` final verde (claves Agencia=local/Taquilla=máquina + mensajes).
- [ ] 6.2 `composer test` verde + `./vendor/bin/pint --test`.
- [ ] 6.3 [CLIENTE] Revisión post-backfill del cliente: renombrar/crear locals reales; registrar si el local necesitará configuración propia (D6 posterior).

## Rollback

- Migraciones aditivas (todo nullable): `php artisan migrate:rollback --step=4` restaura esquema; `taquillas.grupo_id` intacto.
- Backfill reversible: `agencia_id=null` / `master_id=null`; re-ejecutable, no destructivo.
- Scope master: quitar `whereIn` (vuelve global). Scope agencia: revertir ramas en controllers/policies/routes.
- Panel: revertir commits; backend previo no rompe (columnas nullable).
