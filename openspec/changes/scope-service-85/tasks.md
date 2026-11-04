# Tasks: Extraer el scoping jerárquico a un `AlcanceService` + policies (issue #85)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~1.900–2.000 (additions+deletions) |
| Presupuesto por PR/candidate | 800 (override del orchestrator; objetivo ≤400) |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | 0a → 0b → 1a → 1b → 1c → 2a → 2b → 2c → 3a → 3b → 4a → 4b → 5 |
| Delivery strategy | auto-chain (local stacked-to-main; GitHub NO se toca hasta cerrar #85 + BE-04) |
| Chain strategy | stacked-to-main |

```
Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High
```

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 0a-1 | `AlcanceService` listados base + paridad | PR 0a-1 | `php artisan test --filter=AlcanceServiceScopeTest` | `composer test` (usuario, cierre de ciclo) | `git revert` sin tocar controllers (aditivo) |
| 0a-2 | `AlcanceService` usuarios/entidades + paridad | PR 0a-2 | `php artisan test --filter=AlcanceServiceScopeTest` | `composer test` (usuario) | `git revert` sin tocar controllers |
| 0c | Visibilidad/gestión por entidad (DIFERIDO) | PR 0c (cuando se consuma) | `php artisan test --filter=AlcanceEntidadesTest` | `composer test` (usuario) | `git revert` sin tocar controllers |
| 0b | 6 policies + registro + test matriz | PR 0b | `php artisan test --filter=AlcancePoliciesTest` | `composer test` (usuario) | `git revert` sin tocar controllers |
| 1a | Estadistica + Reporte | PR 1a | `php artisan test --filter='Estadistica|Reporte'` | `composer test` (usuario) | revertir solo 2 controllers |
| 1b | Apuesta + `ApuestaPolicy` | PR 1b | `php artisan test --filter='Apuesta'` | `composer test` (usuario) | revertir ApuestaController/ApuestaPolicy |
| 1c | Ticket | PR 1c | `php artisan test --filter='Ticket'` | `composer test` (usuario) | revertir TicketController |
| 2a | Banca + Agencia | PR 2a | `php artisan test --filter='Banca|Agencia'` | `composer test` (usuario) | revertir 2 controllers |
| 2b | Grupo | PR 2b | `php artisan test --filter='Grupo'` | `composer test` (usuario) | revertir GrupoController |
| 2c | Taquilla | PR 2c | `php artisan test --filter='Taquilla'` | `composer test` (usuario) | revertir TaquillaController |
| 3a | User | PR 3a | `php artisan test --filter='UsuarioRolApi|GestionUsuarios|AlcanceJerarquicoUsuarios'` | `composer test` (usuario) | revertir UserController |
| 3b | Comision | PR 3b | `php artisan test --filter='Comision'` | `composer test` (usuario) | revertir ComisionController |
| 4a | Cierre | PR 4a | `php artisan test --filter='Cierre|Cuadre'` | `composer test` (usuario) | revertir CierreController |
| 4b | Juego (límites + visibilidad) | PR 4b | `php artisan test --filter='Limites|Juego'` | `composer test` (usuario) | revertir JuegoController; guards 422 intactos |
| 5 | Docs + arquitectura + conteos | PR 5 | `php artisan test --filter=ArquitecturaAlcanceTest` | `composer test` + `pint --test` (cierre) | revertir docs/test sin tocar lógica |

## Regla operativa de apply

- **Un WU por invocación** de `sdd-apply`. Nunca encadenar dos WUs en un solo apply.
- **Tests primero**: escribir/actualizar el test del WU antes de (o junto a) el código, respetando TDD (`tdd: true`).
- **No ejecutar tests**: el agente NO corre `composer test` ni `pint --test` por WU; solo devuelve los comandos enfocados. El usuario ejecuta y valida.
- **Devolver comandos**: cada WU termina reportando el comando enfocado a ejecutar.
- **Detenerse**: al terminar el WU, el apply se detiene y espera la validación del usuario antes del siguiente.
- **Commit por WU**: un commit convencional en español por work unit (`refactor(scope): ...`).
- **Artefactos OpenSpec**: `tasks.md` se marca con `[x]` y se versiona con `git add -f` (los cambios/ quedan fuera del tree por defecto).

## Fases y Work Units

Rama base raíz: `feature/alcance-scope-service-85`. Cadena local: cada WU hereda la rama del WU anterior.

### Fase 0 — Fundación (aditiva, sin consumidores)

#### 0a-1 — `AlcanceService` (listados base)

- **Objetivo**: crear la capa de alcance para listados (`apuestas`, `tickets`, `cierres`) delegando en los helpers de `User`. Sin consumidores todavía.
- **Rama**: `feat/85-0a-alcance-service` · **Base**: `feature/alcance-scope-service-85`
- **Archivos**:
  - Crear `backend/app/Support/Scope/AlcanceService.php` (subconjunto base)
  - Crear `backend/tests/Feature/AlcanceServiceScopeTest.php` (paridad base)
- **Tareas**:
  - [x] `AlcanceService` (`final class`) con `applyToApuestas`, `applyToTickets`, `applyToCierres`, `bancasDeMaster`, `denegar`.
  - [x] Delegar en `masterBancaIds`, `masterBancaScope`, `masterBancaChainScope`, `masterBancaGroupScope`, `masterCanAccessBanca`.
  - [x] Constantes de precedencia `APUESTAS`, `TICKETS`, `ENTIDADES`, `CIERRE_ITEM` + mensajes de vínculo faltante.
  - [x] `denegar()` = `abort(403, $msg)`.
  - [x] `AlcanceServiceScopeTest`: paridad de esos métodos con dos árboles.
- **Tests**: `AlcanceServiceScopeTest` (nuevo). Baseline intacto.
- **Comandos**: `cd backend && php artisan test --filter=AlcanceServiceScopeTest`
- **Done**: servicio compila; paridad base verde.
- **Líneas estimadas**: ~800 (service 313 + test 486)
- **Rollback**: `git revert` (aditivo).

#### 0a-2 — `AlcanceService` (usuarios + entidades)

- **Objetivo**: completar la capa de listados con usuarios y entidades (`banca|grupo|agencia|taquilla|limite`).
- **Rama**: `feat/85-0a2-usuarios-entidades` · **Base**: `feat/85-0a-alcance-service`
- **Archivos**: modificar `AlcanceService.php` y `AlcanceServiceScopeTest.php`.
- **Tareas**:
  - [x] `applyToUsuarios` (incluye `created_by` como metadata de fila).
  - [x] `applyToEntidades` + `applyToBancas`/`Grupos`/`Agencias`/`Taquillas`/`Limites`.
  - [x] Tests de paridad de usuarios/entidades con dos árboles.
- **Tests**: `AlcanceServiceScopeTest` (ampliado).
- **Comandos**: `cd backend && php artisan test --filter=AlcanceServiceScopeTest`
- **Done**: paridad completa de listados verde.
- **Líneas estimadas**: ~450 (diff)
- **Rollback**: `git revert` (aditivo).

#### 0c — Visibilidad/gestión por entidad (DIFERIDO)

- **Objetivo**: `alcanceDeEntidad`, `puedeGestionar`, `puedeOperarTaquilla` + helpers de visibilidad/gestión por entidad. `entidadesVisibles` se difiere a 4a/4b (solo la consumen Juego/Cierre).
- **Rama**: `feat/85-0c-visibilidad-entidades` · **Base**: la rama vigente cuando la primera oleada lo consuma (2c/3a/4a).
- **Motivo del diferimiento**: WU-0a completo superaba el presupuesto de 800 líneas; esta API no tiene consumidores hasta las oleadas CRUD/volumen. Se implementa cuando un controller la necesite, con tests de matriz.
- **Tareas**:
  - [x] Implementar `alcanceDeEntidad`, `puedeGestionar`, `puedeOperarTaquilla`.
  - [x] Helpers de visibilidad/gestión por entidad y constantes `POLICY`/`LIMITES`/`JUEGO_VIS`.
  - [x] `AlcanceEntidadesTest` con matriz rol×entidad.
  - [ ] Diferir `entidadesVisibles` (+ helpers `visibilidadBanca/Master/Grupo/Agencia`) a 4a/4b.
- **Líneas estimadas**: ~660–850 (partir si supera 800).
- **Rollback**: `git revert` (aditivo).

#### 0b — 6 policies + registro + matriz

- **Objetivo**: crear las 6 policies delegando en `AlcanceService` y registrarlas explícitamente.
- **Rama**: `feat/85-0b-policies` · **Base**: `feat/85-0a2-usuarios-entidades`
- **Archivos**:
  - Crear `backend/app/Policies/{Banca,Grupo,Agencia,Taquilla,Ticket,Cierre}Policy.php`
  - Modificar `backend/app/Providers/AppServiceProvider.php` (6 `Gate::policy(...)`)
  - Crear `backend/tests/Feature/AlcancePoliciesTest.php`
- **Tareas**:
  - [x] Crear cada policy delegando scope/acción en `AlcanceService` (una línea por método).
  - [x] Registrar las 6 en `AppServiceProvider::boot()` junto al registro existente de `UserPolicy`.
  - [x] Escribir `AlcancePoliciesTest`: matriz rol×entidad (6 roles × 6 entidades) con tabla de verdad esperada.
- **Tests**: `AlcancePoliciesTest` (nuevo). Baseline intacto.
- **Comandos**: `cd backend && php artisan test --filter=AlcancePoliciesTest`
- **Done**: policies registradas; matriz verde; baseline sin cambios.
- **Líneas estimadas**: ~330
- **Rollback**: `git revert` (aditivo, sin consumidores).

### Fase 1 — Controllers solo-listado

#### 1a — Estadistica + Reporte

- **Objetivo**: reemplazar la escalera `rol → columna` de listados por `applyToApuestas`/`applyToTickets`.
- **Rama**: `feat/85-1a-estadistica-reporte` · **Base**: `feat/85-0b-policies`
- **Archivos**: Modificar `backend/app/Http/Controllers/Api/EstadisticaController.php`, `backend/app/Http/Controllers/Api/ReporteController.php` (`buildApuestaQuery`, `buildTicketQuery`).
- **Tareas**:
  - [x] `EstadisticaController`: escalera → `AlcanceService::applyToApuestas`/`applyToTickets`.
  - [x] `ReporteController::{buildApuestaQuery,buildTicketQuery}`: escalera → `applyTo*`.
  - [x] Denegaciones manuales → `AlcanceService::denegar()` con mensaje idéntico.
- **Tests**: `EstadisticaTest`, `ReporteTest` (existentes, sin modificar).
- **Comandos**: `cd backend && php artisan test --filter='Estadistica|Reporte'`
- **Done**: SQL generado y status/mensajes idénticos; tests existentes verdes.
- **Líneas estimadas**: ~90
- **Rollback**: revertir solo estos 2 controllers.

#### 1b — Apuesta + `ApuestaPolicy`

- **Objetivo**: migrar `index`/`historial`/`resumen` y delegar el scope de `ApuestaPolicy` en el servicio.
- **Rama**: `feat/85-1b-apuesta` · **Base**: `feat/85-1a-estadistica-reporte`
- **Archivos**: Modificar `backend/app/Http/Controllers/Api/ApuestaController.php`, `backend/app/Policies/ApuestaPolicy.php`.
- **Tareas**:
  - [x] `ApuestaController::{index,historial,resumen}`: escalera → `applyToApuestas`.
  - [x] `ApuestaPolicy`: reemplazar scoping propio por delegación a `AlcanceService` (sin cambio de contrato).
- **Tests**: `ApuestaScopePolicyTest`, `ApuestaTest` (existentes, sin modificar).
- **Comandos**: `cd backend && php artisan test --filter='Apuesta'`
- **Done**: contrato de apuestas preservado; tests verdes.
- **Líneas estimadas**: ~80
- **Rollback**: revertir `ApuestaController` + `ApuestaPolicy`.

#### 1c — Ticket

- **Objetivo**: migrar `index` y `ganadores` (×2) a `applyToTickets`.
- **Rama**: `feat/85-1c-ticket` · **Base**: `feat/85-1b-apuesta`
- **Archivos**: Modificar `backend/app/Http/Controllers/Api/TicketController.php`.
- **Tareas**:
  - [x] `TicketController::{index,ganadores}`: escalera → `applyToTickets`.
  - [x] Denegaciones → `denegar()` con mensaje exacto. (En `show` se usa `alcanceDeEntidad` + `response()->json(['message' => 'No autorizado.'], 403)` para preservar body/status exactos; `autorizarScope` queda fail-closed.)
- **Tests**: `TicketMasterScopeTest`, `TicketGanadoresTest`, `TicketAnuladosVisiblesTest` (existentes, sin modificar).
- **Comandos**: `cd backend && php artisan test --filter='Ticket'`
- **Done**: scope de tickets idéntico; tests verdes.
- **Líneas estimadas**: ~60
- **Rollback**: revertir `TicketController`.

### Fase 2 — CRUD de entidades

#### 2a — Banca + Agencia

- **Objetivo**: migrar el scope de entidades a `applyToEntidades`/policy.
- **Rama**: `feat/85-2a-banca-agencia` · **Base**: `feat/85-1c-ticket`
- **Archivos**: Modificar `backend/app/Http/Controllers/Api/BancaController.php`, `backend/app/Http/Controllers/Api/AgenciaController.php`.
- **Tareas**:
  - [x] `BancaController`: escalera → `applyToEntidades`/`BancaPolicy`. (`index` delega en `applyToEntidades($query,$user,'banca',$mensaje)`; `show`/`update`/`toggle`/`destroy` delegan la decisión en `alcanceDeEntidad`/`puedeGestionar`.)
  - [x] `AgenciaController`: escalera → `applyToEntidades`/`AgenciaPolicy`. (`index` → `applyToEntidades('agencia')`; `authorizeAgenciaAccess` → `alcanceDeEntidad('agencia')`; `authorizeGestion` gana la variante con entidad → `puedeGestionar('agencia')`, conservando el guard de agencia y la rama de ficha propia de #84.)
  - [x] Denegaciones manuales → policy (`denegar`). (El cuerpo/status/mensaje se preserva con el mecanismo existente en cada endpoint: `applyToEntidades` deniega con `denegar()` y los chequeos por entidad usan `abort`/`response()->json` con el texto exacto; `authorizeGrupoAccess` queda intacto.)
- **Tests**: `SuperBancaScopeTest`, `AgenciaScopeTest`, `AgenciaMasterScopeTest`, `BancaMasterIdTest` (existentes, sin modificar).
- **Comandos**: `cd backend && php artisan test --filter='Banca|Agencia'`
- **Done**: scope de banca/agencia idéntico; tests verdes.
- **Líneas estimadas**: ~130
- **Rollback**: revertir ambos controllers.

#### 2b — Grupo

- **Objetivo**: migrar `GrupoController` a `applyToEntidades`/`GrupoPolicy`.
- **Rama**: `feat/85-2b-grupo` · **Base**: `feat/85-2a-banca-agencia`
- **Archivos**: Modificar `backend/app/Http/Controllers/Api/GrupoController.php`.
- **Tareas**:
  - [x] Escalera → `applyToEntidades`/`GrupoPolicy`; denegación unificada. (`index` → `applyToEntidades($query,$user,'grupo','No tienes permiso para ver grupos.')`; `show` → `alcanceDeEntidad('grupo')`; `update`/`toggle`/`destroy` → `puedeGestionar('grupo')`; `authorizeBancaAccess` y la lógica de creación/validación/cascadas intactas.)
- **Tests**: `AlcanceJerarquicoUsuariosTest`, `GestionEntidadesApiTest` (existentes, sin modificar).
- **Comandos**: `cd backend && php artisan test --filter='Grupo'`
- **Done**: scope de grupo idéntico; tests verdes.
- **Líneas estimadas**: ~80
- **Rollback**: revertir `GrupoController`.

#### 2c — Taquilla

- **Objetivo**: migrar `TaquillaController`, incluyendo `puedeOperarTaquilla` y la regla `agencia → grupo`.
- **Rama**: `feat/85-2c-taquilla` · **Base**: `feat/85-2b-grupo`
- **Archivos**: Modificar `backend/app/Http/Controllers/Api/TaquillaController.php`.
- **Tareas**:
  - [x] Escalera → `applyToEntidades`/`TaquillaPolicy`. (`index` → `applyToEntidades($query,$user,'taquilla','No tienes permiso para ver taquillas.')`; `show`/`update`/`toggle`/`destroy` → `alcanceDeEntidad('taquilla')` en `authorizeTaquillaAccess`.)
  - [x] `puedeOperarTaquilla` y la variante `agencia → grupo` delegadas en `AlcanceService` (sin reinterpretar el orden). (El controller no tenía call site de `puedeOperarTaquilla`; su variante `agencia→grupo` sigue expuesta por `TaquillaPolicy::operar`. No se usa `puedeGestionar('taquilla')` en escritura porque añadiría esa variante a `update`/`toggle`/`destroy` y ampliaría la agencia a taquillas de otras agencias del mismo grupo (#84/F1). `authorizeGrupoAccess`/`authorizeAgenciaAccess` quedan intactas.)
  - [x] Denegaciones → `denegar()` con mensaje exacto. (`authorizeTaquillaAccess` → `denegar('No tienes acceso a esta taquilla.')`, mismo 403/body; el fallback fuera de contrato queda inalcanzable por el middleware de ruta.)
- **Tests**: `TaquillaStoreTest`, `TaquillaAgenciaIdTest`, `TaquillaGrupoAgenciaConsistenciaTest`, `TaquillaLocalObligatorioTest` (existentes, sin modificar).
- **Comandos**: `cd backend && php artisan test --filter='Taquilla'`
- **Done**: scope y operación de taquilla idénticos; tests verdes.
- **Líneas estimadas**: ~160
- **Rollback**: revertir `TaquillaController`.

### Fase 3 — Varios

#### 3a — User

- **Objetivo**: migrar `UserController` (visibilidad de usuarios) a `applyToUsuarios`/`UserPolicy`.
- **Rama**: `feat/85-3a-user` · **Base**: `feat/85-2c-taquilla`
- **Archivos**: Modificar `backend/app/Http/Controllers/Api/UserController.php`.
- **Tareas**:
  - [x] Escalera de usuarios → `applyToUsuarios`; eliminar call sites de `masterBancaIds()`. (`index` delega en `applyToUsuarios($query, $user, 'No tienes permisos para ver usuarios.')`; el único call site `masterBancaIds()` del controller queda eliminado. `store`/`show`/`update`/`destroy`/`authorizeEntityBinding`/`deriveEntityBindings` intactos.)
  - [x] Denegación unificada con mensaje exacto. (Los 403 de vínculo faltante usan las constantes `MENSAJE_SIN_BANCA`/`_SIN_GRUPO`/`_SIN_AGENCIA` con texto idéntico al previo; el 403 de rol no permitido conserva `'No tienes permisos para ver usuarios.'` vía `denegar()` (`abort(403, ...)`). La rama es inalcanzable por el middleware de ruta (`role:super_master|master|banca|grupo|taquilla|agencia`), así que status/mensaje se preservan.)
- **Tests**: `AlcanceJerarquicoUsuariosTest`, `GestionUsuariosTest`, `RoleAuthorizationTest`, `UsuarioRolApiTest` (existentes, sin modificar).
- **Comandos**: `cd backend && php artisan test --filter='UsuarioRolApi|GestionUsuarios|AlcanceJerarquicoUsuarios'`
- **Done**: visibilidad de usuarios idéntica; tests verdes.
- **Líneas estimadas**: ~90
- **Rollback**: revertir `UserController`.

#### 3b — Comision

- **Objetivo**: migrar `ComisionController`; eliminar `masterBancaIds()` de los call sites.
- **Rama**: `feat/85-3b-comision` · **Base**: `feat/85-3a-user`
- **Archivos**: Modificar `backend/app/Http/Controllers/Api/ComisionController.php`.
- **Tareas**:
  - [ ] Escalera → `applyTo*`; reemplazar `masterBancaIds()` por `AlcanceService::bancasDeMaster`.
  - [ ] Denegación unificada.
- **Tests**: `ComisionCierreTest`, `ComisionesApiTest`, `ComisionReporteTest` (existentes, sin modificar).
- **Comandos**: `cd backend && php artisan test --filter='Comision'`
- **Done**: scope de comisiones idéntico; tests verdes.
- **Líneas estimadas**: ~20
- **Rollback**: revertir `ComisionController`.

### Fase 4 — Volumen (último)

#### 4a — Cierre

- **Objetivo**: migrar `CierreController`, incluyendo `entidadesVisibles` y el ítem de cierre `taquilla-first`.
- **Rama**: `feat/85-4a-cierre` · **Base**: `feat/85-3b-comision`
- **Archivos**: Modificar `backend/app/Http/Controllers/Api/CierreController.php`.
- **Tareas**:
  - [ ] Escalera → `applyToCierres`/`CierrePolicy`.
  - [ ] `entidadesVisibles` y el orden `CIERRE_ITEM` (taquilla-first) delegados sin reinterpretar.
  - [ ] Denegaciones (19 `abort(403`) → `denegar()` con mensaje exacto.
- **Tests**: `CierreCajaTest`, `CuadreCajaReportTest`, `ComisionCierreTest` (existentes, sin modificar).
- **Comandos**: `cd backend && php artisan test --filter='Cierre|Cuadre'`
- **Done**: cierre y visibilidad de entidades idénticos; tests verdes.
- **Líneas estimadas**: ~160
- **Rollback**: revertir `CierreController`.

#### 4b — Juego (solo límites + visibilidad)

- **Objetivo**: migrar únicamente límites y visibilidad de `JuegoController`; los guards 422 quedan intactos.
- **Rama**: `feat/85-4b-juego` · **Base**: `feat/85-4a-cierre`
- **Archivos**: Modificar `backend/app/Http/Controllers/Api/JuegoController.php`.
- **Tareas**:
  - [ ] Escalera de límites (`LIMITES`) y visibilidad (`JUEGO_VIS`) → `applyToEntidades`/`alcanceDeEntidad`.
  - [ ] Reemplazar los 7 call sites de `masterBancaIds()` por `bancasDeMaster`.
  - [ ] Denegaciones → `denegar()` con mensaje exacto.
  - [ ] NO tocar los guards 422 de validación de payload.
  - [ ] Si el diff estimado supera 800 líneas, partir el controller por grupos de métodos (catálogo / límites / resultados) en WUs adicionales.
- **Tests**: `LimitesScopedApiTest`, `LimitesEscrituraScopeTest`, `JuegoLimiteServiceTest`, `JuegoUpdateTest`, `JuegoToggleTest`, `JuegosJsonTest` (existentes, sin modificar).
- **Comandos**: `cd backend && php artisan test --filter='Limites|Juego'`
- **Done**: límites/visibilidad idénticos; guards 422 intactos; tests verdes.
- **Líneas estimadas**: ~260
- **Rollback**: revertir `JuegoController`; guards 422 no tocados.

### Fase 5 — Docs, arquitectura y verificación de conteos

#### 5 — Docs + `ArquitecturaAlcanceTest` + conteos

- **Objetivo**: documentar el modelo de alcance y fijar las invariantes de no-regresión por scan estático.
- **Rama**: `feat/85-5-docs-arquitectura` · **Base**: `feat/85-4b-juego`
- **Archivos**:
  - Crear `backend/docs/dev/alcance-jerarquico.md`
  - Modificar `backend/docs/dev/arquitectura-backend.md`
  - Crear `backend/tests/Feature/ArquitecturaAlcanceTest.php`
- **Tareas**:
  - [ ] Documentar el modelo de alcance y el orden de precedencia (`taquilla → agencia → grupo → banca`, `taquilla → grupo → banca`).
  - [ ] Escribir `ArquitecturaAlcanceTest` (scan estático conservador): sin `->masterBancaIds()` fuera de `AlcanceService`/`User`; sin escaleras duplicadas en controllers migrados.
  - [ ] Verificar conteos: `grep -rn -- "->masterBancaIds()" app/` = 1; `hasRole`/`hasAnyRole` para filtrar filas en `Api/` = 0.
- **Tests**: `ArquitecturaAlcanceTest` (nuevo).
- **Comandos** (cierre del ciclo): `cd backend && php artisan test --filter=ArquitecturaAlcanceTest`, luego `composer test` y `vendor/bin/pint --test` (UNA vez, usuario).
- **Done**: docs listas; scan verde; `grep` devuelve 1; suite completa verde.
- **Líneas estimadas**: ~200
- **Rollback**: revertir docs/test; sin impacto en lógica.

## Criterios de aceptación globales

- [ ] Ningún controller de `Api/` compara roles para decidir qué filas ve (solo autorización de acción puntual).
- [ ] `AlcanceService` es la única implementación de `taquilla → grupo → banca` y `taquilla → agencia → grupo → banca`.
- [ ] `grep -rn -- "->masterBancaIds()" app/` devuelve **1**.
- [ ] Baseline intacto sin modificar: `SuperBancaScopeTest`, `AgenciaScopeTest`, `MastersPanelTest`, `RoleAuthorizationTest`, `LimitesScopedApiTest`, `TicketMasterScopeTest`, `ApuestaScopePolicyTest`, `PagoEgresoScopeTest`, `AlcanceJerarquicoUsuariosTest`, `CreatedByTest`.
- [ ] Nuevos tests: `AlcanceServiceScopeTest`, `AlcancePoliciesTest`, `ArquitecturaAlcanceTest`.
- [ ] `composer test` en verde y `pint --test` limpio (ejecutados por el usuario).
- [ ] Docs del modelo de alcance en `backend/docs/dev/`.

## Riesgos

- **Superficie grande** (14 controllers, ~6.286 líneas): mitigado por migración incremental en PRs ≤800 líneas y baseline intacto.
- **Tests que asumen mensajes/status exactos**: preservar el mensaje; unificar mecanismo, no texto.
- **`AuthorizationException` normalizada** en `bootstrap/app.php`: usar `denegar()` con `abort(403,$msg)`, no policies `bool` para denegar filas.
- **Multi-rol con órdenes distintos** (`taquilla→agencia→grupo→banca` vs `taquilla→grupo→banca`): constantes por contrato; no `primaryRole()` en filas.
- **`JuegoController` (1.291 líneas)**: solo límites/visibilidad; guards 422 intactos; partir por grupos de métodos si supera 800.
- **Reglas ocultas** (agencia→grupo, cierre ítem taquilla-first): `puedeOperarTaquilla`/`entidadesVisibles` conservan la variante.

## Notas

- **`AuthController` queda FUERA**: no filtra filas.
- **`PagoController` NO cambia**: hereda scope vía `ApuestaPolicy`/ownership.
- **Sin migraciones**: rollback = `git revert` (o imagen `IMAGE_TAG`), sin pérdida de datos.
- **`User.php`**: `masterBancaIds()` permanece como única definición de bajo nivel; no se reimplementa.
- **GitHub NO se toca** hasta cerrar #85 + BE-04; todo queda en ramas locales encadenadas.
